<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REV-DAT-04 - indeks gabungan `(kontrak_id, status)` pada `tagihan`.
 *
 * Satu-satunya indeks yang menyentuh `status` hari ini adalah
 * `(status, jatuh_tempo)`, dan itu tidak menolong dua query yang paling sering
 * dijalankan aplikasi ini:
 *
 *   - `Tagihan::where('kontrak_id', $id)->where('status', '<>', 'dibatalkan')`
 *     - dipanggil setiap kali satu kontrak ditagih, dan sejak REV-FIN-02 juga
 *       setiap kali penerbitan massal memeriksa duplikat;
 *   - `Tagihan::whereIn('kontrak_id', $sub)->whereIn('status', [...])`
 *     - dipanggil `LayananPdp::alasanTidakBolehHapus` dan daftar tunggakan
 *       per penghuni.
 *
 * Keduanya menyaring `kontrak_id` LEBIH DULU, dan tanpa indeks yang dimulai
 * dengan kolom itu PostgreSQL memilih `(status, jatuh_tempo)` lalu memindai
 * seluruh baris berstatus sama - untuk kos 30 kamar itu berarti 30x lipat
 * pekerjaan yang seharusnya, dan biayanya tumbuh seiring jumlah bulan yang
 * pernah ditagih.
 *
 * **Urutan kolomnya `(kontrak_id, status)`, bukan sebaliknya.** Kolom pertama
 * harus kolom yang dipakai dengan kesamaan paling selektif: satu kontrak punya
 * puluhan baris, sedangkan satu status punya ratusan. Indeks `(status,
 * kontrak_id)` akan menyaring ke ratusan baris lebih dulu.
 *
 * Indeks `(status, jatuh_tempo)` **tidak dihapus**. Ia melayani query yang
 * berbeda - daftar tunggakan menyaring status lalu mengurutkan jatuh tempo -
 * dan menggantinya dengan indeks baru akan memindahkan masalahnya, bukan
 * menghilangkannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tagihan', function (Blueprint $table) {
            $table->index(['kontrak_id', 'status'], 'tagihan_kontrak_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('tagihan', function (Blueprint $table) {
            $table->dropIndex('tagihan_kontrak_status_index');
        });
    }
};
