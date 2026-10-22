<?php

namespace App\Domain\Billing;

use App\Models\Tagihan;
use App\Support\WaktuSistem;
use Carbon\CarbonImmutable;

/**
 * Menghitung denda sebuah tagihan pada "hari ini".
 *
 * Tiga keputusan yang menentukan kebenaran angka di sini:
 *
 * 1. **Parameter dibaca dari baris `tagihan`, bukan dari tabel `parameter_denda`.**
 *    Itu inti R-06 lapis 2. Kalau membaca parameter terkini, mengubah tarif denda
 *    bulan depan akan mengubah denda tagihan bulan lalu — persis yang dilarang.
 *
 * 2. **Dasarnya `pokok` yang dibekukan, bukan sisa tagihan.** Penghuni yang sudah
 *    membayar sebagian tetap dikenai denda dari pokok penuh. Kalau dasarnya sisa,
 *    penghuni bisa menurunkan denda dengan membayar Rp 1 — dan itu bukan
 *    perilaku yang diinginkan pemilik kos.
 *
 * 3. **Dihitung sekali jalan** dari `hari_terlambat`, bukan diakumulasi per hari.
 *    Akumulasi harian dengan pembulatan per hari menumpuk selisih.
 */
class PenghitungDenda
{
    public function __construct(
        private readonly Kalkulator $kalkulator,
        private readonly WaktuSistem $waktu,
    ) {}

    public function hitung(Tagihan $tagihan): HasilDenda
    {
        $acuan = $this->waktu->hariIni();
        $jatuhTempo = CarbonImmutable::parse($tagihan->jatuh_tempo)->startOfDay();
        $hariTerlambat = $this->waktu->selisihHari($jatuhTempo, $acuan);

        $parameter = [
            'tarif_persen' => (string) $tagihan->tarif_denda_persen,
            'plafon_persen' => (string) $tagihan->plafon_persen,
            'masa_tenggang' => (int) $tagihan->masa_tenggang,
        ];

        $pokok = (int) $tagihan->pokok;
        $plafon = $this->kalkulator->plafonDenda($pokok, $parameter);

        // Tagihan yang sudah lunas atau dibatalkan tidak berjalan terus. Tanpa
        // pemeriksaan ini, tagihan yang dibayar pada hari ke-100 akan tetap
        // menampilkan denda 30% — dan itu membuat penghuni yang sudah membayar
        // terlihat masih berutang.
        $alasanBerhenti = match ($tagihan->status) {
            Tagihan::STATUS_LUNAS => 'Tagihan sudah lunas.',
            Tagihan::STATUS_DIBATALKAN => 'Tagihan dibatalkan.',
            default => '',
        };

        if ($alasanBerhenti !== '') {
            return new HasilDenda(
                pokok: $pokok,
                jatuhTempo: $jatuhTempo->toDateString(),
                tanggalAcuan: $acuan->toDateString(),
                hariTerlambat: $hariTerlambat,
                masaTenggang: $parameter['masa_tenggang'],
                hariDenda: 0,
                denda: 0,
                plafon: $plafon,
                sisaHariKePlafon: 0,
                status: (string) $tagihan->status,
                berhenti: true,
                alasanBerhenti: $alasanBerhenti,
            );
        }

        $denda = $this->kalkulator->hitungDenda($pokok, $hariTerlambat, $parameter);
        $hariDenda = $this->kalkulator->hariDenda($hariTerlambat, $parameter['masa_tenggang']);

        // Sisa hari sampai plafon hanya bermakna selama denda masih bertambah.
        $hariHinggaPlafon = $this->kalkulator->hariHinggaPlafon($parameter);
        $sisaHari = $hariHinggaPlafon > 0
            ? max(0, $hariHinggaPlafon - $hariDenda)
            : 0;

        return new HasilDenda(
            pokok: $pokok,
            jatuhTempo: $jatuhTempo->toDateString(),
            tanggalAcuan: $acuan->toDateString(),
            hariTerlambat: $hariTerlambat,
            masaTenggang: $parameter['masa_tenggang'],
            hariDenda: $hariDenda,
            denda: $denda,
            plafon: $plafon,
            sisaHariKePlafon: $sisaHari,
            status: (string) $tagihan->status,
            berhenti: false,
            alasanBerhenti: '',
        );
    }
}
