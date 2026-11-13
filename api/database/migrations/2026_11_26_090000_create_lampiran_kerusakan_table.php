<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto lampiran laporan kerusakan (K-15).
 *
 * **Kenapa tabel anak, bukan kolom di `laporan_kerusakan`.** Satu laporan
 * membawa sampai tiga foto, dan kolom berisi tiga gambar berarti tiga kolom
 * `bytea` yang separuhnya selalu kosong - atau satu kolom JSON yang membuat
 * ukuran dan jenis tiap foto tidak bisa diperiksa basis data. Tabel anak membuat
 * jumlahnya dibatasi baris, bukan oleh bentuk kolom.
 *
 * **Kenapa `bytea` di basis data, bukan berkas di `storage/`.** Sama seperti
 * gambar QRIS: berkas yang ditulis ke `storage/` hilang saat container dibangun
 * ulang, karena volume itu memang tidak dipasang. Foto kerusakan yang hilang
 * adalah bukti yang hilang, dan laporan yang fotonya lenyap lebih buruk daripada
 * laporan yang tidak pernah punya foto. `bytea` ikut transaksi, ikut rollback,
 * dan ikut cadangan basis data yang sudah ada.
 *
 * Kolom `isi` sengaja **tidak** dibaca lewat `SELECT *`: `LampiranKerusakan`
 * mengeluarkannya dari `$fillable` dan `PenyimpanLampiran` membacanya dengan
 * kueri tersendiri, supaya daftar laporan tidak menarik megabyte gambar yang
 * tidak ditampilkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lampiran_kerusakan', function (Blueprint $table) {
            $table->id();

            // Laporan yang dihapus membawa lampirannya. Saat ini tidak ada
            // jalur yang menghapus laporan, tetapi lampiran yang tertinggal
            // tanpa induk tidak bisa ditemukan siapa pun - jadi CASCADE, bukan
            // RESTRICT yang akan menggagalkan pembersihan kelak.
            $table->foreignId('laporan_kerusakan_id')
                ->constrained('laporan_kerusakan')
                ->cascadeOnDelete();

            // Nama asli hanya untuk keterangan di layar; tidak pernah dipakai
            // sebagai jalur berkas, jadi tidak ada yang bisa disusupi lewat
            // nama ini. Boleh kosong bila klien tidak mengirimnya.
            $table->string('nama_asli', 160)->nullable();

            // Jenis yang sudah diperiksa dari isi berkas, bukan dari nama atau
            // Content-Type kiriman.
            $table->string('mime', 60);

            // Disimpan terpisah supaya daftar lampiran bisa menampilkan
            // "1,2 MB" tanpa menarik isinya.
            $table->unsignedInteger('ukuran');

            $table->binary('isi');

            $table->timestamps();

            $table->index('laporan_kerusakan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lampiran_kerusakan');
    }
};
