<?php

namespace App\Domain\Billing;

use Carbon\CarbonInterface;

/**
 * Kalkulator tagihan — seam 1.
 *
 * Kelas ini **tidak menyentuh basis data, Eloquent, atau HTTP**. Semua masukannya
 * primitif (int, string, CarbonInterface), semua keluarannya int rupiah. Alasannya:
 * inilah satu-satunya tempat pembulatan dan penjepitan tanggal terjadi, dan dua hal
 * itu adalah sumber bug yang paling mahal di proyek ini. Kalau logikanya tercampur
 * dengan query, menguji batas 1/31 dan 31→28 Februari butuh basis data, dan tesnya
 * jadi lambat serta rapuh.
 *
 * Aturan uang: hitung eksak → bulatkan half-up → simpan hasil bulat → jumlahkan
 * baris yang sudah bulat. Jangan pernah menyimpan angka yang berbeda dari yang
 * ditampilkan.
 */
class Kalkulator
{
    /**
     * Sewa bulan pertama dengan prorata (R-01).
     *
     * `hari_tinggal` dihitung dari tanggal masuk sampai akhir bulan, dan hari
     * masuknya ikut dihitung: masuk tanggal 31 di bulan 31 hari berarti
     * hari_tinggal = 1, bukan 0.
     *
     * Titik batas yang wajib benar: hari_tinggal = hari_dalam_bulan harus
     * menghasilkan **persis** tarif penuh. Kalau hasilnya tarif − Rp 1, ada
     * pembulatan yang salah tempat.
     */
    public function hitungSewaBulanPertama(int $tarif, int $hariTinggal, int $hariDalamBulan): int
    {
        if ($hariDalamBulan <= 0) {
            throw new \InvalidArgumentException('hari_dalam_bulan harus lebih besar dari 0.');
        }

        if ($hariTinggal < 1 || $hariTinggal > $hariDalamBulan) {
            throw new \InvalidArgumentException(sprintf(
                'hari_tinggal harus di antara 1 dan %d, diterima %d.',
                $hariDalamBulan,
                $hariTinggal,
            ));
        }

        // Jalan pintas yang disengaja: sebulan penuh adalah tarif penuh, tanpa
        // pembagian sama sekali. Secara matematis tarif × n / n = tarif, tetapi
        // melewati pembagian menghilangkan seluruh kemungkinan pembulatan.
        if ($hariTinggal === $hariDalamBulan) {
            return $tarif;
        }

        return $this->bagiBulatHalfUp($tarif * $hariTinggal, $hariDalamBulan);
    }

    /**
     * Hari tinggal pada bulan masuk, termasuk hari masuk.
     */
    public function hariTinggal(CarbonInterface $tanggalMasuk): int
    {
        return $tanggalMasuk->copy()->daysInMonth - $tanggalMasuk->day + 1;
    }

    /**
     * Jatuh tempo tagihan: tanggal masuk, dijepit ke akhir bulan periode.
     *
     * "Jatuh tempo tanggal 31" di bulan Februari tidak ada. Tanpa penjepitan,
     * tanggal itu akan menggelinding ke 3 Maret dan denda dihitung dari tanggal
     * yang salah — bug yang hanya muncul beberapa kali setahun sehingga mudah
     * lolos pengujian.
     */
    public function tanggalJatuhTempo(CarbonInterface $tanggalMasuk, CarbonInterface $periode): CarbonInterface
    {
        $awalBulan = $periode->copy()->startOfMonth();

        return $awalBulan->copy()->day(min($tanggalMasuk->day, $awalBulan->daysInMonth));
    }

    /**
     * Denda keterlambatan (R-03).
     *
     * Rumusnya dijalankan **sekali jalan**, bukan diakumulasi per hari. Ini
     * bukan sekadar soal gaya: kalau denda ditambahkan tiap hari dengan hasil
     * yang sudah dibulatkan, selisih pembulatan menumpuk dan angka akhirnya
     * berbeda tergantung berapa kali fungsi dipanggil. Dihitung sekali dari
     * `hari_terlambat`, hasilnya selalu sama.
     *
     * Parameter diterima sebagai array primitif, bukan model — supaya pemanggil
     * bebas memakai snapshot dari baris `tagihan` maupun parameter terkini, dan
     * supaya fungsi ini tetap bisa diuji tanpa basis data.
     *
     * @param  array{tarif_persen: string|int|float, plafon_persen: string|int|float, masa_tenggang: int}  $parameter
     */
    public function hitungDenda(int $pokok, int $hariTerlambat, array $parameter): int
    {
        if ($pokok < 0) {
            throw new \InvalidArgumentException("Pokok tidak boleh negatif, diterima {$pokok}.");
        }

        $masaTenggang = (int) $parameter['masa_tenggang'];
        $tarifRatusan = $this->persenKeRatusan($parameter['tarif_persen']);
        $plafonRatusan = $this->persenKeRatusan($parameter['plafon_persen']);

        if ($masaTenggang < 0) {
            throw new \InvalidArgumentException("Masa tenggang tidak boleh negatif, diterima {$masaTenggang}.");
        }

        // Belum lewat tenggang, atau bahkan belum jatuh tempo: tidak ada denda.
        // `hariTerlambat` negatif berarti tagihan belum jatuh tempo sama sekali.
        if ($hariTerlambat <= $masaTenggang) {
            return 0;
        }

        $hariDenda = $hariTerlambat - $masaTenggang;

        // Denda tidak mungkin melebihi plafon, jadi hari denda tidak perlu
        // dihitung melewati titik plafon. Selain benar secara hasil, ini juga
        // mencegah perkalian meluap pada pokok besar dengan keterlambatan
        // bertahun-tahun.
        if ($tarifRatusan > 0) {
            $hariSampaiPlafon = intdiv($plafonRatusan + $tarifRatusan - 1, $tarifRatusan);
            $hariDenda = min($hariDenda, $hariSampaiPlafon);
        }

        // Persen disimpan dalam satuan seperseratus (1,00% → 100), jadi
        // pembaginya 100 × 100 = 10.000.
        $denda = $this->bagiBulatHalfUp($pokok * $hariDenda * $tarifRatusan, 10_000);
        $plafon = $this->bagiBulatHalfUp($pokok * $plafonRatusan, 10_000);

        return min($denda, $plafon);
    }

    /**
     * Batas atas denda untuk sebuah pokok, tanpa memandang keterlambatan.
     *
     * Dipisah supaya layar bisa menampilkan "denda Rp 345.000 dari maksimum
     * Rp 450.000" — angka plafon yang tidak terlihat membuat penghuni mengira
     * denda bisa naik tanpa batas.
     *
     * @param  array{tarif_persen: string|int|float, plafon_persen: string|int|float, masa_tenggang: int}  $parameter
     */
    public function plafonDenda(int $pokok, array $parameter): int
    {
        if ($pokok < 0) {
            throw new \InvalidArgumentException("Pokok tidak boleh negatif, diterima {$pokok}.");
        }

        return $this->bagiBulatHalfUp($pokok * $this->persenKeRatusan($parameter['plafon_persen']), 10_000);
    }

    /**
     * Jumlah hari denda yang sudah berjalan, tanpa memandang plafon.
     */
    public function hariDenda(int $hariTerlambat, int $masaTenggang): int
    {
        return max(0, $hariTerlambat - $masaTenggang);
    }

    /**
     * Pada hari denda ke berapa plafon tercapai.
     *
     * Untuk 1%/hari dengan plafon 30%, hasilnya 30 — artinya hari denda ke-30
     * (hari terlambat ke-37) sudah kena plafon penuh. Dipakai untuk menghitung
     * sisa hari, dan untuk menjelaskan ke pembaca tagihan kapan denda berhenti.
     *
     * @param  array{tarif_persen: string|int|float, plafon_persen: string|int|float, masa_tenggang: int}  $parameter
     */
    public function hariHinggaPlafon(array $parameter): int
    {
        $tarifRatusan = $this->persenKeRatusan($parameter['tarif_persen']);
        $plafonRatusan = $this->persenKeRatusan($parameter['plafon_persen']);

        if ($tarifRatusan <= 0) {
            // Tanpa tarif, denda tidak pernah bertambah dan plafon tidak
            // pernah tercapai. Dikembalikan sebagai 0 supaya pemanggil tidak
            // membagi dengan nol.
            return 0;
        }

        // Pembagian dibulatkan ke atas dengan aritmetika bulat: (a + b - 1) / b.
        return intdiv($plafonRatusan + $tarifRatusan - 1, $tarifRatusan);
    }

    /**
     * Pembagian bulat dengan pembulatan half-up, tanpa float.
     *
     * Memakai aritmetika bilangan bulat: sisa dibandingkan dengan setengah
     * pembagi. `round($a / $b)` akan menyentuh float dan bisa meleset satu
     * rupiah pada angka besar.
     */
    private function bagiBulatHalfUp(int $pembilang, int $penyebut): int
    {
        $hasilBagi = intdiv($pembilang, $penyebut);
        $sisa = $pembilang % $penyebut;

        // Sisa ≥ setengah pembagi → bulatkan ke atas.
        if ($sisa * 2 >= $penyebut) {
            return $hasilBagi + 1;
        }

        return $hasilBagi;
    }

    /**
     * "1.00" → 100, "30" → 3000, "1.5" → 150.
     *
     * Persentase diurai sebagai teks, bukan di-cast ke float lebih dulu. Cast
     * ke float membuat 1,15% bisa menjadi 1.1499999… dan pembulatan denda
     * meleset satu rupiah pada pokok besar.
     */
    private function persenKeRatusan(string|int|float $nilai): int
    {
        $teks = trim((string) $nilai);

        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $teks, $cocok) !== 1) {
            throw new \InvalidArgumentException(
                "Persentase harus berbentuk angka dengan paling banyak 2 desimal, diterima \"{$teks}\"."
            );
        }

        $bulat = (int) $cocok[1];
        $pecahan = str_pad($cocok[2] ?? '', 2, '0');

        return $bulat * 100 + (int) $pecahan;
    }
}
