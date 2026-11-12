<?php

namespace App\Console\Commands;

use App\Domain\Billing\PenerbitanMassal;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Terbitkan tagihan sebulan untuk semua kontrak aktif.
 *
 * Ada sebagai perintah, bukan hanya sebagai endpoint, karena penerbitan tagihan
 * bulanan adalah pekerjaan terjadwal — bukan sesuatu yang seharusnya bergantung
 * pada ada tidaknya orang membuka halaman. Endpoint dan perintah memanggil
 * layanan yang sama, jadi keduanya tidak bisa menyimpang.
 *
 * Sejak K-14 perintah ini dijalankan **harian**, bukan hanya tanggal 1: pola
 * anniversary memulai siklus pada tanggal masuk kontrak, yang bisa tanggal
 * berapa pun. `--hari-ini` adalah tanggal acuan yang membuat siklus yang belum
 * tiba dilewati; tanpa opsi itu (jalur manual) pemilik tetap boleh menerbitkan
 * lebih awal.
 *
 * `--keluaran=json` dipakai uji paralel untuk membaca hasil tiap proses.
 */
class TerbitkanTagihanMassal extends Command
{
    protected $signature = 'tagihan:terbitkan
                            {periode : Periode tagihan, format YYYY-MM-DD}
                            {--hari-ini= : Tanggal acuan (YYYY-MM-DD); siklus yang belum tiba dilewati}
                            {--keluaran=teks : teks atau json}';

    protected $description = 'Terbitkan tagihan untuk semua kontrak aktif pada satu periode';

    public function handle(PenerbitanMassal $penerbitan): int
    {
        $periode = Carbon::parse($this->argument('periode'));

        $hariIni = $this->option('hari-ini')
            ? Carbon::parse($this->option('hari-ini'), 'Asia/Jakarta')->startOfDay()
            : null;

        $hasil = $penerbitan->jalankan($periode, $hariIni);

        if ($this->option('keluaran') === 'json') {
            $this->line(json_encode($hasil, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf('Periode %s', $hasil['periode']));
        $this->line(sprintf('  terbit   : %d', $hasil['terbit']));
        $this->line(sprintf('  dilewati : %d', $hasil['dilewati']));
        $this->line(sprintf('  gagal    : %d', $hasil['gagal']));

        foreach ($hasil['rincian'] as $baris) {
            if ($baris['status'] === PenerbitanMassal::GAGAL) {
                $this->warn(sprintf(
                    '  ! %s (%s): %s',
                    $baris['penghuni'],
                    $baris['kamar'],
                    $baris['alasan'],
                ));
            }
        }

        return self::SUCCESS;
    }
}
