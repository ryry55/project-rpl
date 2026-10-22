<?php

namespace App\Domain\Billing;

use App\Models\Tagihan;
use App\Support\WaktuSistem;

/**
 * Daftar tunggakan per kelompok umur (R-04).
 *
 * Aturan yang menentukan kelas ini benar atau tidak:
 *
 * 1. **Memakai `PenghitungDenda` yang sama dengan endpoint denda.** Kalau rumus
 *    denda disalin ke sini, dua layar akan menampilkan angka berbeda untuk
 *    tagihan yang sama begitu salah satunya diubah. Yang disalin cepat atau
 *    lambat akan menyimpang.
 *
 * 2. **Menyaring status di SQL, bukan setelah data diambil.** Kalau penyaringan
 *    dilakukan di PHP, `count()` dan `sum()` per kelompok akan menghitung
 *    tagihan lunas lebih dulu lalu membuangnya — dan lebih buruk, batas
 *    `LIMIT` akan terpakai oleh baris yang seharusnya tidak ada.
 *
 * 3. **Kelompok kosong tetap dikembalikan.** Pemilik perlu melihat "0 tagihan
 *    di atas 60 hari" sebagai kabar baik, bukan sebagai kelompok yang hilang.
 */
class DaftarTunggakan
{
    public function __construct(
        private readonly PenghitungDenda $penghitungDenda,
        private readonly Kalkulator $kalkulator,
        private readonly WaktuSistem $waktu,
    ) {}

    /**
     * @return array{
     *     tanggal_acuan: string,
     *     simulasi: bool,
     *     demo_mode: bool,
     *     kelompok: list<array<string, mixed>>,
     *     ringkasan: array<string, int>
     * }
     */
    public function susun(): array
    {
        $daftar = Tagihan::query()
            // R-04: tagihan lunas dan dibatalkan tidak pernah masuk tunggakan.
            ->whereNotIn('status', [Tagihan::STATUS_LUNAS, Tagihan::STATUS_DIBATALKAN])
            ->with(['kontrak.kamar', 'kontrak.penghuni'])
            ->orderBy('jatuh_tempo')
            ->orderBy('id')
            ->get();

        // Wadah disiapkan lengkap lebih dulu supaya kelompok kosong tetap
        // muncul di keluaran, dalam urutan yang tetap.
        $kelompok = [];
        foreach (UmurTunggakan::semua() as $kunci) {
            $kelompok[$kunci] = [
                'kunci' => $kunci,
                'label' => UmurTunggakan::label($kunci),
                'keterangan' => UmurTunggakan::keterangan($kunci),
                'jumlah' => 0,
                'total_pokok' => 0,
                'total_denda' => 0,
                'total' => 0,
                'tagihan' => [],
            ];
        }

        foreach ($daftar as $tagihan) {
            $denda = $this->penghitungDenda->hitung($tagihan);
            $kunci = $this->kalkulator->kelompokUmur($denda->hariTerlambat);

            $baris = [
                'id' => $tagihan->id,
                'penghuni' => $tagihan->kontrak?->penghuni?->nama,
                'kamar' => $tagihan->kontrak?->kamar?->nomor,
                'periode' => $tagihan->periode?->toDateString(),
                'jatuh_tempo' => $denda->jatuhTempo,
                'pokok' => $denda->pokok,
                'denda' => $denda->denda,
                'total' => $denda->pokok + $denda->denda,
                'hari_terlambat' => $denda->hariTerlambat,
                'hari_denda' => $denda->hariDenda,
                'status' => $denda->status,
            ];

            $kelompok[$kunci]['jumlah']++;
            $kelompok[$kunci]['total_pokok'] += $baris['pokok'];
            $kelompok[$kunci]['total_denda'] += $baris['denda'];
            $kelompok[$kunci]['total'] += $baris['total'];
            $kelompok[$kunci]['tagihan'][] = $baris;
        }

        $ringkasan = [
            'jumlah' => 0,
            'total_pokok' => 0,
            'total_denda' => 0,
            'total' => 0,
        ];

        foreach ($kelompok as $isi) {
            $ringkasan['jumlah'] += $isi['jumlah'];
            $ringkasan['total_pokok'] += $isi['total_pokok'];
            $ringkasan['total_denda'] += $isi['total_denda'];
            $ringkasan['total'] += $isi['total'];
        }

        return [
            'tanggal_acuan' => $this->waktu->hariIni()->toDateString(),
            // Diumumkan supaya layar tidak menebak. Menyimpulkan "ada tanggal
            // simulasi berarti server memakainya" salah saat APP_DEMO_MODE=false:
            // UI akan mengklaim simulasi aktif padahal angka yang tampil adalah
            // angka hari ini. Kesalahan yang sama sudah dicatat di keputusan T6.
            'simulasi' => $this->waktu->sedangSimulasi(),
            'demo_mode' => (bool) config('app.demo_mode'),
            'kelompok' => array_values($kelompok),
            'ringkasan' => $ringkasan,
        ];
    }
}
