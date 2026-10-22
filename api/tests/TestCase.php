<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->pastikanBukanDatabaseDemo();
    }

    /**
     * Pagar keselamatan: tes memakai RefreshDatabase, yang menghapus seluruh
     * tabel. Kalau konfigurasi salah dan tes menunjuk ke database demo, data
     * untuk sidang hilang tanpa peringatan — ini pernah terjadi sekali, dan
     * gejalanya menyesatkan (login gagal padahal kode benar).
     *
     * Lebih baik tes gagal keras dengan pesan jelas daripada menghapus data.
     */
    private function pastikanBukanDatabaseDemo(): void
    {
        $database = config('database.connections.'.config('database.default').'.database');

        if (getenv('IZINKAN_DB_DEMO') === '1') {
            return;
        }

        if (! str_ends_with((string) $database, '_test')) {
            throw new RuntimeException(
                "Tes menolak berjalan pada database [{$database}]. ".
                'Nama database pengujian harus berakhiran [_test]. '.
                'Periksa DB_DATABASE di phpunit.xml.'
            );
        }
    }
}
