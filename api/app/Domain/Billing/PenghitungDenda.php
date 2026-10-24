<?php

namespace App\Domain\Billing;

use App\Domain\Kebijakan\PilihanKebijakan;
use App\Models\Tagihan;
use App\Support\WaktuSistem;
use Carbon\CarbonImmutable;

/**
 * Menghitung denda sebuah tagihan pada "hari ini".
 *
 * Empat keputusan yang menentukan kebenaran angka di sini:
 *
 * 1. **Bentuk denda dibaca dari `tagihan.kebijakan_terpakai`, bukan dari
 *    `kebijakan_kos` terkini.** Itu inti R-06 lapis 2, dan sejak T17 ini
 *    satu-satunya lapis yang tersisa: denda adalah angka yang dihitung ulang
 *    setiap kali dibaca, jadi ia satu-satunya nilai di sistem ini yang benar-
 *    benar bergerak mengikuti waktu. Kalau bentuknya dibaca dari kebijakan
 *    terkini, pemilik yang menaikkan denda bulan depan akan mengubah denda
 *    tagihan bulan lalu.
 *
 * 2. **Dasarnya `pokok` yang dibekukan, bukan sisa tagihan.** Penghuni yang
 *    sudah membayar sebagian tetap dikenai denda dari pokok penuh. Kalau
 *    dasarnya sisa, penghuni bisa menurunkan denda dengan membayar Rp 1 — dan
 *    itu bukan perilaku yang diinginkan pemilik kos.
 *
 *    **Kecuali pokoknya sudah lunas seluruhnya** (REV-FIN-07). Begitu pokok
 *    terbayar penuh dan hanya denda yang tersisa, denda berhenti berjalan.
 *    Sebelumnya ia terus bertambah sampai plafon meski pokoknya sudah dibayar —
 *    dan itu bukan lagi menagih keterlambatan, itu menagih sesuatu yang sudah
 *    dibayar. Batasnya sengaja "pokok lunas", bukan "sudah ada pembayaran":
 *    yang kedua akan membuka jalan menurunkan denda dengan membayar Rp 1.
 *
 * 3. **Dihitung sekali jalan** dari `hari_terlambat`, bukan diakumulasi per
 *    hari. Akumulasi harian dengan pembulatan per hari menumpuk selisih.
 *
 * 4. **Tagihan lama tetap terbaca.** Tagihan yang terbit sebelum T17 tidak
 *    punya `kebijakan_terpakai`; untuk itu bentuknya disusun dari tiga kolom
 *    lama (`tarif_denda_persen`, `plafon_persen`, `masa_tenggang`) yang masih
 *    tersimpan di barisnya. Tanpa jalan fallback ini, seluruh tagihan lama
 *    akan menampilkan denda nol — angka yang salah, bukan angka yang kosong.
 *
 * 5. **Pemaafan mengurangi denda, bukan menghapusnya dari riwayat**
 *    (REV-FIN-07). `denda_dimaafkan` dikurangkan dari hasil hitung, dan
 *    `denda_sebelum_maaf` tetap dikirim supaya layar bisa menulis "denda Rp
 *    450.000, dimaafkan Rp 450.000". Menampilkan nol tanpa menyebut
 *    pemaafannya akan membuat penghuni mengira dendanya tidak pernah ada.
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

        $denda = $this->bentukDenda($tagihan);

        $pokok = (int) $tagihan->pokok;
        $plafon = $this->kalkulator->plafonDendaMenurut($denda, $pokok);
        $dimaafkan = (int) $tagihan->denda_dimaafkan;

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
            // Denda yang berhenti bukan denda yang dimaafkan: pemaafan tetap
            // dilaporkan apa adanya, karena ia keputusan sadar pemilik dan
            // menghilangkannya dari layar akan menyembunyikan fakta itu.
            return new HasilDenda(
                tipe: $denda->tipe,
                pokok: $pokok,
                jatuhTempo: $jatuhTempo->toDateString(),
                tanggalAcuan: $acuan->toDateString(),
                hariTerlambat: $this->waktu->selisihHari($jatuhTempo, $acuan),
                masaTenggang: $denda->masaTenggang,
                hariDenda: 0,
                denda: 0,
                dendaSebelumMaaf: 0,
                dimaafkan: $dimaafkan,
                plafon: $plafon,
                sisaHariKePlafon: null,
                status: (string) $tagihan->status,
                berhenti: true,
                alasanBerhenti: $alasanBerhenti,
            );
        }

        // REV-FIN-07: denda berhenti bertambah pada hari pokoknya lunas.
        //
        // Sebelum ini denda berjalan sampai plafon meski pokoknya sudah dibayar
        // penuh, dan itu menagih keterlambatan atas uang yang sudah diterima.
        // Denda **dibekukan** pada tanggal itu, bukan dinolkan: menolkannya akan
        // memberi hadiah kepada penghuni yang menunda pokok 100 hari — ia
        // membayar pokoknya, lalu seluruh dendanya lenyap.
        //
        // `min()` dipakai karena `tanggalPokokLunas()` bisa berada di masa
        // depan relatif terhadap tanggal simulasi (pembayaran bertanggal maju
        // ditolak validator, tetapi data lama bisa saja begitu).
        $tanggalPokokLunas = $tagihan->tanggalPokokLunas();
        $acuanDenda = $acuan;

        if ($tanggalPokokLunas !== null && $tanggalPokokLunas->lessThan($acuan)) {
            $acuanDenda = $tanggalPokokLunas;
        }

        $hariTerlambat = $this->waktu->selisihHari($jatuhTempo, $acuan);
        $hariTerlambatDenda = $this->waktu->selisihHari($jatuhTempo, $acuanDenda);

        $dendaSebelumMaaf = $this->kalkulator->hitungDendaMenurut($denda, $pokok, $hariTerlambatDenda);
        $hariDenda = $this->kalkulator->hariDenda($hariTerlambatDenda, $denda->masaTenggang);

        // `max(0, ...)`: pemaafan yang lebih besar dari denda saat ini tidak
        // boleh menghasilkan denda negatif. Itu bisa terjadi dengan sengaja —
        // pemilik memaafkan Rp 500.000 saat denda baru Rp 300.000 karena
        // dendanya akan terus berjalan — dan hasilnya harus nol, bukan angka
        // negatif yang terbaca sebagai "kos berutang ke penghuni".
        $dendaRupiah = max(0, $dendaSebelumMaaf - $dimaafkan);

        // Sisa hari sampai plafon hanya bermakna selama denda masih bertambah
        // dan memang punya plafon.
        $sisaHari = null;
        if ($denda->berplafon()) {
            $hariHinggaPlafon = $this->kalkulator->hariHinggaPlafon([
                'tarif_persen' => $denda->persen,
                'plafon_persen' => $denda->plafonPersen,
                'masa_tenggang' => $denda->masaTenggang,
            ]);
            $sisaHari = $hariHinggaPlafon > 0
                ? max(0, $hariHinggaPlafon - $hariDenda)
                : 0;
        }

        // Denda yang sudah dibekukan karena pokoknya lunas tetap dilaporkan
        // sebagai "berhenti", dengan sebab yang berbeda dari lunas dan batal.
        // Tanpa ini, layar akan menampilkan "terlambat 100 hari" untuk tagihan
        // yang dendanya berhenti di hari ke-40.
        $dibekukan = $acuanDenda->lessThan($acuan);

        return new HasilDenda(
            tipe: $denda->tipe,
            pokok: $pokok,
            jatuhTempo: $jatuhTempo->toDateString(),
            tanggalAcuan: $acuan->toDateString(),
            hariTerlambat: $hariTerlambat,
            masaTenggang: $denda->masaTenggang,
            hariDenda: $hariDenda,
            denda: $dendaRupiah,
            dendaSebelumMaaf: $dendaSebelumMaaf,
            dimaafkan: $dimaafkan,
            plafon: $plafon,
            sisaHariKePlafon: $sisaHari,
            status: (string) $tagihan->status,
            berhenti: $dibekukan,
            alasanBerhenti: $dibekukan
                ? 'Pokok tagihan sudah dibayar penuh pada '.$acuanDenda->toDateString().', jadi denda berhenti bertambah.'
                : '',
        );
    }

    /**
     * Bentuk denda yang berlaku bagi tagihan ini, dari dokumen bekunya.
     *
     * Urutan pembacaan disengaja: dokumen beku menang; kolom lama hanya dipakai
     * bila dokumennya tidak ada. Membalik urutan itu akan membuat tagihan baru
     * memakai kolom lama yang kini selalu NULL.
     */
    public function bentukDenda(Tagihan $tagihan): DendaTerpilih
    {
        $dokumen = $tagihan->kebijakan_terpakai['denda'] ?? null;

        if (is_array($dokumen) && isset($dokumen['tipe'])) {
            return $this->dariDokumen($dokumen);
        }

        return $this->dariKolomLama($tagihan);
    }

    /**
     * @param  array<string, mixed>  $dokumen
     */
    private function dariDokumen(array $dokumen): DendaTerpilih
    {
        $tipe = (string) $dokumen['tipe'];

        return match ($tipe) {
            PilihanKebijakan::DENDA_TANPA_DENDA => DendaTerpilih::tanpaDenda(),

            PilihanKebijakan::DENDA_NOMINAL_TETAP => DendaTerpilih::nominalTetap(
                (int) ($dokumen['nominal'] ?? 0),
            ),

            PilihanKebijakan::DENDA_PERSEN_BULANAN => DendaTerpilih::persenBulanan(
                (string) ($dokumen['persen'] ?? '0.00'),
                (string) ($dokumen['plafon_persen'] ?? '0.00'),
                (int) ($dokumen['masa_tenggang'] ?? 0),
            ),

            PilihanKebijakan::DENDA_PERSEN_HARIAN => DendaTerpilih::persenHarian(
                (string) ($dokumen['persen'] ?? '0.00'),
                (string) ($dokumen['plafon_persen'] ?? '0.00'),
                (int) ($dokumen['masa_tenggang'] ?? 0),
            ),

            // Tipe tak dikenal di dalam arsip berarti arsipnya rusak atau
            // ditulis versi kode yang lebih baru. Melempar di sini membuat
            // masalahnya terlihat; menebak 1%/hari akan menyembunyikannya di
            // balik angka yang tampak wajar.
            default => throw new \UnexpectedValueException(
                "Bentuk denda \"{$tipe}\" pada dokumen kebijakan tagihan tidak dikenal."
            ),
        };
    }

    /**
     * Bentuk denda untuk tagihan yang terbit sebelum T17.
     *
     * Ketiga kolom ini dulu NOT NULL, jadi nilainya pasti ada. Setelah migrasi
     * T17 kolomnya nullable, tetapi tagihan lama tidak diubah isinya.
     */
    private function dariKolomLama(Tagihan $tagihan): DendaTerpilih
    {
        // Tanpa kolom lama dan tanpa dokumen, tidak ada dasar untuk menghitung
        // apa pun. Nol lebih jujur daripada menebak: tagihan seperti ini hanya
        // bisa muncul dari baris yang dibuat tangan, bukan dari alur aplikasi.
        if ($tagihan->tarif_denda_persen === null) {
            return DendaTerpilih::tanpaDenda();
        }

        return DendaTerpilih::persenHarian(
            (string) $tagihan->tarif_denda_persen,
            (string) ($tagihan->plafon_persen ?? '0.00'),
            (int) ($tagihan->masa_tenggang ?? 0),
        );
    }
}
