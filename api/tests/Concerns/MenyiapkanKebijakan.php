<?php

namespace Tests\Concerns;

use App\Domain\Kebijakan\PilihanKebijakan;
use App\Models\KebijakanKos;

/**
 * Menyiapkan kebijakan kos default untuk tes yang menerbitkan tagihan.
 *
 * Sejak T16, `POST /tagihan` menolak terbit bila kebijakan belum ditetapkan.
 * Tes yang maksudnya menguji prorata, pembayaran, atau tunggakan jadi gagal
 * karena alasan yang tidak sedang diuji — dan kegagalan itu benar: gerbangnya
 * memang bekerja. Yang perlu dilakukan adalah menyatakan kebijakannya di
 * penyiapan, sama seperti tes-tes itu sudah menyatakan tarif dan parameter
 * denda.
 *
 * `berlaku_dari` default 2026-01-01, bukan hari ini: hampir semua tes memakai
 * periode 2026, dan kebijakan yang berlaku mulai hari ini tidak akan terpakai
 * untuk periode yang sudah lewat.
 */
trait MenyiapkanKebijakan
{
    protected function siapkanKebijakanDefault(
        ?int $kamarId = null,
        string $berlakuDari = '2026-01-01',
    ): void {
        KebijakanKos::create([
            'kamar_id' => $kamarId,
            'tipe' => PilihanKebijakan::TIPE_SATUAN_SEWA,
            'nilai' => PilihanKebijakan::SATUAN_BULANAN,
            'berlaku_dari' => $berlakuDari,
        ]);

        KebijakanKos::create([
            'kamar_id' => $kamarId,
            'tipe' => PilihanKebijakan::TIPE_JATUH_TEMPO,
            'nilai' => PilihanKebijakan::JATUH_TEMPO_TANGGAL_MASUK,
            'berlaku_dari' => $berlakuDari,
        ]);
    }
}
