<?php

namespace App\Console\Commands;

use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\LaporanKerusakan;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mulai kontrak yang tanggal masuknya sudah tiba (S1.9).
 *
 * Sejak kontrak boleh bertanggal masa depan, ada satu keadaan yang tidak bisa
 * diselesaikan saat kontrak dibuat: kontrak `dipesan` harus menjadi `aktif`
 * pada suatu hari, tanpa ada orang yang menekan tombol.
 *
 * **Kenapa perintah terjadwal, bukan status yang dihitung saat dibaca.**
 * Alternatifnya adalah memperlakukan `dipesan` sebagai "aktif bila tgl_masuk
 * <= hari ini" di setiap query. Itu terlihat lebih sederhana, tetapi membuat
 * jawaban bergantung pada jam mesin yang membaca: laporan yang dicetak pukul
 * 23:59 dan 00:01 akan berbeda untuk data yang sama, dan `PenerbitanMassal`
 * yang berjalan tanggal 1 pukul 00:10 akan menagih atau tidak menagih
 * tergantung urutan. Menyimpan statusnya membuat satu baris punya satu makna.
 *
 * Perintahnya idempoten: kontrak yang sudah `aktif` tidak disentuh, jadi
 * menjalankannya berkali-kali aman.
 */
class MulaiKontrak extends Command
{
    protected $signature = 'kontrak:mulai
                            {--tanggal= : Tanggal acuan (YYYY-MM-DD), bawaan hari ini WIB}
                            {--uji : Tampilkan yang akan diubah tanpa menyimpan}';

    protected $description = 'Ubah kontrak yang sudah jatuh tanggal masuknya dari dipesan menjadi aktif';

    public function handle(): int
    {
        $tanggal = $this->option('tanggal')
            ? Carbon::parse($this->option('tanggal'), 'Asia/Jakarta')->startOfDay()
            : now('Asia/Jakarta')->startOfDay();

        $daftar = Kontrak::query()
            ->where('status', 'dipesan')
            ->whereDate('tgl_masuk', '<=', $tanggal->toDateString())
            ->with('kamar')
            ->get();

        if ($daftar->isEmpty()) {
            $this->info('Tidak ada kontrak yang perlu dimulai pada '.$tanggal->toDateString().'.');

            return self::SUCCESS;
        }

        if ($this->option('uji')) {
            foreach ($daftar as $kontrak) {
                $this->line(sprintf(
                    '  akan dimulai: kontrak #%d, kamar %s, tgl masuk %s',
                    $kontrak->id,
                    $kontrak->kamar?->nomor ?? '-',
                    $kontrak->tgl_masuk->toDateString(),
                ));
            }

            return self::SUCCESS;
        }

        $jumlah = DB::transaction(function () use ($daftar) {
            $jumlah = 0;

            foreach ($daftar as $kontrak) {
                // Diperiksa ulang di dalam transaksi: bila ada kontrak lain
                // yang lebih dulu menjadi aktif untuk kamar yang sama - keadaan
                // yang seharusnya tidak terjadi karena indeks unik, tetapi bisa
                // muncul dari data lama - yang ini dilewati, bukan dipaksa.
                // Memaksa akan menggagalkan seluruh transaksi dan membuat
                // kontrak lain ikut batal.
                $sudahAda = Kontrak::query()
                    ->where('kamar_id', $kontrak->kamar_id)
                    ->where('status', 'aktif')
                    ->whereKeyNot($kontrak->id)
                    ->exists();

                if ($sudahAda) {
                    $this->warn(sprintf(
                        '  dilewati: kontrak #%d (kamar %s sudah punya kontrak aktif)',
                        $kontrak->id,
                        $kontrak->kamar?->nomor ?? '-',
                    ));

                    continue;
                }

                $kontrak->update(['status' => 'aktif']);

                // Kamar yang sedang diperbaiki TETAP `perbaikan` (T14).
                // Kontraknya tetap aktif dan tetap ditagih - perbaikan bukan
                // alasan menghentikan sewa - tetapi kamarnya belum siap
                // dihuni, dan status `terisi` akan menyembunyikan itu.
                $sedangDiperbaiki = LaporanKerusakan::where('kamar_id', $kontrak->kamar_id)
                    ->whereNull('selesai_pada')
                    ->exists();

                Kamar::whereKey($kontrak->kamar_id)->update([
                    'status_huni' => $sedangDiperbaiki ? 'perbaikan' : 'terisi',
                ]);
                $jumlah++;
            }

            return $jumlah;
        });

        $this->info('Kontrak dimulai: '.$jumlah.' dari '.$daftar->count().'.');

        return self::SUCCESS;
    }
}
