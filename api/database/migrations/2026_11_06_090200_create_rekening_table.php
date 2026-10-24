<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REV-UI-05 - rekening dan QRIS tujuan pembayaran.
 *
 * Sebelum ini portal penghuni hanya menyebut angka yang harus dibayar, tanpa
 * pernah menyebut ke mana uangnya dikirim. Penghuni lalu bertanya lewat
 * WhatsApp, dan pemilik mengetik ulang nomor rekeningnya untuk tiap orang.
 *
 * **Kenapa tabel tersendiri, bukan jenis `kebijakan_kos` baru.** Bentuk
 * `kebijakan_kos` adalah satu nilai pilihan ditambah kolom angka bertipe
 * (`persen`, `nominal`, `masa_tenggang`, `tanggal`). Nomor rekening adalah teks
 * bebas, dan menaruhnya di kolom angka berarti menambah kolom `teks` yang tidak
 * pernah dipakai jenis lain - satu kolom yang selalu kosong di 95% baris hanya
 * supaya satu jenis muat. Rekening juga **berjumlah lebih dari satu** (satu
 * kos bisa punya BCA dan QRIS sekaligus), sedangkan `kebijakan_kos` dirancang
 * satu baris per dimensi.
 *
 * **Tidak append-only**, berbeda dari `tarif_sewa`/`kebijakan_kos`/
 * `komponen_biaya`. Tidak ada angka uang yang dihitung dari baris ini: ia hanya
 * dibaca dan ditampilkan. Mengganti nomor rekening hari ini tidak mengubah
 * tagihan bulan lalu, karena tagihan tidak pernah membaca tabel ini. Nomor lama
 * tetap terlacak di `log_audit` lewat aksi `rekening.ubah`, dan itu memang
 * tempat yang benar untuk jejak "rekening mana yang berlaku kapan".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rekening', function (Blueprint $table) {
            $table->id();

            // `transfer` atau `qris`. Dua bentuk yang benar-benar berbeda di
            // layar: transfer menampilkan nomor rekening dan atas nama, QRIS
            // menampilkan gambar yang dipindai. Menyatukannya menjadi satu
            // kolom teks bebas akan memaksa layar menebak isinya.
            $table->string('jenis', 20)->default('transfer');

            // Nama bank atau penyedia QRIS ("BCA", "GoPay", "QRIS BCA").
            $table->string('nama', 60);

            // Nomor rekening atau identitas merchant. Nullable karena QRIS
            // statis bisa hanya berupa gambar tanpa nomor yang perlu dibaca
            // manusia - dan memaksa kolom ini terisi membuat pemilik mengarang
            // angka supaya formulirnya mau tersimpan.
            $table->string('nomor', 60)->nullable();

            // Nama pemilik rekening. Penting bukan sebagai formalitas:
            // penghuni yang melihat nama berbeda dari nama pemilik kos akan
            // curiga, dan kekhawatiran itu wajar.
            $table->string('atas_nama', 80)->nullable();

            // Catatan bebas, mis. "khusus transfer, jangan ke rekening pribadi".
            $table->string('catatan', 200)->nullable();

            // Rekening yang tidak aktif tetap tersimpan tetapi tidak tampil di
            // portal. Menghapusnya akan menghilangkan jawaban atas pertanyaan
            // "dulu saya transfer ke mana?" yang ditanyakan penghuni lama.
            $table->boolean('aktif')->default(true);

            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();

            $table->index(['aktif', 'urutan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rekening');
    }
};
