<?php

namespace App\Domain\Pembayaran;

use App\Domain\Billing\PenghitungDenda;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Models\User;
use App\Support\WaktuSistem;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Rekap pendapatan per metode bayar (T21).
 *
 * Satu aturan menentukan seluruh kelas ini benar atau tidak: **pendapatan
 * adalah uang yang benar-benar diterima, yaitu `SUM(pembayaran.jumlah)`.
 * Denda yang masih tertera di tagihan BUKAN pendapatan** - ia piutang.
 *
 * Kenapa aturan itu perlu ditulis sekeras ini. Tampilan yang wajar untuk pemilik
 * kos adalah menjumlahkan kolom `total` tiap tagihan dan menyebutnya
 * "pendapatan bulan ini". Angka itu terlalu besar: ia mencampur uang yang sudah
 * masuk dengan denda yang belum dibayar siapa pun, dan pemilik yang mengira
 * sudah menerima Rp 4 juta padahal rekeningnya bertambah Rp 3,2 juta akan
 * menyalahkan aplikasinya. Denda baru menjadi pendapatan pada hari ia dibayar.
 *
 * Tiga keputusan bentuk:
 *
 * 1. **Periode memakai `tanggal_pembayaran`, bukan `tagihan.periode`.** Rekap
 *    ini menjawab "uang masuk bulan ini" (basis kas), bukan "tagihan bulan ini"
 *    (basis akrual). Penghuni yang membayar tunggakan Oktober pada November
 *    menambah kas November - itu yang terlihat di rekening, dan itulah yang
 *    harus cocok dengan rekap.
 *
 * 2. **Pembayaran tanpa metode dilaporkan sebagai kelompok tersendiri, tidak
 *    dilebur ke tunai.** Baris lama (sebelum T20) tidak punya metode, dan
 *    menganggapnya tunai berarti mengarang fakta tentang uang. Kelompok
 *    "tanpa metode" membuat lubangnya terlihat, bukan tertutup rapi.
 *
 * 3. **Tunai dipecah per pemegang uang.** Untuk kos yang pemiliknya tidak
 *    tinggal di lokasi, pertanyaan yang sebenarnya ditanyakan bukan "berapa
 *    pendapatan", melainkan "setoran mana yang masih di tangan pengelola".
 *    Itu hanya bisa dijawab kalau setiap baris tunai menyebut pemegangnya.
 */
class RekapPendapatan
{
    public function __construct(
        private readonly WaktuSistem $waktu,
        private readonly PenghitungDenda $penghitungDenda,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function susun(CarbonInterface $dari, CarbonInterface $sampai): array
    {
        $awal = $dari->toDateString();
        $akhir = $sampai->toDateString();

        // Satu query agregat untuk seluruh rekap, bukan satu query per metode.
        // Empat metode berarti empat query kalau dikerjakan terpisah, dan
        // jumlahnya bertambah setiap kali ada metode baru.
        //
        // Sejak REV-FIN-01 tabel ini juga berisi baris pembalik bernilai
        // negatif. `SUM(jumlah)` karena itu sudah berarti **uang bersih**, dan
        // itulah yang dilaporkan sebagai pendapatan. Yang dipisah hanya
        // hitungan transaksinya: pembalik bukan transaksi penerimaan, jadi
        // `transaksi` menghitung baris masuk saja, sementara `pembalik_*`
        // melaporkan berapa banyak uang yang ditarik pada rentang ini. Tanpa
        // pemisahan itu, rekap bulan yang berisi satu koreksi akan melaporkan
        // "13 transaksi" untuk 12 pembayaran, dan selisih satu itu tidak bisa
        // dijelaskan siapa pun yang membacanya.
        $baris = Pembayaran::query()
            ->whereBetween('tanggal_pembayaran', [$awal, $akhir])
            ->selectRaw(
                'metode, sumber, diterima_oleh,
                 COUNT(*) FILTER (WHERE pembalik_id IS NULL) AS transaksi,
                 COUNT(*) FILTER (WHERE pembalik_id IS NOT NULL) AS pembalik_transaksi,
                 SUM(jumlah) AS jumlah,
                 COALESCE(SUM(jumlah) FILTER (WHERE pembalik_id IS NOT NULL), 0) AS pembalik_jumlah'
            )
            ->groupBy('metode', 'sumber', 'diterima_oleh')
            ->get();

        return [
            'dari' => $awal,
            'sampai' => $akhir,
            'total' => (int) $baris->sum('jumlah'),
            'jumlah_transaksi' => (int) $baris->sum('transaksi'),
            // Uang yang ditarik kembali pada rentang ini. Dilaporkan terpisah
            // supaya pemilik yang membandingkan rekap dengan mutasi rekening
            // bisa melihat sendiri bahwa selisihnya berasal dari koreksi, bukan
            // dari uang yang hilang. Nilainya negatif - sama seperti barisnya.
            'pembalik' => [
                'jumlah' => (int) $baris->sum('pembalik_jumlah'),
                'transaksi' => (int) $baris->sum('pembalik_transaksi'),
            ],
            'per_metode' => $this->perMetode($baris),
            'per_sumber' => $this->perSumber($baris),
            'tanpa_metode' => $this->tanpaMetode($baris),
            'tunai_di_tangan' => $this->tunaiDiTangan($baris),
            // Piutang, BUKAN pendapatan. Diumumkan terpisah supaya layar bisa
            // menjelaskan selisihnya sendiri: "pendapatan Rp 3.200.000, denda
            // yang belum dibayar Rp 240.000". Tanpa pembanding ini, pemilik
            // membandingkan rekap dengan jumlah tagihan dan mengira ada uang
            // yang hilang. Ia sengaja TIDAK ikut ke `total`.
            'piutang' => $this->piutang(),
            // Diumumkan, bukan disimpulkan layar: `APP_DEMO_MODE` yang mati
            // membuat header simulasi diabaikan, dan rekap yang mengklaim
            // "tanggal simulasi" padahal angkanya angka hari ini adalah
            // kesalahan yang sudah pernah terjadi di layar Tunggakan.
            'simulasi' => $this->waktu->sedangSimulasi(),
            'demo_mode' => (bool) config('app.demo_mode'),
        ];
    }

    /**
     * @param  Collection<int, Pembayaran>  $baris
     * @return list<array<string, mixed>>
     */
    private function perMetode(Collection $baris): array
    {
        $label = MetodePembayaran::label();

        // Wadah disiapkan lengkap: metode yang belum menerima uang bulan ini
        // tetap muncul dengan angka nol. Kelompok yang hilang membuat pemilik
        // bertanya apakah QRIS memang tidak dipakai atau layarnya rusak.
        $hasil = [];
        foreach (MetodePembayaran::SEMUA as $metode) {
            $milik = $baris->where('metode', $metode);

            $hasil[] = [
                'metode' => $metode,
                'label' => $label[$metode],
                'jumlah' => (int) $milik->sum('jumlah'),
                'transaksi' => (int) $milik->sum('transaksi'),
            ];
        }

        return $hasil;
    }

    /**
     * @param  Collection<int, Pembayaran>  $baris
     * @return list<array<string, mixed>>
     */
    private function perSumber(Collection $baris): array
    {
        $label = MetodePembayaran::labelSumber();

        $hasil = [];
        foreach (MetodePembayaran::SEMUA_SUMBER as $sumber) {
            $milik = $baris->where('sumber', $sumber);

            $hasil[] = [
                'sumber' => $sumber,
                'label' => $label[$sumber],
                'jumlah' => (int) $milik->sum('jumlah'),
                'transaksi' => (int) $milik->sum('transaksi'),
            ];
        }

        return $hasil;
    }

    /**
     * @param  Collection<int, Pembayaran>  $baris
     * @return array<string, int>
     */
    private function tanpaMetode(Collection $baris): array
    {
        $milik = $baris->whereNull('metode');

        return [
            'jumlah' => (int) $milik->sum('jumlah'),
            'transaksi' => (int) $milik->sum('transaksi'),
        ];
    }

    /**
     * Uang tunai yang tercatat diterima tiap orang, pada rentang ini.
     *
     * Tanpa nama pemegang, angkanya dikelompokkan sebagai "tidak tercatat" -
     * bukan dibuang, karena membuangnya membuat total per orang tidak lagi
     * berjumlah sama dengan total tunai, dan selisih yang tidak dijelaskan
     * adalah hal pertama yang ditanyakan saat uangnya tidak cocok.
     *
     * @param  Collection<int, Pembayaran>  $baris
     * @return list<array<string, mixed>>
     */
    private function tunaiDiTangan(Collection $baris): array
    {
        $milik = $baris->where('metode', MetodePembayaran::TUNAI);

        if ($milik->isEmpty()) {
            return [];
        }

        $nama = User::query()
            ->whereIn('id', $milik->pluck('diterima_oleh')->filter()->unique()->values())
            ->pluck('name', 'id');

        return $milik
            ->groupBy(fn (Pembayaran $p) => $p->diterima_oleh ?? 0)
            ->map(fn (Collection $kelompok, int $id) => [
                'diterima_oleh' => $id === 0 ? null : $id,
                'nama' => $id === 0 ? 'Tidak tercatat' : ($nama[$id] ?? 'Akun terhapus'),
                // Diurutkan menurun supaya pemegang dengan setoran terbesar -
                // yaitu yang paling perlu disetor - muncul lebih dulu.
                'jumlah' => (int) $kelompok->sum('jumlah'),
                'transaksi' => (int) $kelompok->sum('transaksi'),
            ])
            ->sortByDesc('jumlah')
            ->values()
            ->all();
    }

    /**
     * Uang tunai yang masih di tangan **satu orang**, pada rentang ini
     * (REV-SEC-06).
     *
     * Endpoint `/rekap` sejak R2 hanya untuk pemilik, karena rekap adalah
     * gambaran utuh keuangan kos - dan pengelola yang bisa membacanya bisa
     * mengetahui pendapatan pemiliknya tanpa pernah diminta. Tetapi menutup
     * rekap seluruhnya akan membuat pengelola kehilangan satu pertanyaan yang
     * memang miliknya: "berapa uang tunai yang masih saya pegang?"
     *
     * Karena itu yang dibuka bukan rekap yang dipersempit, melainkan pertanyaan
     * yang berbeda: angka milik pemanggil sendiri, tanpa rincian pendapatan kos.
     * Yang dikembalikan hanya total dan jumlah transaksi; tidak ada nama
     * penghuni, tidak ada metode lain, dan tidak ada piutang.
     *
     * @return array<string, mixed>
     */
    public function tunaiSaya(int $userId, CarbonInterface $dari, CarbonInterface $sampai): array
    {
        $awal = $dari->toDateString();
        $akhir = $sampai->toDateString();

        $baris = Pembayaran::query()
            ->where('metode', MetodePembayaran::TUNAI)
            ->where('diterima_oleh', $userId)
            ->whereBetween('tanggal_pembayaran', [$awal, $akhir])
            ->selectRaw(
                'COUNT(*) FILTER (WHERE pembalik_id IS NULL) AS transaksi,
                 COUNT(*) FILTER (WHERE pembalik_id IS NOT NULL) AS pembalik_transaksi,
                 COALESCE(SUM(jumlah), 0) AS jumlah,
                 COALESCE(SUM(jumlah) FILTER (WHERE pembalik_id IS NOT NULL), 0) AS pembalik_jumlah'
            )
            ->first();

        return [
            'dari' => $awal,
            'sampai' => $akhir,
            'jumlah' => (int) ($baris->jumlah ?? 0),
            'transaksi' => (int) ($baris->transaksi ?? 0),
            'pembalik' => [
                'jumlah' => (int) ($baris->pembalik_jumlah ?? 0),
                'transaksi' => (int) ($baris->pembalik_transaksi ?? 0),
            ],
            // Disebutkan apa adanya: angka ini adalah uang yang **tercatat**
            // diterima orang ini, bukan hasil pencocokan dengan setoran fisik.
            // Sistem belum punya pencatatan setoran, jadi klaim "sudah disetor"
            // tidak bisa dibuat di sini.
            'catatan' => 'Uang tunai yang tercatat Anda terima pada rentang ini. Sistem belum mencatat setoran, '
                .'jadi angka ini belum dikurangi setoran apa pun.',
        ];
    }

    /**
     * Denda yang belum tertagih - piutang, bukan pendapatan.
     *
     * Dihitung lewat {@see PenghitungDenda} yang sama dengan endpoint denda dan
     * layar Tunggakan, bukan dengan rumus salinan. Menyalin rumus berarti dua
     * layar menampilkan denda berbeda untuk tagihan yang sama begitu salah
     * satunya diubah.
     *
     * Angkanya hanya mencakup tagihan yang belum lunas dan belum dibatalkan:
     * tagihan lunas sudah memasukkan dendanya ke `total_final`, sehingga ia
     * sudah tercatat sebagai uang diterima lewat baris pembayaran, dan
     * menghitungnya lagi di sini akan menghitung uang yang sama dua kali.
     *
     * @return array<string, int>
     */
    private function piutang(): array
    {
        $tagihan = Tagihan::query()
            ->whereNotIn('status', [Tagihan::STATUS_LUNAS, Tagihan::STATUS_DIBATALKAN])
            ->get(['id', 'pokok', 'jatuh_tempo', 'status', 'kebijakan_terpakai',
                'tarif_denda_persen', 'plafon_persen', 'masa_tenggang']);

        $denda = 0;
        foreach ($tagihan as $satu) {
            $denda += $this->penghitungDenda->hitung($satu)->denda;
        }

        return [
            'tagihan_terbuka' => $tagihan->count(),
            'denda_belum_dibayar' => $denda,
        ];
    }
}
