<?php

namespace App\Domain\Audit;

use App\Http\Controllers\Api\KebijakanController;
use App\Models\KebijakanKos;
use App\Models\TarifSewa;

/**
 * Bentuk nilai_lama untuk perubahan tarif dan kebijakan (T24).
 *
 * Kenapa kelas terpisah, bukan dua blok kode di controller. Pertanyaannya sama
 * di kedua tempat: "baris mana yang sedang digantikan?". Menjawabnya dengan
 * cara berbeda di dua tempat menghasilkan jejak yang tidak bisa dibandingkan -
 * yang satu menyimpan seluruh baris, yang lain hanya angkanya, dan laporan
 * "dari Rp 1.500.000 menjadi Rp 1.800.000" hanya bisa disusun dari bentuk yang
 * seragam.
 *
 * Kenapa dicari, bukan dioper pemanggil. Tarif dan kebijakan append-only: yang
 * berubah bukan satu baris, melainkan baris baru ditambahkan di atas yang lama.
 * "Nilai lama" karena itu berarti **baris terakhir yang berlaku sebelum
 * tanggal berlaku yang baru** - dan definisi itu harus sama dengan yang dipakai
 * saat menghitung tagihan, kalau tidak jejaknya menceritakan perubahan yang
 * tidak pernah terjadi.
 *
 * Nilai lama dibiarkan null bila memang belum ada yang digantikan. Itu jawaban
 * yang benar untuk penetapan pertama, dan lebih jujur daripada mengarang nol.
 */
final class NilaiLama
{
    /**
     * Baris tarif yang digantikan oleh tarif baru ini.
     *
     * Yang dicari adalah tarif dengan `berlaku_dari` paling akhir yang **masih
     * sebelum** tanggal berlaku baru. Tarif yang berlaku mulai tanggal yang
     * sama persis tidak dianggap "lama": basis data menolak duplikat seperti
     * itu, jadi keadaan tersebut tidak mungkin ada.
     *
     * @return array<string, mixed>|null
     */
    public static function tarif(?TarifSewa $sebelumnya): ?array
    {
        if ($sebelumnya === null) {
            return null;
        }

        return [
            'kamar_id' => $sebelumnya->kamar_id,
            'nilai' => (int) $sebelumnya->nilai,
            'berlaku_dari' => $sebelumnya->berlaku_dari->toDateString(),
        ];
    }

    /**
     * Baris kebijakan yang digantikan, untuk tipe dan kamar yang sama.
     *
     * Bentuknya sengaja identik dengan `nilai_baru` yang ditulis
     * {@see KebijakanController::store}: pembaca jejak
     * harus bisa membandingkan keduanya kolom demi kolom tanpa memetakan nama
     * lebih dulu.
     *
     * @return array<string, mixed>|null
     */
    public static function kebijakan(?KebijakanKos $sebelumnya): ?array
    {
        if ($sebelumnya === null) {
            return null;
        }

        return [
            'tipe' => $sebelumnya->tipe,
            'nilai' => $sebelumnya->nilai,
            'kamar_id' => $sebelumnya->kamar_id,
            'persen' => $sebelumnya->persen,
            'plafon_persen' => $sebelumnya->plafon_persen,
            'masa_tenggang' => $sebelumnya->masa_tenggang,
            'nominal' => $sebelumnya->nominal,
            'tanggal' => $sebelumnya->tanggal,
            'berlaku_dari' => $sebelumnya->berlaku_dari->toDateString(),
        ];
    }
}
