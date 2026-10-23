<?php

use App\Models\AturanKos;
use App\Models\Fasilitas;
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
        // sini akan menyembunyikan gerbang "kebijakan belum lengkap" - dan
        // gerbang itulah yang mendemonstrasikan tesis proyek ini: sistem tidak
        // menebak kebijakan kos.

        // Fasilitas dan aturan (T18, T19) DISEMAI, berbeda dari kebijakan.
        //
        // Alasannya berbeda jenis: kebijakan kosong adalah gerbang yang harus
        // dilihat dosen, sedangkan daftar fasilitas kosong hanya membuat layar
        // kamar terlihat belum jadi. Keduanya juga tidak menyentuh uang, jadi
        // menyemainya tidak menyembunyikan keputusan apa pun.
        //
        // Memakai `firstOrCreate` supaya seeder boleh dijalankan berulang.
        foreach ([
            ['nama' => 'AC', 'ikon' => 'ac', 'urutan' => 10],
            ['nama' => 'Kamar mandi dalam', 'ikon' => 'mandi', 'urutan' => 20],
            ['nama' => 'Lemari', 'ikon' => 'lemari', 'urutan' => 30],
            ['nama' => 'Meja belajar', 'ikon' => 'meja', 'urutan' => 40],
            ['nama' => 'Wi-Fi', 'ikon' => 'wifi', 'urutan' => 50],
            ['nama' => 'Parkir motor', 'ikon' => 'parkir', 'urutan' => 60],
        ] as $f) {
            Fasilitas::firstOrCreate(['nama' => $f['nama']], $f);
        }

        foreach ([
            [
                'judul' => 'Jam malam',
                'isi' => 'Gerbang dikunci pukul 23.00. Penghuni yang pulang lebih larut '
                    .'mohon memberi tahu pemilik lebih dulu lewat WhatsApp.',
                'kategori' => 'keamanan',
                'sanksi' => 'Teguran lisan',
                'urutan' => 10,
            ],
            [
                'judul' => 'Tamu',
                'isi' => 'Tamu boleh berkunjung sampai pukul 21.00 dan tidak boleh menginap '
                    .'tanpa izin pemilik.',
                'kategori' => 'tamu',
                'sanksi' => 'Tamu diminta pulang',
                'urutan' => 20,
            ],
            [
                'judul' => 'Kebersihan',
                'isi' => 'Sampah dibuang ke tempat sampah di depan setiap malam. Kamar '
                    .'dibersihkan penghuni masing-masing.',
                'kategori' => 'kebersihan',
                'sanksi' => null,
                'urutan' => 30,
            ],
            [
                'judul' => 'Pembayaran sewa',
                'isi' => 'Sewa dibayar paling lambat tanggal jatuh tempo yang tertera di '
                    .'tagihan. Keterlambatan dikenakan denda sesuai kebijakan kos.',
                'kategori' => 'pembayaran',
                'sanksi' => 'Denda sesuai kebijakan',
                'urutan' => 40,
            ],
        ] as $a) {
            AturanKos::firstOrCreate(['judul' => $a['judul']], $a);
        }

        $this->command?->info("Pemilik  : {$pemilik->email} / password");
        $this->command?->info("Penghuni : {$akunPenghuni->email} / password");
    }
}
