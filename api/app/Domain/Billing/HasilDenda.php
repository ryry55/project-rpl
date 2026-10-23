<?php

namespace App\Domain\Billing;

/**
 * Hasil perhitungan denda untuk satu tagihan.
 *
 * Dibuat sebagai objek, bukan sekadar int, karena layar perlu lebih dari satu
 * angka: denda hari ini, plafonnya, sudah berapa hari terlambat, dan berapa
 * hari lagi sampai plafon. Menghitung ulang tiap angka di controller atau di
 * Vue akan menduplikasi aturan dan cepat atau lambat berbeda.
 *
 * Sejak T17 bentuknya bergantung pada tipe denda yang dipilih pemilik, jadi
 * `tipe` ikut dibawa dan `plafon`/`sisaHariKePlafon` boleh `null`. Layar wajib
 * membaca `tipe` lebih dulu: bagi denda nominal, "mencapai plafon dalam 12
 * hari" tidak berarti apa pun, dan menampilkannya sebagai angka 0 membuat
 * pembaca tagihan mengira plafonnya nol.
 */
final class HasilDenda
{
    public function __construct(
        /** Salah satu konstanta `PilihanKebijakan::DENDA_*`. */
        public readonly string $tipe,
        public readonly int $pokok,
        public readonly string $jatuhTempo,
        public readonly string $tanggalAcuan,
        public readonly int $hariTerlambat,
        public readonly int $masaTenggang,
        public readonly int $hariDenda,
        public readonly int $denda,
        /** null berarti bentuk denda ini tidak berplafon. */
        public readonly ?int $plafon,
        /** null berarti tidak bermakna untuk bentuk denda ini. */
        public readonly ?int $sisaHariKePlafon,
        public readonly string $status,
        public readonly bool $berhenti,
        public readonly string $alasanBerhenti,
    ) {}

    /**
     * Yang harus dibayar penghuni: pokok plus denda berjalan.
     *
     * Ditulis sebagai method dan dipakai juga oleh `toArray()` supaya rumusnya
     * hanya ada di satu tempat. Pemanggil lain — termasuk `PencatatPembayaran` —
     * memakai ini alih-alih menuliskan `pokok + denda` sendiri.
     */
    public function total(): int
    {
        return $this->pokok + $this->denda;
    }

    /**
     * Apakah bentuk denda ini berjalan mengikuti waktu.
     *
     * Dipakai layar untuk memutuskan apakah uraian "terlambat N hari, lewat
     * tenggang M hari" perlu ditampilkan. Untuk denda nominal, kalimat itu
     * menyesatkan: dendanya tidak bertambah karena hari bertambah.
     *
     * Perhatikan bahwa `false` di sini mencakup **dua** bentuk yang berbeda —
     * `nominal_tetap` dan `tanpa_denda` — jadi layar tidak boleh memakai ini
     * sebagai satu-satunya cabang. Pakai {@see bentuk()} untuk memilih kalimat.
     */
    public function berjalan(): bool
    {
        return $this->bentuk() === 'persen';
    }

    /**
     * Kelompok bentuk denda, untuk memilih kalimat di layar.
     *
     * Diteruskan dari `DendaTerpilih` supaya aturannya hanya ada di satu
     * tempat: `HasilDenda` adalah hasil hitung, bukan pemilik definisi bentuk.
     */
    public function bentuk(): string
    {
        return DendaTerpilih::bentukDari($this->tipe);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tipe' => $this->tipe,
            'bentuk' => $this->bentuk(),
            'berjalan' => $this->berjalan(),
            'pokok' => $this->pokok,
            'jatuh_tempo' => $this->jatuhTempo,
            'tanggal_acuan' => $this->tanggalAcuan,
            'hari_terlambat' => $this->hariTerlambat,
            'masa_tenggang' => $this->masaTenggang,
            'hari_denda' => $this->hariDenda,
            'denda' => $this->denda,
            'plafon' => $this->plafon,
            'sisa_hari_ke_plafon' => $this->sisaHariKePlafon,
            'total' => $this->total(),
            'status' => $this->status,
            'berhenti' => $this->berhenti,
            'alasan_berhenti' => $this->alasanBerhenti,
        ];
    }
}
