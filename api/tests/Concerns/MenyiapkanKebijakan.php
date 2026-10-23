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
 * penyiapan.
 *
 * Sejak T17 `denda` ikut wajib, karena `parameter_denda` sudah dihapus: tidak
 * ada lagi sumber angka denda selain kebijakan. Bawaannya sengaja persis R-03
 * (1% per hari, plafon 30%, tenggang 7) supaya tes yang sudah ada tetap
 * memeriksa angka yang sama seperti sebelum tiket ini. Perubahan perilaku
 * hanya terjadi pada tes yang secara eksplisit memilih bentuk denda lain —
 * dan hanya tes itu yang seharusnya ikut berubah.
 *
 * `berlaku_dari` default 2026-01-01, bukan hari ini: hampir semua tes memakai
 * periode 2026, dan kebijakan yang berlaku mulai hari ini tidak akan terpakai
 * untuk periode yang sudah lewat.
 */
trait MenyiapkanKebijakan
{
    /**
     * @param  array{nilai?: string, persen?: string, plafon_persen?: string, masa_tenggang?: int, nominal?: int}|null  $denda
     */
    protected function siapkanKebijakanDefault(
        ?int $kamarId = null,
        string $berlakuDari = '2026-01-01',
        ?array $denda = null,
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

        $this->siapkanKebijakanDenda($kamarId, $berlakuDari, $denda);
    }

    /**
     * Baris kebijakan denda saja, untuk tes yang butuh bentuk denda tertentu.
     *
     * @param  array{nilai?: string, persen?: string, plafon_persen?: string, masa_tenggang?: int, nominal?: int}|null  $denda
     */
    protected function siapkanKebijakanDenda(
        ?int $kamarId = null,
        string $berlakuDari = '2026-01-01',
        ?array $denda = null,
    ): void {
        // Bawaan = R-03. Tanpa nilai bawaan ini, setiap tes lama harus
        // menyebutkan bentuk denda hanya untuk mempertahankan angka yang
        // sebelumnya tertanam di kode.
        $denda ??= [
            'nilai' => PilihanKebijakan::DENDA_PERSEN_HARIAN,
            'persen' => '1.00',
            'plafon_persen' => '30.00',
            'masa_tenggang' => 7,
        ];

        KebijakanKos::create([
            'kamar_id' => $kamarId,
            'tipe' => PilihanKebijakan::TIPE_DENDA,
            'nilai' => $denda['nilai'],
            'persen' => $denda['persen'] ?? null,
            'plafon_persen' => $denda['plafon_persen'] ?? null,
            'masa_tenggang' => $denda['masa_tenggang'] ?? null,
            'nominal' => $denda['nominal'] ?? null,
            'berlaku_dari' => $berlakuDari,
        ]);
    }

    /**
     * Baris kebijakan deposit, untuk tes yang menguji pembentukan kontrak.
     */
    protected function siapkanKebijakanDeposit(
        string $nilai = PilihanKebijakan::DEPOSIT_TANPA_DEPOSIT,
        ?int $nominal = null,
        ?int $kamarId = null,
        string $berlakuDari = '2026-01-01',
    ): void {
        KebijakanKos::create([
            'kamar_id' => $kamarId,
            'tipe' => PilihanKebijakan::TIPE_DEPOSIT,
            'nilai' => $nilai,
            'nominal' => $nilai === PilihanKebijakan::DEPOSIT_NOMINAL ? $nominal : null,
            'berlaku_dari' => $berlakuDari,
        ]);
    }
}
