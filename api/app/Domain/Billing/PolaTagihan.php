<?php

namespace App\Domain\Billing;

use App\Domain\Kebijakan\PilihanKebijakan;
use App\Models\KebijakanKos;
use Carbon\CarbonInterface;

/**
 * Pola tagihan: kapan siklus sebuah kontrak dimulai.
 *
 * Dua pola, dan hanya dua:
 *
 *   - `tanggal_1` — awal siklus tanggal 1 tiap bulan. Ini perilaku lama, dan
 *     tetap menjadi bawaan bila kebijakan `pola_tagihan` belum diisi.
 *   - `anniversary` — awal siklus tanggal masuk kontrak, dijepit ke akhir
 *     bulan. Masuk 31 Januari berarti siklus Februari mulai 28 Februari.
 *
 * Kelas ini murni (tanpa basis data) supaya aturan tanggalnya bisa diuji tanpa
 * kontrak, tarif, atau PostgreSQL — sama seperti {@see Kalkulator}. Yang
 * disimpan di `tagihan.periode` adalah **awal siklus**, bukan selalu tanggal 1.
 *
 * Penjepitan ke akhir bulan bukan hiasan: tanpa itu, "tanggal 31" di bulan
 * Februari akan menggelinding ke 3 Maret, dan tagihan lahir dengan periode yang
 * tidak pernah diminta siapa pun.
 */
final class PolaTagihan
{
    /**
     * Pola yang dipakai dari baris kebijakan, dengan bawaan yang aman.
     *
     * Baris kosong atau nilai tak dikenal berarti `tanggal_1`, bukan galat:
     * kos yang belum pernah menyentuh layar Kebijakan harus tetap menagih
     * seperti sebelum fitur ini ada.
     */
    public static function dari(?KebijakanKos $baris): string
    {
        return $baris?->nilai === PilihanKebijakan::POLA_ANNIVERSARY
            ? PilihanKebijakan::POLA_ANNIVERSARY
            : PilihanKebijakan::POLA_TANGGAL_1;
    }

    /**
     * Awal siklus untuk bulan yang memuat `$acuan`.
     *
     * `$acuan` boleh tanggal berapa pun di bulan itu; yang dipakai hanya
     * bulannya. Untuk pola anniversary, tanggal masuk yang menentukan.
     */
    public static function awalSiklus(
        string $pola,
        CarbonInterface $tanggalMasuk,
        CarbonInterface $acuan,
    ): CarbonInterface {
        $bulan = $acuan->copy()->startOfMonth();

        if ($pola !== PilihanKebijakan::POLA_ANNIVERSARY) {
            return $bulan;
        }

        return $bulan->copy()->day(min($tanggalMasuk->day, $bulan->daysInMonth));
    }

    /**
     * Awal siklus pertama kontrak — siklus paling awal yang boleh ditagih.
     *
     * Untuk `tanggal_1` siklus pertama adalah tanggal 1 bulan masuk (tagihan
     * bulan masuk diprorata dari tanggal masuk). Untuk `anniversary` siklus
     * pertama adalah tanggal masuk itu sendiri.
     */
    public static function siklusPertama(string $pola, CarbonInterface $tanggalMasuk): CarbonInterface
    {
        return $pola === PilihanKebijakan::POLA_ANNIVERSARY
            ? $tanggalMasuk->copy()->startOfDay()
            : $tanggalMasuk->copy()->startOfMonth();
    }

    /**
     * Tanggal tagihan boleh mulai terbit untuk satu siklus (K-14 Level 1).
     *
     * **Tanggal terbit hanya boleh menunda, tidak pernah memajukan.** Kalau
     * pemilik menyetel tanggal 5 sementara siklusnya mulai tanggal 15, tagihan
     * tetap terbit tanggal 15 — menerbitkannya tanggal 5 berarti menagih
     * sebelum penghuni menempati kamar, dan itu justru yang dicegah penjagaan
     * "belum waktunya".
     *
     * `null` berarti belum disetel: terbit pada hari siklusnya mulai, yaitu
     * perilaku sebelum fitur ini ada.
     */
    public static function tanggalTerbit(CarbonInterface $awalSiklus, ?int $tanggal): CarbonInterface
    {
        if ($tanggal === null) {
            return $awalSiklus->copy()->startOfDay();
        }

        $hari = min($tanggal, $awalSiklus->daysInMonth);
        $calon = $awalSiklus->copy()->startOfMonth()->day($hari);

        return $calon->lessThan($awalSiklus)
            ? $awalSiklus->copy()->startOfDay()
            : $calon;
    }
}
