<?php

namespace App\Domain\Kebijakan;

use App\Models\Kamar;
use App\Models\KebijakanKos;
use App\Models\KomponenBiaya;
use Carbon\CarbonInterface;

/**
 * Penyelesai "baris mana yang berlaku" untuk tabel riwayat ber-`berlaku_dari`.
 *
 * Satu kelas melayani `kebijakan_kos` DAN `komponen_biaya`, karena keduanya
 * memakai pola yang sama persis:
 *
 *   1. baris khusus kamar yang berlaku paling akhir menang;
 *   2. kalau tidak ada, baris global (kamar_id NULL) yang berlaku paling akhir;
 *   3. kalau tidak ada juga, tidak ada yang berlaku.
 *
 * Sebelum kelas ini, langkah 1–2 ditulis di `PencariTarif::komponenUntuk` dan
 * akan ditulis ulang untuk kebijakan. Dua salinan berarti dua kesempatan salah
 * pada aturan yang paling mudah salah: baris khusus kamar harus menang atas
 * baris global **tanpa memandang tanggal**. Kalau urutannya terbalik, mengubah
 * default kos akan diam-diam menimpa penimpa per kamar yang lebih baru.
 *
 * Kenapa `berlaku_dari <= awal periode`, bukan `<= hari ini`: tagihan periode
 * Agustus yang diterbitkan bulan September harus memakai kebijakan yang berlaku
 * pada Agustus. Memakai "hari ini" membuat periode yang sama memberi hasil
 * berbeda tergantung kapan tombolnya ditekan — dan itu tepat yang dilarang R-06.
 */
class PenyelesaiKebijakan
{
    /**
     * Tipe kebijakan yang wajib ada sebelum tagihan boleh terbit.
     *
     * Tiga sejak T17. `denda` ikut wajib karena `parameter_denda` sudah
     * dihapus: tidak ada lagi sumber angka denda yang lain, sehingga kosong
     * berarti sistem harus menebak — dan menebak angka uang adalah persis
     * yang dilarang.
     *
     * `deposit` sengaja TIDAK wajib: ia dibaca saat kontrak dibuat, bukan saat
     * tagihan terbit. Kos yang tidak memungut deposit tidak boleh terhalang
     * hanya karena ia tidak menyatakan "tanpa deposit"; ketiadaannya berarti
     * nol, dan pemilik tetap bisa mengisinya sendiri di formulir kontrak.
     *
     * @var list<string>
     */
    public const TIPE_WAJIB = [
        KebijakanKos::TIPE_SATUAN_SEWA,
        KebijakanKos::TIPE_JATUH_TEMPO,
        KebijakanKos::TIPE_DENDA,
    ];

    /**
     * Kebijakan satu tipe yang berlaku untuk satu kamar pada satu periode.
     *
     * `null` berarti belum ditetapkan — bukan error. Pemanggil yang butuh
     * kepastian memakai {@see pastikanLengkap}; layar Pengaturan justru perlu
     * membedakan "belum diisi" dari "galat".
     */
    public function kebijakan(string $tipe, CarbonInterface $periode, ?Kamar $kamar = null): ?KebijakanKos
    {
        return $this->semuaBerlaku($periode, $kamar)[$tipe] ?? null;
    }

    /**
     * Seluruh tipe kebijakan yang berlaku, dipetakan tipe => baris.
     *
     * Satu query untuk semua tipe, bukan satu query per tipe: penerbitan massal
     * memanggil ini untuk setiap kontrak, dan empat query per kontrak pada kos
     * 30 kamar berarti 120 query hanya untuk membaca kebijakan.
     *
     * @return array<string, KebijakanKos>
     */
    public function semuaBerlaku(CarbonInterface $periode, ?Kamar $kamar = null): array
    {
        $awal = $periode->copy()->startOfMonth();

        $semua = KebijakanKos::query()
            ->whereDate('berlaku_dari', '<=', $awal)
            ->when(
                $kamar !== null,
                // Global (kamar_id NULL) + khusus kamar ini.
                fn ($q) => $q->where(fn ($q2) => $q2
                    ->whereNull('kamar_id')
                    ->orWhere('kamar_id', $kamar->id)),
                fn ($q) => $q->whereNull('kamar_id'),
            )
            // Baris khusus kamar didahulukan, lalu yang paling baru. Urutan ini
            // yang membuat penimpa per kamar menang atas default kos tanpa
            // memandang tanggal berlakunya.
            ->orderByRaw('kamar_id IS NULL ASC')
            ->orderByDesc('berlaku_dari')
            ->orderByDesc('id')
            ->get();

        $terakhir = [];
        foreach ($semua as $baris) {
            if (! isset($terakhir[$baris->tipe])) {
                $terakhir[$baris->tipe] = $baris;
            }
        }

        return $terakhir;
    }

    /**
     * Tipe wajib yang belum ditetapkan untuk kamar ini pada periode ini.
     *
     * @return list<string>
     */
    public function tipeYangKurang(CarbonInterface $periode, ?Kamar $kamar = null): array
    {
        $ada = $this->semuaBerlaku($periode, $kamar);

        return array_values(array_filter(
            self::TIPE_WAJIB,
            fn (string $tipe) => ! isset($ada[$tipe]),
        ));
    }

    /**
     * Kebijakan yang berlaku, dijamin lengkap — atau melempar penolakan.
     *
     * Dipakai jalur penerbitan tagihan. Bedanya dengan {@see pastikanLengkap}
     * hanya pada hasil: yang ini mengembalikan barisnya, supaya pemanggil tidak
     * perlu menanyakan ulang "baris mana yang tadi dipakai" dan menjalankan
     * query yang sama untuk kedua kalinya. Pada penerbitan massal 30 kamar,
     * menanyakan ulang berarti 90 query tambahan untuk jawaban yang sudah ada.
     *
     * @return array<string, KebijakanKos>
     *
     * @throws KebijakanBelumLengkap
     */
    public function untukPenerbitan(CarbonInterface $periode, ?Kamar $kamar = null): array
    {
        $berlaku = $this->semuaBerlaku($periode, $kamar);

        $kurang = array_values(array_filter(
            self::TIPE_WAJIB,
            fn (string $tipe) => ! isset($berlaku[$tipe]),
        ));

        if ($kurang !== []) {
            throw KebijakanBelumLengkap::dariTipe($kurang);
        }

        return $berlaku;
    }

    /**
     * @throws KebijakanBelumLengkap
     */
    public function pastikanLengkap(CarbonInterface $periode, ?Kamar $kamar = null): void
    {
        $this->untukPenerbitan($periode, $kamar);
    }

    /**
     * Komponen biaya tetap yang berlaku pada periode tertentu, dikelompokkan
     * per nama. Dipindahkan dari `PencariTarif` tanpa mengubah perilaku.
     *
     * Baris bernilai 0 berarti komponen itu dihentikan — tetap dikembalikan
     * supaya pemanggil bisa membedakan "tidak pernah ada" dari "dihentikan".
     *
     * @return list<KomponenBiaya>
     */
    public function komponenBiaya(CarbonInterface $periode, ?Kamar $kamar = null): array
    {
        $awal = $periode->copy()->startOfMonth();

        $semua = KomponenBiaya::query()
            ->whereDate('berlaku_dari', '<=', $awal)
            ->when(
                $kamar !== null,
                fn ($q) => $q->where(fn ($q2) => $q2
                    ->whereNull('kamar_id')
                    ->orWhere('kamar_id', $kamar->id)),
                // Tanpa kamar: hanya global, supaya daftar di UI tidak
                // menampilkan komponen milik kamar lain.
                fn ($q) => $q->whereNull('kamar_id'),
            )
            ->orderBy('nama')
            ->orderByRaw('kamar_id IS NULL ASC')
            ->orderByDesc('berlaku_dari')
            ->orderByDesc('id')
            ->get();

        $terakhir = [];
        foreach ($semua as $baris) {
            if (! isset($terakhir[$baris->nama])) {
                $terakhir[$baris->nama] = $baris;
            }
        }

        return array_values($terakhir);
    }
}
