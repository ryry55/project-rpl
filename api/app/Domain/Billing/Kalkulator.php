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
}
