<?php

namespace App\Domain\Billing;

use App\Models\Kontrak;
use Carbon\CarbonInterface;

/**
 * Alasan sebuah tagihan tidak bisa diterbitkan.
 *
 * Dibuat sebagai exception berpesan Indonesia, bukan sekadar `false` atau `null`,
 * supaya controller bisa meneruskan alasannya apa adanya ke pengguna tanpa
 * menebak. Pesan yang menyebut angka konkret ("periode 2026-09 sebelum tanggal
 * masuk 2026-10-05") jauh lebih berguna daripada "tagihan tidak dapat diterbitkan".
 */
class TagihanTidakDapatDiterbitkan extends \RuntimeException
{
    public static function karenaTarifKosong(Kontrak $kontrak, CarbonInterface $periode): self
    {
        return new self(sprintf(
            'Kamar %s belum punya tarif yang berlaku untuk periode %s.',
            $kontrak->kamar->nomor,
            $periode->toDateString(),
        ));
    }

    public static function karenaParameterDendaKosong(CarbonInterface $periode): self
    {
        return new self(sprintf(
            'Belum ada kebijakan denda yang berlaku untuk periode %s. Tetapkan dulu di menu Kebijakan.',
            $periode->toDateString(),
        ));
    }

    public static function karenaPeriodeSebelumMasuk(CarbonInterface $periode, CarbonInterface $masuk): self
    {
        return new self(sprintf(
            'Periode %s sebelum tanggal masuk %s. Tagihan tidak bisa diterbitkan untuk bulan yang belum dihuni.',
            $periode->toDateString(),
            $masuk->toDateString(),
        ));
    }

    public static function karenaKontrakSudahSelesai(CarbonInterface $periode, CarbonInterface $keluar): self
    {
        return new self(sprintf(
            'Kontrak sudah selesai pada %s, jadi periode %s tidak bisa ditagihkan.',
            $keluar->toDateString(),
            $periode->toDateString(),
        ));
    }
}
