<?php

use App\Domain\Notifikasi\Peristiwa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Peristiwa notifikasi kesembilan: `komponen.diubah` (REV-SEC-07).
 *
 * Komponen biaya adalah angka uang yang masuk ke setiap tagihan, dan
 * penambahannya terjadi beberapa kali setahun - bukan puluhan kali sehari.
 * Itu tepat kategori yang sejak T25 dikirim lewat email juga, sama seperti
 * tarif dan kebijakan. Meminjam `kebijakan.diubah` akan membuat pemilik
 * menerima email berjudul "Kebijakan kos diubah" untuk perubahan yang tidak
 * menyentuh kebijakan apa pun.
 *
 * Bawaannya in-app + email; lihat {@see Peristiwa}.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE pengaturan_notifikasi DROP CONSTRAINT IF EXISTS pengaturan_notifikasi_peristiwa_dikenal'
        );

        DB::statement(
            "ALTER TABLE pengaturan_notifikasi
             ADD CONSTRAINT pengaturan_notifikasi_peristiwa_dikenal
             CHECK (peristiwa IN (
                 'usulan.diajukan', 'usulan.diputuskan',
                 'tarif.diubah', 'kebijakan.diubah', 'komponen.diubah',
                 'tagihan.massal', 'tagihan.terbit',
                 'pembayaran.dicatat', 'pembayaran.diverifikasi'
             ))"
        );
    }

    public function down(): void
    {
        // Pengaturan yang menyebut peristiwa ini harus hilang lebih dulu,
        // kalau tidak penambahan CHECK lama gagal dan rollback berhenti di
        // tengah jalan.
        DB::table('pengaturan_notifikasi')->where('peristiwa', 'komponen.diubah')->delete();

        DB::statement(
            'ALTER TABLE pengaturan_notifikasi DROP CONSTRAINT IF EXISTS pengaturan_notifikasi_peristiwa_dikenal'
        );

        DB::statement(
            "ALTER TABLE pengaturan_notifikasi
             ADD CONSTRAINT pengaturan_notifikasi_peristiwa_dikenal
             CHECK (peristiwa IN (
                 'usulan.diajukan', 'usulan.diputuskan',
                 'tarif.diubah', 'kebijakan.diubah',
                 'tagihan.massal', 'tagihan.terbit',
                 'pembayaran.dicatat', 'pembayaran.diverifikasi'
             ))"
        );
    }
};
