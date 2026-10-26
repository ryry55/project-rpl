<?php

namespace App\Console\Commands;

use App\Domain\Audit\PencatatAudit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Membuat akun pemilik pertama di lingkungan yang tidak punya akun apa pun.
 *
 * **Masalah yang dijawab perintah ini.** Seeder menolak berjalan di produksi
 * (`DatabaseSeeder::run`), dan penolakan itu benar: seeder menulis sandi
 * "password" ke akun pemilik. Akibatnya sebuah instalasi produksi yang baru
 * selesai deploy tidak punya satu pun akun, dan tidak ada jalan masuk sama
 * sekali. Sebelum perintah ini, satu-satunya cara adalah menempel blok
 * `php artisan tinker --execute="User::create([...])"` dari dokumentasi - dan
 * blok itu punya tiga cacat yang semuanya tidak terlihat sampai dijalankan
 * sungguhan:
 *
 *   1. **Sandi tertulis di riwayat shell.** `tinker --execute` menaruh seluruh
 *      kalimatnya di `.bash_history` dan di log perintah. Sandi pemilik kos
 *      adalah kunci seluruh datanya.
 *   2. **Tidak ada pemeriksaan apa pun.** Salah ketik email, sandi kurang dari
 *      delapan huruf, atau email yang sudah dipakai semuanya berakhir sebagai
 *      pengecualian basis data yang tidak menjelaskan apa yang harus diperbaiki.
 *   3. **Menjalankannya dua kali membuat dua pemilik.** Tidak ada yang menahan,
 *      dan pada model satu-kos dua akun pemilik berarti dua orang yang
 *      sama-sama bisa mengubah tarif tanpa jejak siapa yang lebih berhak.
 *
 * Perintah ini menutup ketiganya: sandi ditanyakan secara tersembunyi
 * (`secret()`, tidak tampil di layar dan tidak masuk riwayat), isian divalidasi
 * dengan aturan yang sama seperti formulir lain, dan akun pemilik kedua ditolak
 * kecuali diminta secara sadar lewat `--paksa`.
 *
 * **Kenapa bukan endpoint `POST /pemilik`.** Endpoint untuk membuat pemilik
 * harus terbuka bagi seseorang yang belum punya akun, dan tidak ada satu pun
 * hal yang bisa dipakai untuk membuktikan bahwa orang itu memang pemilik kos
 * ini. Setiap bentuknya berakhir sebagai pintu belakang: token statis di URL,
 * "kode undangan" yang bisa ditebak, atau pendaftaran terbuka. Perintah artisan
 * menuntut akses ke server, dan akses ke server sudah berarti seluruh data bisa
 * dibaca langsung dari basis data - jadi ia tidak menambah permukaan serangan,
 * sementara endpoint akan menambahkannya.
 */
class BuatPemilik extends Command
{
    protected $signature = 'kos:buat-pemilik
                            {--nama= : Nama pemilik (kosongkan untuk ditanyakan)}
                            {--email= : Email pemilik (kosongkan untuk ditanyakan)}
                            {--sandi= : Kata sandi. AWAS: argumen perintah tercatat di riwayat shell}
                            {--paksa : Tetap buat walau sudah ada akun pemilik}';

    protected $description = 'Buat akun pemilik pertama (untuk instalasi produksi yang belum punya akun)';

    public function handle(PencatatAudit $audit): int
    {
        // --- Pagar 1: jangan diam-diam membuat pemilik kedua -----------------
        //
        // Diperiksa lebih dulu, sebelum satu pertanyaan pun diajukan. Menanyakan
        // nama dan sandi lalu menolak di akhir membuat orang mengetik dua kali
        // untuk sesuatu yang sudah bisa diketahui sejak awal.
        $sudahAda = User::query()->where('peran', User::PERAN_PEMILIK)->get(['email']);

        if ($sudahAda->isNotEmpty() && ! $this->option('paksa')) {
            $this->error('Sudah ada akun pemilik: '.$sudahAda->pluck('email')->implode(', '));
            $this->line('');
            $this->line('Model satu-kos hanya mengenal satu pemilik. Menambah pemilik kedua berarti');
            $this->line('dua orang yang sama-sama bisa mengubah tarif dan kebijakan, dan tidak ada');
            $this->line('satu pun pemeriksaan di aplikasi yang membedakan keduanya.');
            $this->line('');
            $this->line('Bila akun itu memang harus dibuat juga (mis. pasangan pemilik), ulangi');
            $this->line('dengan --paksa. Bila yang Anda butuhkan hanya memulihkan akses, gunakan');
            $this->line('"lupa sandi" di layar masuk.');

            return self::FAILURE;
        }

        // --- Isian ----------------------------------------------------------
        $nama = $this->option('nama') ?: $this->ask('Nama pemilik');
        $email = $this->option('email') ?: $this->ask('Email pemilik');

        // `secret()` menyembunyikan ketikan. Ini bukan kerapian: sandi yang
        // tampil di layar ikut terekam pada rekaman sesi (tmux, screen, asciinema)
        // dan pada tangkapan layar yang biasa dikirim orang saat minta bantuan.
        $sandiDiketik = false;
        $sandi = $this->option('sandi');

        if ($sandi === null) {
            if ($this->input->isInteractive()) {
                $sandi = (string) $this->secret('Kata sandi (minimal 8 huruf, tidak akan tampil)');
                $ulang = (string) $this->secret('Ulangi kata sandi');
                $sandiDiketik = true;

                if ($sandi !== $ulang) {
                    $this->error('Ulangan kata sandi tidak sama. Tidak ada yang dibuat.');

                    return self::FAILURE;
                }
            } else {
                // Tanpa TTY tidak ada yang bisa ditanyakan. Sandi dibangkitkan,
                // dicetak SEKALI, dan akunnya ditandai wajib ganti sandi.
                $sandi = Str::password(20);
            }
        }

        // --- Pagar 2: validasi yang sama dengan formulir lain ----------------
        //
        // `min:8` disalin dari `AuthController::aturSandi`, bukan dipilih ulang:
        // akun yang lahir dengan sandi lebih pendek daripada yang diterima
        // halaman ganti sandi adalah akun yang tidak bisa memakai aturannya
        // sendiri.
        $validator = Validator::make(
            ['nama' => $nama, 'email' => $email, 'sandi' => $sandi],
            [
                'nama' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'sandi' => ['required', 'string', 'min:8'],
            ],
            [
                'nama.required' => 'Nama pemilik wajib diisi.',
                'email.required' => 'Email wajib diisi.',
                'email.email' => 'Email tidak berbentuk alamat email.',
                'email.unique' => 'Email itu sudah dipakai akun lain.',
                'sandi.required' => 'Kata sandi wajib diisi.',
                'sandi.min' => 'Kata sandi minimal 8 huruf.',
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $pesan) {
                $this->error($pesan);
            }
            $this->line('Tidak ada yang dibuat.');

            return self::FAILURE;
        }

        // --- Pagar 3: tandai sandi yang diketahui pihak lain -----------------
        //
        // Tiga jalur, tiga alasan berbeda - dan bedanya bukan kerapian, melainkan
        // siapa yang mungkin sudah melihat sandinya:
        //
        //   diketik interaktif -> TIDAK wajib ganti. Yang mengetik adalah orang
        //                         yang akan memakainya, dan ketikannya tersembunyi
        //                         sehingga tidak masuk riwayat terminal.
        //   --sandi=...        -> WAJIB ganti. Argumen perintah tercatat di
        //                         `.bash_history` dan terlihat di `ps`.
        //   dibangkitkan       -> WAJIB ganti. Sandinya tercetak di layar, dan
        //                         layar itu kemungkinan besar masih bisa digulir.
        $wajibGanti = ! $sandiDiketik;

        $pemilik = DB::transaction(function () use ($nama, $email, $sandi, $wajibGanti, $audit) {
            $user = User::create([
                'name' => $nama,
                'email' => $email,
                'password' => $sandi,
                'peran' => User::PERAN_PEMILIK,
                'penghuni_id' => null,
                'pemilik_id' => null,
                // Langsung aktif. Berbeda dari penghuni dan pengelola, tidak ada
                // tautan aktivasi yang dikirim ke pemilik: yang menjalankan
                // perintah ini sudah memegang akses server, dan akses server
                // sudah cukup untuk membaca seluruh basis data. Menuntut
                // aktivasi lewat email hanya menambah satu langkah yang bisa
                // gagal (SMTP belum disetel pada instalasi baru) tanpa menutup
                // apa pun.
                'email_verified_at' => now(),
            ]);

            // `wajib_ganti_sandi` tidak ada di `$fillable` - sengaja, sama
            // seperti di seeder: kolom itu hanya boleh ditulis kode, tidak
            // pernah dari masukan pengguna.
            $user->forceFill(['wajib_ganti_sandi' => $wajibGanti])->save();

            // Jejak audit tanpa nama dan email, sama seperti `pengelola.tambah`.
            // Alasannya sudah dicatat di sana: `log_audit` tidak bisa diubah,
            // sehingga salinan data pribadi di dalamnya tidak akan pernah
            // terjangkau permintaan penghapusan data (T28).
            $audit->catat(
                aksi: 'pemilik.tambah',
                entitas: 'users',
                entitasId: $user->id,
                nilaiBaru: ['peran' => User::PERAN_PEMILIK],
            );

            return $user;
        });

        // --- Laporan --------------------------------------------------------
        $this->info('Akun pemilik dibuat.');
        $this->line('  nama  : '.$pemilik->name);
        $this->line('  email : '.$pemilik->email);

        if (! $sandiDiketik) {
            $this->line('  sandi : '.$sandi);
            $this->warn('Sandi di atas hanya ditampilkan sekali dan tidak disimpan di mana pun.');
        }

        if ($wajibGanti) {
            $this->line('');
            $this->line('Akun ini akan diminta mengganti sandi saat pertama masuk, karena sandinya');
            $this->line('sudah terlihat di layar atau tercatat sebagai argumen perintah.');
        }

        $this->line('');
        $this->line('Langkah berikutnya, berurutan (lihat docs/produk/13_PANDUAN-PENYIAPAN.md):');
        $this->line('  1. masuk, lalu ganti sandi bila diminta');
        $this->line('  2. isi Kebijakan kos - tagihan tidak bisa terbit sebelum lengkap');
        $this->line('  3. tambah kamar dan tetapkan tarifnya');
        $this->line('  4. tambah rekening tujuan pembayaran');

        return self::SUCCESS;
    }
}
