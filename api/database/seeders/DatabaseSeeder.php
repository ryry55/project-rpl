<?php

use App\Models\Penghuni;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Data awal untuk demo.
 *
 * Kredensial sengaja sederhana dan dicetak di README supaya dosen bisa langsung
 * masuk tanpa menebak. Jangan dipakai di produksi.
 *
 * Semua penyemaian memakai `firstOrCreate`, bukan `updateOrCreate`: tabel
 * riwayat bersifat append-only, dan trigger di basis data akan menolak UPDATE.
 * Seeder yang menjalankan ulang `updateOrCreate` akan gagal — dan itu memang
 * perilaku yang diinginkan, karena mengubah riwayat tidak boleh jadi kebiasaan.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $pemilik = User::updateOrCreate(
            ['email' => 'pemilik@kos.test'],
            [
                'name' => 'Yukie Wirapratama Agusti',
                'password' => Hash::make('password'),
                'peran' => User::PERAN_PEMILIK,
                'penghuni_id' => null,
            ]
        );

        $penghuni = Penghuni::updateOrCreate(
            ['nama' => 'Budi Santoso'],
            ['email' => 'penghuni@kos.test', 'no_hp' => '081200000001']
        );

        $akunPenghuni = User::updateOrCreate(
            ['email' => 'penghuni@kos.test'],
            [
                'name' => 'Budi Santoso',
                'password' => Hash::make('password'),
                'peran' => User::PERAN_PENGHUNI,
                'penghuni_id' => $penghuni->id,
            ]
        );

        // Sengaja TIDAK menyemai kebijakan kos maupun parameter denda.
        //
        // Sejak T17 tidak ada lagi `parameter_denda`: angka denda hanya datang
        // dari kebijakan yang dipilih pemilik. Membuatkan kebijakan bawaan di
        // sini akan menyembunyikan gerbang "kebijakan belum lengkap" — dan
        // gerbang itulah yang mendemonstrasikan tesis proyek ini: sistem tidak
        // menebak kebijakan kos.

        $this->command?->info("Pemilik  : {$pemilik->email} / password");
        $this->command?->info("Penghuni : {$akunPenghuni->email} / password");
    }
}
