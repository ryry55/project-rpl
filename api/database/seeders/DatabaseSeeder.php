<?php

namespace Database\Seeders;

use App\Domain\Akses\Wewenang;
use App\Models\AturanKos;
use App\Models\Fasilitas;
use App\Models\PengelolaKewenangan;
use App\Models\Penghuni;
use App\Models\PersetujuanPdp;
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
 *
 * **Namespace `Database\Seeders` wajib ada.** Tanpa itu kelasnya menjadi
 * `DatabaseSeeder` global, sementara `db:seed` mencari
 * `Database\Seeders\DatabaseSeeder` - dan karena tidak menemukannya, autoloader
 * memuat berkas ini SETIAP kali seeder dipanggil. Panggilan pertama berhasil,
 * panggilan kedua gagal dengan "Cannot declare class DatabaseSeeder, because
 * the name is already in use". Gejalanya menyesatkan karena `db:seed` yang
 * dijalankan sekali - seperti di entrypoint - selalu tampak baik-baik saja.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seeder ini menulis sandi yang tercetak di layar masuk, dan
        // menimpanya setiap kali berjalan. Di produksi itu berarti akun
        // pemilik kembali ke "password" setiap layanan bangun dari tidur -
        // persis setelah T27 menghabiskan seluruh tiketnya untuk memastikan
        // tidak ada yang tahu sandi orang lain.
        //
        // Penolakan ini bukan kehati-hatian berlebihan: satu deploy dengan
        // RUN_SEEDER yang lupa dimatikan sudah cukup untuk membuka seluruh
        // data kos. Lebih baik gagal keras dan terlihat.
        if (app()->environment('production')) {
            $this->command?->warn(
                'Seeder tidak dijalankan di produksi (T29). '
                .'Akun demo hanya boleh dibuat di lingkungan pengembangan.'
            );

            return;
        }

        $pemilik = User::updateOrCreate(
            ['email' => 'pemilik@kos.test'],
            [
                'name' => 'Yukie Wirapratama Agusti',
                'password' => Hash::make('password'),
                'peran' => User::PERAN_PEMILIK,
                'penghuni_id' => null,
                // Pemilik tidak pernah ditahan oleh gerbang mana pun, tetapi
                // email_verified_at diisi supaya keadaannya jujur: akun ini
                // memang dipakai masuk.
                'email_verified_at' => now(),
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
                // Akun demo dianggap sudah aktif dan sudah menyetujui PDP.
                // Tanpa ini, dosen yang membuka aplikasi mendarat di layar
                // persetujuan sebelum melihat satu tagihan pun - dan kredensial
                // yang tercetak di layar masuk menjadi tidak berguna.
                'email_verified_at' => now(),
            ]
        );

        // Akun demo dikecualikan dari kewajiban ganti sandi. Sandinya memang
        // dicetak di layar masuk dengan sengaja, jadi memaksanya berganti
        // berarti mencetak kredensial yang tidak berlaku.
        $akunPenghuni->forceFill(['wajib_ganti_sandi' => false])->save();

        // Akun pengelola contoh (T24).
        //
        // Diberi empat wewenang harian dan sengaja TIDAK diberi `ubah_tarif`
        // maupun `ubah_kebijakan`. Itu bukan kelengkapan demo: kalau akun
        // contoh ini punya semua wewenang, tidak ada yang bisa diperlihatkan
        // saat menguji pembatasan - layar akan tampak sama saja untuk semua
        // orang, dan dosen tidak punya cara melihat bahwa pembatasannya bekerja.
        $pengelola = User::updateOrCreate(
            ['email' => 'pengelola@kos.test'],
            [
                'name' => 'Sari Pengelola',
                'password' => Hash::make('password'),
                'peran' => User::PERAN_PENGELOLA,
                'penghuni_id' => null,
                'pemilik_id' => $pemilik->id,
                'email_verified_at' => now(),
            ]
        );

        $pengelola->forceFill(['wajib_ganti_sandi' => false])->save();

        // Persetujuan PDP untuk ketiga akun contoh (T28).
        //
        // Tanpa baris ini, dosen yang membuka aplikasi mendarat di layar
        // persetujuan sebelum melihat satu tagihan pun - dan kredensial yang
        // tercetak di layar masuk menjadi tidak berguna, karena layar itu
        // muncul sebelum apa pun bisa dilihat.
        //
        // `firstOrCreate`, bukan `create`: tabelnya append-only lewat trigger,
        // dan seeder ini dijalankan setiap container start.
        foreach ([$pemilik, $akunPenghuni, $pengelola] as $akunContoh) {
            PersetujuanPdp::firstOrCreate(
                ['user_id' => $akunContoh->id, 'versi' => PersetujuanPdp::VERSI_BERLAKU],
                ['disetujui_pada' => now()]
            );
        }

        foreach ([
            Wewenang::CATAT_PEMBAYARAN,
            Wewenang::KELOLA_KAMAR,
            Wewenang::KELOLA_PENGHUNI,
            Wewenang::TERBITKAN_TAGIHAN,
        ] as $w) {
            PengelolaKewenangan::firstOrCreate([
                'user_id' => $pengelola->id,
                'wewenang' => $w,
            ]);
        }

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

        $this->command?->info("Pemilik    : {$pemilik->email} / password");
        $this->command?->info("Pengelola  : {$pengelola->email} / password  (tanpa ubah tarif/kebijakan)");
        $this->command?->info("Penghuni   : {$akunPenghuni->email} / password");
    }
}
