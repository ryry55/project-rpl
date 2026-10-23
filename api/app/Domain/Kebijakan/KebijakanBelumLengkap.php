<?php

namespace App\Domain\Kebijakan;

use App\Domain\Billing\TagihanTidakDapatDiterbitkan;

/**
 * Penolakan terbit karena kebijakan kos belum lengkap (T16).
 *
 * Turunan {@see TagihanTidakDapatDiterbitkan}, bukan kelas terpisah, supaya
 * tetap tertangkap oleh semua tempat yang sudah menangani "tagihan tidak bisa
 * diterbitkan" — termasuk penerbitan massal. Tetapi ia punya tipe sendiri,
 * karena penerbitan massal perlu membedakan sebabnya: tarif kosong adalah
 * masalah satu kamar, kebijakan kosong adalah masalah satu kos. Kalau keduanya
 * dilaporkan dengan sebab yang sama, pemilik yang melihat 30 baris gagal akan
 * mengira ada 30 hal yang harus diperbaiki.
 */
class KebijakanBelumLengkap extends TagihanTidakDapatDiterbitkan
{
    /**
     * @param  list<string>  $tipe
     */
    public static function dariTipe(array $tipe): self
    {
        $label = array_map(fn (string $satu) => match ($satu) {
            'satuan_sewa' => 'satuan sewa',
            'jatuh_tempo' => 'jatuh tempo',
            default => $satu,
        }, $tipe);

        return new self(sprintf(
            'Kebijakan kos belum lengkap: %s belum ditetapkan. Isi lewat menu Kebijakan sebelum menerbitkan tagihan.',
            implode(' dan ', $label),
        ));
    }
}
