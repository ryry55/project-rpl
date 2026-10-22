<?php

use App\Models\ParameterDenda;
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

        // Parameter denda awal: 1% per hari, plafon 30%, masa tenggang 7 hari
        // (R-03). Tanpa baris ini, T3 tidak punya parameter untuk dihitung.
        ParameterDenda::firstOrCreate(
            ['berlaku_dari' => '2026-01-01'],
            [
                'tarif_persen' => 1.00,
                'plafon_persen' => 30.00,
                'masa_tenggang' => 7,
            ]
        );

        $this->command?->info("Pemilik  : {$pemilik->email} / password");
        $this->command?->info("Penghuni : {$akunPenghuni->email} / password");
    }
}
