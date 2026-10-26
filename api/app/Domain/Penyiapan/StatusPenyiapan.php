<?php

namespace App\Domain\Penyiapan;

use App\Domain\Kebijakan\PenyelesaiKebijakan;
use App\Models\Kamar;
use App\Models\KebijakanKos;
use App\Models\Penghuni;
use App\Models\Rekening;
use App\Support\WaktuSistem;
use Illuminate\Support\Collection;

/**
 * Urutan penyiapan yang harus dilalui pemilik sebelum kos ini bisa dipakai.
 *
 * **Masalah yang dijawab kelas ini.** Sebuah instalasi baru benar-benar kosong:
 * tidak ada kebijakan, tidak ada kamar, tidak ada tarif, tidak ada rekening.
 * Pemilik yang baru pertama masuk melihat menu yang sama dengan kos yang sudah
 * berjalan setahun, menekan hal pertama yang terlihat menarik, lalu menabrak
 * gerbang yang tidak ia ketahui - "Kebijakan kos belum lengkap: satuan sewa
 * belum ditetapkan". Pesan itu benar, tetapi datang terlambat: ia baru muncul
 * setelah pemilik mengetik satu kontrak penuh.
 *
 * Kelas ini menjawab pertanyaan yang seharusnya ditanyakan sejak awal: **apa
 * yang belum ada, dan mana yang harus lebih dulu.**
 *
 * **Kenapa dihitung dari keadaan sebenarnya, bukan disimpan sebagai penanda.**
 * Alternatifnya adalah kolom `kos.sudah_disiapkan` yang disetel sekali. Itu
 * lebih murah, dan salah dengan tiga cara:
 *
 *   1. Ia bisa benar padahal keadaannya tidak: penanda disetel, lalu pemilik
 *      menghapus semua kamarnya.
 *   2. Ia bisa salah padahal keadaannya benar: instalasi yang datanya
 *      dipulihkan dari backup tidak akan pernah melewati langkah penyiapan
 *      lagi, sehingga penandanya tetap `false` selamanya.
 *   3. Ia menambah satu keadaan yang harus dijaga konsisten dengan empat tabel
 *      lain, dan tidak ada satu pun pemeriksaan yang bisa menangkap
 *      ketidakcocokannya.
 *
 * Menghitungnya dari tabel berarti jawabannya selalu benar menurut data, dan
 * tidak ada yang perlu diingat untuk memperbaruinya.
 *
 * **Kenapa urutannya begini.** Kebijakan lebih dulu karena ia satu-satunya
 * langkah yang **menahan** langkah lain: tanpa satuan sewa, jatuh tempo, dan
 * denda, tagihan tidak bisa terbit sama sekali. Tarif sesudah kamar karena
 * tarif menempel pada kamar. Rekening paling akhir karena ia tidak menahan apa
 * pun - penghuni tetap bisa membayar tunai - tetapi tanpa ia portal penghuni
 * tidak bisa memberi tahu ke mana uang dikirim.
 */
class StatusPenyiapan
{
    /**
     * Kunci langkah yang wajib selesai sebelum tagihan pertama bisa terbit.
     *
     * Empat, bukan lima: "penghuni pertama" sengaja tidak wajib. Kos yang baru
     * dibuka boleh saja belum punya penghuni selama berminggu-minggu, dan
     * menandainya sebagai langkah yang belum selesai akan membuat penanda
     * penyiapan menyala selamanya pada kos yang sebenarnya sudah siap.
     *
     * @var list<string>
     */
    public const WAJIB = [
        'kebijakan',
        'kamar',
        'tarif',
        'rekening',
    ];

    public function __construct(
        private readonly PenyelesaiKebijakan $penyelesai,
        private readonly WaktuSistem $waktu,
    ) {}

    /**
     * @return array{
     *     selesai: bool,
     *     jumlah_selesai: int,
     *     jumlah_wajib: int,
     *     langkah: list<array<string, mixed>>
     * }
     */
    public function susun(): array
    {
        $hariIni = $this->waktu->hariIni();

        // --- Kebijakan ------------------------------------------------------
        //
        // Diperiksa terhadap AWAL BULAN INI, bukan terhadap hari ini, karena
        // begitulah `PenyelesaiKebijakan` menyelesaikannya saat tagihan terbit.
        // Memakai "hari ini" akan membuat layar menyatakan "belum lengkap"
        // untuk kebijakan yang bertanggal 1 bulan ini - padahal tagihan bulan
        // ini memang memakainya.
        $kurang = $this->penyelesai->tipeYangKurang($hariIni);

        $kebijakanSelesai = $kurang === [];

        // --- Kamar dan tarif ------------------------------------------------
        $kamar = Kamar::query()
            ->with('tarifTerakhir')
            ->orderBy('nomor')
            ->get();

        $kamarTanpaTarif = $kamar->filter(fn (Kamar $k) => $k->tarifTerakhir === null);

        // --- Rekening -------------------------------------------------------
        $rekeningAktif = Rekening::query()->where('aktif', true)->count();

        $langkah = [
            [
                'kunci' => 'kebijakan',
                'judul' => 'Tetapkan kebijakan kos',
                'keterangan' => 'Satuan sewa, cara jatuh tempo, dan aturan denda. Tanpa ketiganya '
                    .'tagihan tidak bisa terbit - sistem tidak menebak kebijakan kos.',
                'selesai' => $kebijakanSelesai,
                'wajib' => true,
                'rincian' => $kebijakanSelesai
                    ? null
                    : 'Belum ditetapkan: '.$this->sebutTipe($kurang).'.',
                'tautan' => '/pemilik/kebijakan',
                'aksi' => 'Isi kebijakan',
            ],
            [
                'kunci' => 'kamar',
                'judul' => 'Tambahkan kamar',
                'keterangan' => 'Satu baris per kamar yang disewakan. Nomor kamar inilah yang '
                    .'muncul di tagihan penghuni.',
                'selesai' => $kamar->isNotEmpty(),
                'wajib' => true,
                'rincian' => $kamar->isEmpty()
                    ? 'Belum ada kamar sama sekali.'
                    : $kamar->count().' kamar terdaftar.',
                'tautan' => '/pemilik/kamar',
                'aksi' => 'Tambah kamar',
            ],
            [
                'kunci' => 'tarif',
                'judul' => 'Tetapkan tarif tiap kamar',
                'keterangan' => 'Tarif per bulan untuk setiap kamar. Tagihan dihitung dari sini, '
                    .'dan mengubahnya menambah baris baru - riwayat lama tetap tersimpan.',
                'selesai' => $kamar->isNotEmpty() && $kamarTanpaTarif->isEmpty(),
                'wajib' => true,
                'rincian' => $this->rincianTarif($kamar, $kamarTanpaTarif),
                'tautan' => '/pemilik/kamar',
                'aksi' => 'Atur tarif',
            ],
            [
                'kunci' => 'rekening',
                'judul' => 'Tambahkan rekening tujuan pembayaran',
                'keterangan' => 'Ke mana penghuni mentransfer. Tanpa ini portal penghuni tidak '
                    .'bisa memberi tahu cara membayar, dan setiap penghuni akan bertanya lewat WhatsApp.',
                'selesai' => $rekeningAktif > 0,
                'wajib' => true,
                'rincian' => $rekeningAktif > 0
                    ? $rekeningAktif.' rekening aktif.'
                    : 'Belum ada rekening aktif.',
                'tautan' => '/pemilik/rekening',
                'aksi' => 'Tambah rekening',
            ],
            [
                'kunci' => 'penghuni',
                'judul' => 'Catat penghuni pertama',
                'keterangan' => 'Tidak wajib untuk mulai. Kos yang baru dibuka boleh belum punya '
                    .'penghuni; langkah ini baru perlu saat kamar benar-benar ditempati.',
                'selesai' => Penghuni::query()->exists(),
                'wajib' => false,
                'rincian' => null,
                'tautan' => '/pemilik/penghuni',
                'aksi' => 'Tambah penghuni',
            ],
        ];

        $wajib = array_values(array_filter($langkah, fn (array $l) => $l['wajib']));
        $selesaiWajib = count(array_filter($wajib, fn (array $l) => $l['selesai']));

        return [
            'selesai' => $selesaiWajib === count($wajib),
            'jumlah_selesai' => $selesaiWajib,
            'jumlah_wajib' => count($wajib),
            'langkah' => $langkah,
        ];
    }

    /**
     * @param  Collection<int, Kamar>  $kamar
     * @param  Collection<int, Kamar>  $tanpaTarif
     */
    private function rincianTarif(Collection $kamar, Collection $tanpaTarif): ?string
    {
        if ($kamar->isEmpty()) {
            return 'Tambahkan kamar lebih dulu.';
        }

        if ($tanpaTarif->isEmpty()) {
            return 'Semua kamar sudah punya tarif.';
        }

        $nomor = $tanpaTarif->pluck('nomor')->take(5)->implode(', ');
        $sisa = $tanpaTarif->count() - min(5, $tanpaTarif->count());

        // Dibatasi lima nomor: kos 30 kamar yang belum bertarif sama sekali
        // akan menghasilkan kalimat sepanjang paragraf, dan yang perlu
        // ditindaklanjuti tetap sama - "isi tarifnya".
        return 'Belum bertarif: '.$nomor.($sisa > 0 ? ', dan '.$sisa.' lainnya' : '').'.';
    }

    /**
     * Nama tipe kebijakan dalam bahasa manusia.
     *
     * @param  list<string>  $tipe
     */
    private function sebutTipe(array $tipe): string
    {
        $label = [
            KebijakanKos::TIPE_SATUAN_SEWA => 'satuan sewa',
            KebijakanKos::TIPE_JATUH_TEMPO => 'cara jatuh tempo',
            KebijakanKos::TIPE_DENDA => 'aturan denda',
        ];

        return collect($tipe)->map(fn (string $t) => $label[$t] ?? $t)->implode(', ');
    }
}
