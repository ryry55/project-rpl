<?php

namespace App\Domain\Kebijakan;

use App\Models\KebijakanKos;

/**
 * Berapa deposit yang harus ditagih saat kontrak dibuat.
 *
 * Sebelum T17, deposit hanya kolom di `kontrak` yang diisi tangan dan tidak
 * pernah dibaca siapa pun — nilai yang tersimpan tetapi tidak berakibat.
 * Sekarang kebijakan `deposit` menentukan bawaannya:
 *
 *   - `tanpa_deposit`  -> 0
 *   - `nominal`        -> angka tetap dari kebijakan
 *   - `satu_bulan`     -> sebesar tarif sewa yang berlaku saat kontrak dibuat
 *
 * Tiga keputusan yang disengaja:
 *
 * 1. **Ketiadaan kebijakan berarti nol, bukan galat.** Berbeda dengan denda,
 *    deposit tidak wajib ditetapkan. Kos yang tidak memungut deposit adalah
 *    keadaan normal, dan memaksanya menyatakan "tanpa deposit" hanya menambah
 *    langkah tanpa menambah kejelasan.
 *
 * 2. **`satu_bulan` memakai tarif saat kontrak dibuat, bukan saat dibaca.**
 *    Deposit adalah uang yang diserahkan di muka; menaikkan tarif tahun depan
 *    tidak boleh mengubah deposit yang sudah dibayar.
 *
 * 3. **Pemilik tetap bisa menimpa.** Fungsi ini hanya menghasilkan nilai
 *    bawaan untuk formulir; isian pemilik menang, karena kesepakatan dengan
 *    penghuni bisa berbeda dari kebijakan umum kos.
 */
final class PenentuDeposit
{
    /**
     * @param  int  $tarifSewa  tarif yang berlaku saat kontrak dibuat
     */
    public static function hitung(?KebijakanKos $kebijakan, int $tarifSewa): int
    {
        if ($kebijakan === null) {
            return 0;
        }

        return match ($kebijakan->nilai) {
            PilihanKebijakan::DEPOSIT_TANPA_DEPOSIT => 0,
            PilihanKebijakan::DEPOSIT_NOMINAL => (int) $kebijakan->nominal,
            PilihanKebijakan::DEPOSIT_SATU_BULAN => $tarifSewa,
            default => 0,
        };
    }

    /**
     * Penjelasan singkat untuk layar, supaya pemilik tahu dari mana angka itu.
     */
    public static function keterangan(?KebijakanKos $kebijakan): string
    {
        if ($kebijakan === null) {
            return 'Belum ada kebijakan deposit; isi sendiri bila kos ini memungut deposit.';
        }

        return match ($kebijakan->nilai) {
            PilihanKebijakan::DEPOSIT_TANPA_DEPOSIT => 'Kebijakan kos: tanpa deposit.',
            PilihanKebijakan::DEPOSIT_NOMINAL => 'Kebijakan kos: deposit nominal tetap.',
            PilihanKebijakan::DEPOSIT_SATU_BULAN => 'Kebijakan kos: deposit sebesar satu bulan sewa.',
            default => '',
        };
    }
}
