<?php

use App\Models\Penghuni;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Akun awal untuk demo T1.
 *
 * Kredensial sengaja sederhana dan dicetak di README supaya dosen bisa langsung
 * masuk tanpa menebak. Jangan dipakai di produksi.
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
            ['no_hp' => '081200000001']
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

        $this->command?->info("Pemilik  : {$pemilik->email} / password");
        $this->command?->info("Penghuni : {$akunPenghuni->email} / password");
    }
}
