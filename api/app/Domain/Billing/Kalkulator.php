<?php

namespace App\Domain\Billing;

use App\Domain\Kebijakan\PilihanKebijakan;
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
     * Sewa satu periode menurut satuan yang dipilih pemilik (T16).
     *
     * Ini dispatcher, bukan rumus baru: cabang `bulanan` memanggil
     * {@see hitungSewaBulanPertama} apa adanya. Memindahkan prorata ke sini
     * akan mengubah satu-satunya tempat pembulatan R-01 berada, dan tes batas
     * 17/31 yang sudah ada berhenti membuktikan apa pun tentang kode yang
     * benar-benar berjalan.
     *
     * Arti tiap satuan, dan kenapa begitu:
     *
     *   - `bulanan` — tarif adalah harga sebulan; bulan masuk diprorata menurut
     *     hari tinggal (R-01), bulan berikutnya tarif penuh.
     *   - `mingguan` — tarif adalah harga seminggu. Jumlah yang ditagih adalah
     *     tarif × hari / 7, dibulatkan half-up. Kos mingguan tetap menagih
     *     bulanan di sistem ini, karena periode tagihan selalu satu bulan;
     *     yang berubah hanya cara harga mingguan diterjemahkan ke sebulan.
     *   - `harian` — tarif adalah harga sehari; jumlahnya tarif × hari.
     *
     * Ketiganya memakai `hariDitagih` yang sama, sehingga bulan masuk tetap
     * diprorata pada satuan apa pun. Tanpa itu, penghuni yang masuk tanggal 28
     * akan ditagih sebulan penuh hanya karena kosnya mingguan.
     *
     * @param  int  $hariDitagih  hari yang ditagih pada periode ini (1..hariDalamBulan)
     * @param  int  $hariDalamBulan  jumlah hari bulan periode
     */
    public function hitungSewa(string $satuan, int $tarif, int $hariDitagih, int $hariDalamBulan): int
    {
        if ($hariDalamBulan <= 0) {
            throw new \InvalidArgumentException('hari_dalam_bulan harus lebih besar dari 0.');
        }

        if ($hariDitagih < 1 || $hariDitagih > $hariDalamBulan) {
            throw new \InvalidArgumentException(sprintf(
                'hari_ditagih harus di antara 1 dan %d, diterima %d.',
                $hariDalamBulan,
                $hariDitagih,
            ));
        }

        if ($tarif < 0) {
            throw new \InvalidArgumentException("Tarif tidak boleh negatif, diterima {$tarif}.");
        }

        return match ($satuan) {
            PilihanKebijakan::SATUAN_BULANAN => $this->hitungSewaBulanPertama(
                $tarif,
                $hariDitagih,
                $hariDalamBulan,
            ),
            // Sebulan penuh pada satuan mingguan tidak selalu bilangan bulat
            // minggu (31 hari = 4,43 minggu), jadi tidak ada jalan pintas di
            // sini: pembagiannya memang harus terjadi.
            PilihanKebijakan::SATUAN_MINGGUAN => $this->bagiBulatHalfUp($tarif * $hariDitagih, 7),
            PilihanKebijakan::SATUAN_HARIAN => $tarif * $hariDitagih,
            default => throw new \InvalidArgumentException(
                "Satuan sewa \"{$satuan}\" tidak dikenal."
            ),
        };
    }

    /**
     * Jatuh tempo menurut cara yang dipilih pemilik (T16).
     *
     * Cabang `tanggal_masuk` memanggil {@see tanggalJatuhTempo} apa adanya,
     * sehingga perilaku sebelum T16 tetap persis sama — termasuk penjepitan
     * tanggal 31 di bulan Februari, yang sudah diuji dan tidak boleh berubah.
     *
     * `tanggal_tetap` juga dijepit dengan alasan yang sama: pemilik yang
     * menagih "tanggal 31" tidak boleh mendapat jatuh tempo 3 Maret di bulan
     * Februari, karena denda akan dihitung dari tanggal yang salah.
     *
     * **Pada bulan masuk, jatuh tempo tidak boleh mendahului tanggal masuk.**
     * Kebijakan "tanggal tetap 15" berarti sewa jatuh tempo tiap tanggal 15.
     * Penghuni yang masuk tanggal 16 September karena itu akan mendapat
     * tagihan September yang jatuh temponya 15 September — sehari **sebelum**
     * ia menempati kamarnya. Tagihan itu lahir dalam keadaan sudah terlambat,
     * dan denda mulai berjalan dari tanggal ia belum berutang apa pun. Yang
     * ditagih pun bukan sebulan penuh: pokoknya sudah diprorata 15/30, jadi
     * menagihnya lebih cepat daripada porsi yang ia pakai tidak punya dasar.
     *
     * Karena itu pada bulan masuk saja, jatuh tempo dinaikkan sampai tanggal
     * masuk. Bulan-bulan berikutnya tetap persis tanggal 15. Cara
     * `tanggal_masuk` dan `akhir_bulan` tidak terpengaruh: keduanya sudah
     * menghasilkan tanggal yang tidak pernah mendahului hari masuk.
     */
    public function tanggalJatuhTempoMenurut(
        string $cara,
        CarbonInterface $periode,
        CarbonInterface $tanggalMasuk,
        ?int $tanggalTetap = null,
    ): CarbonInterface {
        $awalBulan = $periode->copy()->startOfMonth();

        $jatuhTempo = match ($cara) {
            PilihanKebijakan::JATUH_TEMPO_TANGGAL_MASUK => $this->tanggalJatuhTempo($tanggalMasuk, $periode),
            PilihanKebijakan::JATUH_TEMPO_TANGGAL_TETAP => $awalBulan->copy()->day(
                min($tanggalTetap ?? 1, $awalBulan->daysInMonth)
            ),
            PilihanKebijakan::JATUH_TEMPO_AKHIR_BULAN => $awalBulan->copy()->endOfMonth()->startOfDay(),
            default => throw new \InvalidArgumentException(
                "Cara jatuh tempo \"{$cara}\" tidak dikenal."
            ),
        };

        // Penjaga bulan masuk. Hanya berlaku bila periode tagihan adalah bulan
        // yang sama dengan bulan tanggal masuk — bulan berikutnya tidak punya
        // alasan untuk ditahan, karena penghuni memang sudah menempati kamar
        // sejak lama.
        if ($awalBulan->isSameMonth($tanggalMasuk) && $jatuhTempo->lessThan($tanggalMasuk)) {
            return $tanggalMasuk->copy()->startOfDay();
        }

        return $jatuhTempo;
    }

    /**
     * Denda satu periode menurut bentuk yang dipilih pemilik (T17).
     *
     * Ini dispatcher, bukan rumus baru: cabang `persen_harian` memanggil
     * {@see hitungDenda} apa adanya. Memindahkan rumus R-03 ke sini akan
     * mengubah satu-satunya tempat pembulatan denda berada, dan seluruh tes
     * batas hari 7/8/30/37 yang sudah ada berhenti membuktikan apa pun tentang
     * kode yang benar-benar berjalan.
     *
     * Arti tiap bentuk:
     *
     *   - `persen_harian` — persen dari pokok, dikali hari denda. Ini R-03.
     *   - `persen_bulanan` — persen dari pokok per **bulan** keterlambatan,
     *     bukan per 30 hari. Sebulan dihitung sebagai satu bulan penuh sejak
     *     jatuh tempo, karena pemilik kos berpikir "telat sebulan", bukan
     *     "telat 30 hari". Bulan berjalan dihitung sebagai bulan penuh, sama
     *     seperti sewa: masuk tanggal 28 tetap disewa sampai akhir bulan.
     *   - `nominal_tetap` — angka rupiah tetap, tidak tumbuh. Dihitung per
     *     bulan keterlambatan dengan aturan pembulatan yang sama.
     *   - `tanpa_denda` — nol, dan bukan kesalahan.
     *
     * @param  int  $hariTerlambat  negatif berarti belum jatuh tempo
     */
    public function hitungDendaMenurut(DendaTerpilih $denda, int $pokok, int $hariTerlambat): int
    {
        if ($pokok < 0) {
            throw new \InvalidArgumentException("Pokok tidak boleh negatif, diterima {$pokok}.");
        }

        // Belum lewat tenggang: tidak ada denda pada bentuk apa pun. Untuk
        // denda nominal, tenggang selalu 0 sehingga hari ke-1 sudah kena.
        if ($hariTerlambat <= $denda->masaTenggang) {
            return 0;
        }

        return match ($denda->tipe) {
            PilihanKebijakan::DENDA_TANPA_DENDA => 0,

            PilihanKebijakan::DENDA_NOMINAL_TETAP => $this->dendaNominal(
                $denda->nominal,
                $hariTerlambat - $denda->masaTenggang,
            ),

            PilihanKebijakan::DENDA_PERSEN_BULANAN => $this->dendaPersenBulanan(
                $pokok,
                $hariTerlambat - $denda->masaTenggang,
                $denda->persen,
                $denda->plafonPersen,
            ),

            PilihanKebijakan::DENDA_PERSEN_HARIAN => $this->hitungDenda($pokok, $hariTerlambat, [
                'tarif_persen' => $denda->persen,
                'plafon_persen' => $denda->plafonPersen,
                'masa_tenggang' => $denda->masaTenggang,
            ]),

            default => throw new \InvalidArgumentException(
                "Bentuk denda \"{$denda->tipe}\" tidak dikenal."
            ),
        };
    }

    /**
     * Batas atas denda untuk sebuah pokok, menurut bentuk yang dipilih.
     *
     * `null` berarti tidak ada batas atas — bukan "batasnya nol". Denda nominal
     * tidak berplafon karena angkanya tetap, dan pemanggil yang menampilkan
     * "dari maksimum Rp X" perlu tahu bedanya.
     */
    public function plafonDendaMenurut(DendaTerpilih $denda, int $pokok): ?int
    {
        if (! $denda->berplafon()) {
            return null;
        }

        return $this->plafonDenda($pokok, [
            'tarif_persen' => $denda->persen,
            'plafon_persen' => $denda->plafonPersen,
            'masa_tenggang' => $denda->masaTenggang,
        ]);
    }

    /**
     * Jumlah bulan keterlambatan, dibulatkan ke atas.
     *
     * Keterlambatan 1 hari sudah dihitung satu bulan, karena pemilik kos
     * menagih "telat sebulan" untuk keterlambatan sekecil apa pun. Dihitung
     * dengan aritmetika bulat: (hari + 29) / 30 tidak dipakai karena bulan
     * tidak selalu 30 hari; yang dipakai adalah pembagian ke atas terhadap 30
     * sebagai panjang bulan rata-rata — dan itu didokumentasikan sebagai
     * keputusan, bukan kebetulan.
     */
    public function bulanTerlambat(int $hariDenda): int
    {
        if ($hariDenda <= 0) {
            return 0;
        }

        return intdiv($hariDenda + 29, 30);
    }

    /**
     * Denda persen per bulan, dengan plafon.
     */
    private function dendaPersenBulanan(int $pokok, int $hariDenda, string $persen, string $plafonPersen): int
    {
        $bulan = $this->bulanTerlambat($hariDenda);
        $tarifRatusan = $this->persenKeRatusan($persen);
        $plafonRatusan = $this->persenKeRatusan($plafonPersen);

        $denda = $this->bagiBulatHalfUp($pokok * $bulan * $tarifRatusan, 10_000);
        $plafon = $this->bagiBulatHalfUp($pokok * $plafonRatusan, 10_000);

        return min($denda, $plafon);
    }

    /**
     * Denda nominal tetap per bulan keterlambatan.
     */
    private function dendaNominal(int $nominal, int $hariDenda): int
    {
        if ($nominal < 0) {
            throw new \InvalidArgumentException("Nominal denda tidak boleh negatif, diterima {$nominal}.");
        }

        return $nominal * $this->bulanTerlambat($hariDenda);
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
     * Kelompok umur tunggakan (R-04).
     *
     * Hari terlambat diterima sebagai argumen, **tidak** dibaca dari `now()`.
     * Itu bukan soal kerapian: kalau fungsi ini memanggil jam sendiri, ia
     * menjadi bergantung pada waktu dan tidak bisa diuji pada titik batas tanpa
     * menggeser jam global. Pemanggil — yang sudah punya `WaktuSistem` — yang
     * menghitung hari terlambat, sehingga `X-Simulasi-Tanggal` dan tes berjalan
     * lewat jalur yang sama.
     *
     * Batasnya inklusif di kedua ujung: hari ke-30 masih kelompok 0-30, hari
     * ke-31 sudah 31-60. Pergeseran satu hari di sini berarti pemilik menagih
     * orang di kelompok yang salah.
     */
    public function kelompokUmur(int $hariTerlambat): string
    {
        // Negatif berarti tagihan belum jatuh tempo. Ini kelompok tersendiri,
        // bukan 0-30: orang yang belum waktunya bayar bukan penunggak.
        if ($hariTerlambat < 0) {
            return UmurTunggakan::BELUM_JATUH_TEMPO;
        }

        if ($hariTerlambat <= 30) {
            return UmurTunggakan::HARI_0_30;
        }

        if ($hariTerlambat <= 60) {
            return UmurTunggakan::HARI_31_60;
        }

        return UmurTunggakan::LEBIH_60;
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
