<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REV-DAT-01 - cascade ke tabel append-only diledakkan trigger-nya sendiri.
 *
 * Tiga tabel yang menyimpan riwayat tarif dan kebijakan menunjuk `kamar`
 * dengan `ON DELETE CASCADE`. Itu tidak pernah bisa berjalan: setiap tabel di
 * antaranya punya trigger `BEFORE DELETE` yang menolak penghapusan, sehingga
 * `DELETE FROM kamar` tidak menghasilkan cascade yang bersih melainkan
 * `ERROR: Tabel tarif_sewa bersifat append-only (R-06)`. Pesan itu menyebut
 * tabel yang tidak disebut pemanggil, dan pemilik yang menekan "hapus kamar"
 * tidak bisa menyimpulkan apa pun darinya.
 *
 * **Kenapa `RESTRICT`, bukan membiarkan cascade.** `RESTRICT` membuat
 * kegagalan terjadi lebih awal dan lebih jujur: basis data menolak sebelum
 * trigger sempat menyala, dan pesannya menyebut kamar, bukan tabel riwayat.
 * Yang lebih penting, cascade pada tabel append-only adalah janji yang bohong
 * - ia menyatakan "menghapus kamar akan membersihkan riwayatnya", padahal yang
 * terjadi adalah transaksi gagal di tengah jalan.
 *
 * **Kenapa riwayat tidak boleh ikut terhapus.** Kalau trigger-nya dilonggarkan
 * supaya cascade bisa jalan, maka menghapus satu kamar akan menghapus riwayat
 * tarifnya - dan pertanyaan "tarifnya dulu berapa" menjadi tidak bisa dijawab.
 * Itu persis yang ingin dicegah R-06.
 *
 * Kamar yang punya riwayat tarif karena itu **tidak bisa dihapus** - dan itu
 * jawaban yang benar. Kamar yang salah ketik nomornya diperbaiki lewat
 * `PUT /kamar/{kamar}`, bukan dihapus.
 *
 * `kamar_fasilitas` sengaja TIDAK diubah: ia bukan tabel riwayat, tidak punya
 * trigger append-only, dan pemasangan fasilitas memang wajar ikut hilang
 * bersama kamarnya. `kontrak` sudah `RESTRICT` sejak awal.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tabel = ['tarif_sewa', 'komponen_biaya', 'kebijakan_kos'];

    public function up(): void
    {
        foreach ($this->tabel as $tabel) {
            // Dilepas lalu dipasang ulang: PostgreSQL tidak punya
            // `ALTER CONSTRAINT ... ON DELETE`. Nama constraint-nya mengikuti
            // konvensi Laravel (`{tabel}_{kolom}_foreign`) dan sudah diperiksa
            // di `pg_constraint` sebelum migrasi ini ditulis.
            Schema::table($tabel, function (Blueprint $table) {
                $table->dropForeign(['kamar_id']);
            });

            Schema::table($tabel, function (Blueprint $table) {
                $table->foreign('kamar_id')->references('id')->on('kamar')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tabel as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->dropForeign(['kamar_id']);
            });

            Schema::table($tabel, function (Blueprint $table) {
                $table->foreign('kamar_id')->references('id')->on('kamar')->cascadeOnDelete();
            });
        }
    }
};
