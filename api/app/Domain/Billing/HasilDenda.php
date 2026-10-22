<?php

namespace App\Domain\Billing;

/**
 * Hasil perhitungan denda untuk satu tagihan.
 *
 * Dibuat sebagai objek, bukan sekadar int, karena layar perlu lebih dari satu
 * angka: denda hari ini, plafonnya, sudah berapa hari terlambat, dan berapa
 * hari lagi sampai plafon. Menghitung ulang tiap angka di controller atau di
 * Vue akan menduplikasi aturan dan cepat atau lambat berbeda.
 */
final class HasilDenda
{
    public function __construct(
        public readonly int $pokok,
        public readonly string $jatuhTempo,
        public readonly string $tanggalAcuan,
        public readonly int $hariTerlambat,
        public readonly int $masaTenggang,
        public readonly int $hariDenda,
        public readonly int $denda,
        public readonly int $plafon,
        public readonly int $sisaHariKePlafon,
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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
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
