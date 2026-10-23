<?php

namespace App\Domain\Billing;

use App\Domain\Kebijakan\PilihanKebijakan;

/**
 * Bentuk denda yang dipilih pemilik, sebagai nilai yang siap dihitung.
 *
 * Sebelum T17, `PenghitungDenda` membaca tiga kolom `tagihan` dan selalu
 * memakai satu rumus. Sekarang bentuknya bisa empat macam, dan tiap bentuk
 * membawa angka yang berbeda-beda. Objek ini yang membuat perbedaan itu
 * eksplisit: pemanggil tidak bisa "lupa" bahwa denda nominal tidak punya
 * plafon, karena field-nya memang tidak ada.
 *
 * **Ini tipe domain, bukan model.** Tidak ada Eloquent di sini, supaya
 * `Kalkulator` tetap murni dan bisa diuji tanpa basis data.
 *
 * Kenapa memakai DTO dan bukan sekadar meneruskan array: array membuat
 * kesalahan ketik nama kunci menjadi `null` yang diam-diam menghasilkan denda
 * nol, dan itu tampak seperti "tidak ada denda" alih-alih seperti bug.
 */
final class DendaTerpilih
{
    private function __construct(
        /** Salah satu konstanta `PilihanKebijakan::DENDA_*`. */
        public readonly string $tipe,
        /** Tarif persen per satuan waktu; 0 untuk bentuk yang tidak memakainya. */
        public readonly string $persen,
        /** Plafon persen dari pokok; 0 berarti tanpa plafon. */
        public readonly string $plafonPersen,
        /** Hari sebelum denda mulai berjalan. */
        public readonly int $masaTenggang,
        /** Denda tetap dalam rupiah; 0 untuk bentuk yang tidak memakainya. */
        public readonly int $nominal,
    ) {}

    /**
     * Denda persen per hari, mis. 1%/hari dengan plafon 30% dan tenggang 7 hari.
     */
    public static function persenHarian(string $persen, string $plafonPersen, int $masaTenggang = 0): self
    {
        return new self(
            tipe: PilihanKebijakan::DENDA_PERSEN_HARIAN,
            persen: $persen,
            plafonPersen: $plafonPersen,
            masaTenggang: $masaTenggang,
            nominal: 0,
        );
    }

    public static function persenBulanan(string $persen, string $plafonPersen, int $masaTenggang = 0): self
    {
        return new self(
            tipe: PilihanKebijakan::DENDA_PERSEN_BULANAN,
            persen: $persen,
            plafonPersen: $plafonPersen,
            masaTenggang: $masaTenggang,
            nominal: 0,
        );
    }

    public static function nominalTetap(int $nominal): self
    {
        return new self(
            tipe: PilihanKebijakan::DENDA_NOMINAL_TETAP,
            persen: '0.00',
            plafonPersen: '0.00',
            masaTenggang: 0,
            nominal: $nominal,
        );
    }

    public static function tanpaDenda(): self
    {
        return new self(
            tipe: PilihanKebijakan::DENDA_TANPA_DENDA,
            persen: '0.00',
            plafonPersen: '0.00',
            masaTenggang: 0,
            nominal: 0,
        );
    }

    /**
     * Kelompok bentuk denda dari kode tipe saja.
     *
     * `berjalan()` saja tidak cukup untuk memilih kalimat di layar: ia menjawab
     * "apakah bertambah tiap hari", sedangkan layar perlu tiga kalimat berbeda
     * — persen (menyebut tenggang dan plafon), nominal (menyebut nominal per
     * bulan), dan tanpa denda (tidak menyebut apa pun). Tanpa method ini, layar
     * harus menyalin daftar nama tipe dari `PilihanKebijakan`, dan salinan itu
     * akan tertinggal setiap kali bentuk denda bertambah.
     *
     * Statis karena `HasilDenda` hanya menyimpan `tipe`, bukan seluruh DTO:
     * hasil hitung tidak perlu membawa ulang angka yang sudah dipakai.
     *
     * @return 'persen'|'nominal'|'tanpa'
     */
    public static function bentukDari(string $tipe): string
    {
        return match ($tipe) {
            PilihanKebijakan::DENDA_PERSEN_HARIAN,
            PilihanKebijakan::DENDA_PERSEN_BULANAN => 'persen',
            PilihanKebijakan::DENDA_NOMINAL_TETAP => 'nominal',
            default => 'tanpa',
        };
    }

    /**
     * Kelompok bentuk denda ini, untuk memilih kalimat di layar.
     *
     * @return 'persen'|'nominal'|'tanpa'
     */
    public function bentuk(): string
    {
        return self::bentukDari($this->tipe);
    }

    /**
     * Apakah denda ini bertambah mengikuti waktu.
     *
     * Hanya bentuk persen. Dipakai layar untuk memutuskan apakah "denda
     * berhenti di plafon" dan "sisa hari ke plafon" perlu ditampilkan sama
     * sekali — bagi denda nominal, kalimat itu tidak berarti apa pun.
     *
     * Perhatikan bahwa `false` mencakup **dua** bentuk yang berbeda,
     * `nominal_tetap` dan `tanpa_denda`. Layar yang ingin memilih kalimat
     * memakai {@see bentuk()}, bukan method ini.
     */
    public function berjalan(): bool
    {
        return $this->bentuk() === 'persen';
    }

    /**
     * Apakah denda ini bisa mencapai plafon.
     */
    public function berplafon(): bool
    {
        return $this->berjalan() && $this->plafonPersen !== '0.00' && $this->plafonPersen !== '0';
    }

    /**
     * Kunci `tarif_terpakai`/`kebijakan_terpakai` untuk dokumen beku.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tipe' => $this->tipe,
            'persen' => $this->persen,
            'plafon_persen' => $this->plafonPersen,
            'masa_tenggang' => $this->masaTenggang,
            'nominal' => $this->nominal,
        ];
    }
}
