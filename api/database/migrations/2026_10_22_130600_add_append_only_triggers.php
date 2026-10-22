<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Penegakan append-only untuk tabel riwayat (R-06).
 *
 * Ditegakkan dengan trigger di basis data, bukan hanya dengan "tidak ada
 * endpoint DELETE di aplikasi". Alasannya: R-06 harus tetap benar walau
 * seseorang menjalankan SQL langsung, dan bukti bahwa riwayat tidak bisa
 * diubah adalah bukti yang bisa ditunjukkan ke penguji.
 *
 * Trigger dipilih daripada REVOKE karena REVOKE tidak berlaku bagi superuser
 * atau pemilik tabel — termasuk user yang dipakai saat pengujian, sehingga
 * tesnya bisa lolos secara palsu.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tabel = ['tarif_sewa', 'komponen_biaya', 'parameter_denda'];

    public function up(): void
    {
        foreach ($this->tabel as $tabel) {
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION tolak_ubah_{$tabel}() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION
                        'Tabel {$tabel} bersifat append-only (R-06): % tidak diizinkan. Tambahkan baris baru dengan berlaku_dari yang baru.',
                        TG_OP;
                END;
                $$ LANGUAGE plpgsql;
            SQL);

            DB::unprepared(<<<SQL
                CREATE TRIGGER {$tabel}_append_only
                BEFORE UPDATE OR DELETE ON {$tabel}
                FOR EACH ROW EXECUTE FUNCTION tolak_ubah_{$tabel}();
            SQL);
        }
    }

    public function down(): void
    {
        foreach ($this->tabel as $tabel) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$tabel}_append_only ON {$tabel};");
            DB::unprepared("DROP FUNCTION IF EXISTS tolak_ubah_{$tabel}();");
        }
    }
};
