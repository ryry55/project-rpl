<?php

namespace App\Domain\Kebijakan;

use Carbon\CarbonInterface;

/**
 * Nama kos yang dipakai di layar dan dokumen (K-17).
 *
 * Sebelum ini nama kos tertulis di kode di empat tempat: judul bilah pemilik,
 * subjudul layar masuk, subjek email, dan kaki kuitansi. Semuanya berbunyi
 * "Huni" — nama contoh, bukan nama kos siapa pun.
 *
 * Satu kelas, bukan empat pembacaan kebijakan yang tersebar, karena keempat
 * tempat itu harus menampilkan nama yang **sama**. Kalau masing-masing membaca
 * sendiri, satu tempat akan lupa memakai nilai bawaan dan menampilkan kosong.
 *
 * Nilai bawaannya sengaja tetap "Huni": kos yang tidak pernah menyentuh
 * layar Kebijakan tidak boleh tiba-tiba kehilangan judulnya.
 */
class IdentitasKos
{
    public const BAWAAN = 'Huni';

    public function __construct(private readonly PenyelesaiKebijakan $penyelesai) {}

    /**
     * Nama kos yang berlaku pada periode itu, atau nilai bawaan.
     *
     * Periode diterima sebagai argumen supaya pemanggil yang menjelaskan masa
     * lalu (mis. kuitansi lama) bisa memakai nama yang berlaku saat itu. Tanpa
     * argumen, yang dipakai adalah hari ini.
     */
    public function nama(?CarbonInterface $periode = null): string
    {
        $baris = $this->penyelesai->kebijakan(
            PilihanKebijakan::TIPE_NAMA_KOS,
            $periode ?? now(),
        );

        $nama = trim((string) $baris?->nilai);

        return $nama !== '' ? $nama : self::BAWAAN;
    }
}
