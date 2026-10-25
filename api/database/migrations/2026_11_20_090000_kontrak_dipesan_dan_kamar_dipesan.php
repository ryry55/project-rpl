<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S1.9 (REV-FIN-04) - kontrak boleh mulai di masa depan.
 *
 * Sebelum ini, `before_or_equal:today` menolak tanggal masuk yang akan datang,
 * sehingga pemilik tidak bisa mencatat penghuni yang sudah menyanggupi kamar
 * untuk bulan depan. Pertanyaan "kamar mana yang sudah dipesan" hanya bisa
 * dijawab dari buku catatan.
 *
 * Mengizinkan tanggal masa depan saja tidak cukup, dan itu sebabnya migrasi
 * ini ada. Kontrak lama selalu langsung berstatus `aktif`, dan
 * `KontrakController` menandai kamarnya `terisi` begitu kontrak dibuat. Bila
 * tanggalnya di masa depan, kamar akan tampak terisi oleh orang yang belum
 * menempatinya - dan daftar kamar kosong di formulir kontrak akan
 * menawarkan kamar itu lagi, sehingga satu kamar bisa dipesan dua kali.
 *
 * Yang ditambahkan karena itu dua hal:
 *
 *   1. status kontrak `dipesan` - sudah disepakati, belum dimulai. Ia TIDAK
 *      dihitung sebagai kontrak aktif, sehingga tagihan tidak terbit dan
 *      penghuni tidak dianggap menempati kamar.
 *   2. status hunian kamar `dipesan` - kamar sudah dijanjikan, belum dihuni.
 *      Ia tetap dihitung "tidak tersedia" oleh formulir, tetapi tidak
 *      dilaporkan sebagai terisi.
 *
 * Keduanya diubah menjadi `aktif`/`terisi` oleh `kontrak:mulai` yang
 * dijadwalkan setiap hari (lihat routes/console.php), bukan oleh pembacaan
 * data: status yang berubah karena dibaca akan berbeda tergantung siapa yang
 * membuka layar lebih dulu.
 *
 * Partial unique index `kontrak_satu_aktif_per_kamar` DIPERLUAS ke `dipesan`,
 * bukan dibiarkan. Inilah yang mencegah satu kamar dipesan dua kali, dan ia
 * harus di tingkat basis data: pemeriksaan `exists()` di aplikasi selalu punya
 * celah balapan antara "memeriksa" dan "menulis", dan dua pemilik yang menekan
 * Simpan pada detik yang sama akan sama-sama lolos. Constraint tidak punya
 * celah itu.
 *
 * Nama indeksnya sengaja tidak diganti walau cakupannya bertambah: nama itu
 * disebut di pesan galat dan di beberapa komentar, dan mengganti nama berarti
 * memperbaiki semua sebutan itu tanpa menambah kemampuan apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Enum PostgreSQL ditambahkan lewat ALTER TYPE, bukan `->change()`:
        // Laravel tidak bisa mengubah enum tanpa doctrine/dbal, dan menambah
        // nilai di akhir tidak memerlukan penulisan ulang tabel.
        DB::statement('ALTER TABLE kontrak DROP CONSTRAINT IF EXISTS kontrak_status_check');
        DB::statement("ALTER TABLE kontrak ADD CONSTRAINT kontrak_status_check CHECK (status IN ('aktif', 'selesai', 'dipesan'))");

        DB::statement('ALTER TABLE kamar DROP CONSTRAINT IF EXISTS kamar_status_huni_check');
        DB::statement("ALTER TABLE kamar ADD CONSTRAINT kamar_status_huni_check CHECK (status_huni IN ('kosong', 'terisi', 'dipesan'))");

        // Satu kamar hanya boleh punya SATU kontrak yang belum selesai, baik
        // yang sudah berjalan maupun yang masih dipesan.
        DB::statement('DROP INDEX IF EXISTS kontrak_satu_aktif_per_kamar');
        DB::statement(
            "CREATE UNIQUE INDEX kontrak_satu_aktif_per_kamar
             ON kontrak (kamar_id)
             WHERE status IN ('aktif', 'dipesan')"
        );
    }

    public function down(): void
    {
        // Kembalikan ke keadaan semula hanya bila tidak ada baris yang memakai
        // nilai baru. Menghapus nilai tanpa memeriksa akan menggagalkan
        // constraint dan meninggalkan migrasi setengah jalan.
        DB::statement("UPDATE kontrak SET status = 'aktif' WHERE status = 'dipesan'");
        DB::statement("UPDATE kamar SET status_huni = 'kosong' WHERE status_huni = 'dipesan'");

        DB::statement('ALTER TABLE kontrak DROP CONSTRAINT IF EXISTS kontrak_status_check');
        DB::statement("ALTER TABLE kontrak ADD CONSTRAINT kontrak_status_check CHECK (status IN ('aktif', 'selesai'))");

        DB::statement('ALTER TABLE kamar DROP CONSTRAINT IF EXISTS kamar_status_huni_check');
        DB::statement("ALTER TABLE kamar ADD CONSTRAINT kamar_status_huni_check CHECK (status_huni IN ('kosong', 'terisi'))");

        DB::statement('DROP INDEX IF EXISTS kontrak_satu_aktif_per_kamar');
        DB::statement(
            "CREATE UNIQUE INDEX kontrak_satu_aktif_per_kamar
             ON kontrak (kamar_id)
             WHERE status = 'aktif'"
        );
    }
};
