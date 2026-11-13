<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kredensial perangkat kos (K-16): WiFi, CCTV, RFID, dan lainnya.
 *
 * **Kenapa tabel sendiri.** Sebelum ini kredensial perangkat hanya hidup di
 * percakapan WhatsApp: sandi WiFi dikirim ulang setiap ada penghuni baru, dan
 * sandi CCTV hanya diingat pemilik. Pertanyaan "sandi WiFi-nya apa" adalah
 * pertanyaan yang paling sering diulang di kos, dan jawabannya tidak boleh
 * bergantung pada siapa yang masih menyimpan chat lama.
 *
 * **Kenapa SATU tabel, bukan tabel per jenis.** Bentuk keempat jenis perangkat
 * sama: nama, letak, identitas, sandi, catatan. Memisahkannya menjadi
 * `perangkat_wifi`/`perangkat_cctv` berarti empat tabel dengan kolom yang sama
 * dan empat endpoint yang isinya sama - dan menambah jenis kelima kelak berarti
 * satu tabel dan satu endpoint lagi. Jenisnya cukup satu kolom ber-CHECK.
 *
 * **Kenapa `sandi` dienkripsi.** K-16 memutuskannya: sandi WiFi hanya boleh
 * sampai ke penghuni lewat server setelah login, tidak boleh terbaca dari
 * cadangan basis data yang bocor. Enkripsinya di model (`encrypted` cast),
 * bukan di sini, supaya tidak ada jalur tulis yang bisa melewatinya.
 *
 * **Kenapa tidak append-only.** Tidak ada angka uang yang dihitung dari tabel
 * ini. Sandi yang diganti hari ini tidak mengubah tagihan bulan lalu. Jejak
 * perubahannya tetap ada di `log_audit` - dan itu yang menjawab "sandi mana
 * yang berlaku bulan lalu" bila suatu saat ditanyakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perangkat', function (Blueprint $table) {
            $table->id();

            // `wifi` | `cctv` | `rfid` | `lainnya`. Jenis menentukan siapa yang
            // boleh melihat sandinya: penghuni hanya boleh melihat `wifi`
            // (K-16), sedangkan CCTV/RFID tetap milik pengurus kos.
            $table->string('jenis', 20)->default('wifi');

            // Nama yang dikenali penghuni: "WiFi Lantai 1", "CCTV Gerbang".
            $table->string('nama', 60);

            // Letak perangkat, untuk membedakan dua WiFi dengan nama mirip.
            $table->string('lokasi', 80)->nullable();

            // SSID WiFi atau nama pengguna perangkat. Boleh kosong: kartu RFID
            // hanya punya nomor, dan CCTV lama kadang tidak punya akun.
            $table->string('nama_pengguna', 80)->nullable();

            // Rahasia yang dienkripsi model. `text`, bukan `string(255)`:
            // ciphertext Laravel jauh lebih panjang daripada sandi aslinya.
            $table->text('sandi')->nullable();

            // Petunjuk pemakaian, mis. "SSID 5 GHz, jangan dipakai untuk
            // printer" atau "kartu ditempel di pintu, jangan dibawa keluar".
            $table->string('catatan', 200)->nullable();

            // Perangkat yang tidak aktif tetap tersimpan tetapi tidak tampil di
            // portal. Menghapusnya akan menghilangkan jawaban "sandi WiFi lama
            // yang mana" saat penghuni lama bertanya.
            $table->boolean('aktif')->default(true);

            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();

            $table->index(['aktif', 'urutan']);
        });

        // CHECK, bukan enum: menambah jenis kelak cukup dengan migrasi kecil.
        // Nilai tertutup ini yang membuat penyaringan portal penghuni bisa
        // diandalkan - jenis di luar daftar tidak mungkin tersimpan.
        DB::statement(
            "ALTER TABLE perangkat ADD CONSTRAINT perangkat_jenis_check
             CHECK (jenis IN ('wifi', 'cctv', 'rfid', 'lainnya'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('perangkat');
    }
};
