<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Backup database ke berkas SQL di dalam container.
 *
 * Kenapa perintah artisan, bukan hanya `docker/backup.sh`: skrip shell itu
 * memanggil `pg_dump` dan `psql`, yang tidak tersedia di image produksi satu
 * layanan (image itu hanya memasang `postgresql16-client` untuk kebutuhan
 * lain). Perintah ini memakai `pg_dump` bila ada, dan jatuh ke dump berbasis
 * PHP bila tidak - supaya jadwal backup tidak pernah "berhasil" tanpa
 * menghasilkan berkas.
 *
 * **Batas yang harus dibaca operator:** di Render free tidak ada disk
 * persisten. Backup ini hilang saat container restart. Ia berguna untuk
 * pemulihan cepat dalam satu masa hidup container, bukan sebagai arsip.
 * Arsip sungguhan harus diunduh keluar; lihat docs/produk/8_OPERATIONS_SOP.md.
 */
class BackupDatabase extends Command
{
    protected $signature = 'kos:backup
                            {--tujuan= : Direktori tujuan, bawaan storage/backups}
                            {--simpan-hari=14 : Hapus backup lebih tua dari N hari}';

    protected $description = 'Backup database ke berkas SQL dan bersihkan backup lama';

    public function handle(): int
    {
        $tujuan = $this->option('tujuan') ?: storage_path('backups');
        $simpan = (int) $this->option('simpan-hari');

        File::ensureDirectoryExists($tujuan);

        $cap = now('Asia/Jakarta')->format('Ymd\THis');
        $berkas = rtrim($tujuan, '/').'/kos-'.$cap.'.sql';

        $this->info('Menulis '.$berkas.' ...');

        $berhasil = $this->pgDump($berkas) || $this->dumpPhp($berkas);

        if (! $berhasil) {
            $this->error('GAGAL: tidak ada cara dump yang berhasil.');

            return self::FAILURE;
        }

        // Berkas nol byte adalah kegagalan yang paling berbahaya: ia terlihat
        // seperti backup, berumur benar, dan baru ketahuan rusak saat
        // dibutuhkan.
        if (! File::exists($berkas) || File::size($berkas) === 0) {
            $this->error('GAGAL: berkas backup kosong.');
            File::delete($berkas);

            return self::FAILURE;
        }

        $this->info('Selesai: '.File::size($berkas).' byte');

        $this->bersihkan($tujuan, $simpan);

        return self::SUCCESS;
    }

    /**
     * `pg_dump` bila tersedia. Ini jalur yang benar: hasilnya bisa dipulihkan
     * dengan `psql -f`, dan `--clean --if-exists` membuatnya bisa ditimpakan
     * ke database yang sudah berisi.
     */
    private function pgDump(string $berkas): bool
    {
        if (trim((string) shell_exec('command -v pg_dump 2>/dev/null')) === '') {
            return false;
        }

        $perintah = sprintf(
            'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -d %s --clean --if-exists --no-owner --no-privileges -f %s 2>&1',
            escapeshellarg((string) config('database.connections.pgsql.password')),
            escapeshellarg((string) config('database.connections.pgsql.host')),
            escapeshellarg((string) config('database.connections.pgsql.port')),
            escapeshellarg((string) config('database.connections.pgsql.username')),
            escapeshellarg((string) config('database.connections.pgsql.database')),
            escapeshellarg($berkas),
        );

        exec($perintah, $keluaran, $kode);

        if ($kode !== 0) {
            $this->warn('pg_dump gagal (kode '.$kode.'), mencoba dump berbasis PHP.');

            return false;
        }

        return true;
    }

    /**
     * Jalur cadangan: tulis INSERT per baris memakai koneksi PDO yang sudah
     * dipakai aplikasi. Tidak selengkap `pg_dump` (tidak ada urutan DDL), jadi
     * hasilnya hanya untuk memulihkan DATA ke skema yang sudah dimigrasi.
     */
    private function dumpPhp(string $berkas): bool
    {
        $this->warn('pg_dump tidak tersedia; menulis dump data berbasis PHP.');

        $tabel = collect(DB::select(
            "select tablename from pg_tables where schemaname = 'public' order by tablename"
        ))->pluck('tablename');

        $pegangan = fopen($berkas, 'w');

        if ($pegangan === false) {
            return false;
        }

        fwrite($pegangan, '-- Dump data Kos-Kosan, dibuat '.now('Asia/Jakarta')->toDateTimeString()." WIB\n");
        fwrite($pegangan, "-- Tanpa DDL. Pulihkan ke skema yang sudah dimigrasi.\n\n");

        foreach ($tabel as $nama) {
            $baris = DB::table($nama)->get();

            if ($baris->isEmpty()) {
                continue;
            }

            fwrite($pegangan, "-- Tabel: {$nama} (".$baris->count()." baris)\n");

            foreach ($baris as $satu) {
                $nilai = collect((array) $satu)->map(function ($v) {
                    if ($v === null) {
                        return 'NULL';
                    }
                    if (is_bool($v)) {
                        return $v ? 'true' : 'false';
                    }
                    if (is_int($v) || is_float($v)) {
                        return (string) $v;
                    }

                    return DB::getPdo()->quote((string) $v);
                })->implode(', ');

                $kolom = implode(', ', array_map(
                    fn ($k) => '"'.str_replace('"', '""', $k).'"',
                    array_keys((array) $satu),
                ));

                fwrite($pegangan, "INSERT INTO \"{$nama}\" ({$kolom}) VALUES ({$nilai});\n");
            }

            fwrite($pegangan, "\n");
        }

        fclose($pegangan);

        return true;
    }

    private function bersihkan(string $tujuan, int $hari): void
    {
        $batas = now()->subDays($hari)->getTimestamp();

        foreach (File::glob(rtrim($tujuan, '/').'/kos-*.sql') as $lama) {
            if (File::lastModified($lama) < $batas) {
                File::delete($lama);
                $this->line('  dihapus: '.basename($lama));
            }
        }
    }
}
