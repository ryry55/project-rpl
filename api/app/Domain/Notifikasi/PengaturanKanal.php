<?php

namespace App\Domain\Notifikasi;

use App\Models\PengaturanNotifikasi;
use App\Models\User;

/**
 * Apakah satu pengguna menerima satu peristiwa lewat satu kanal (T25).
 *
 * Bawaannya diambil dari {@see Peristiwa::kanalBawaan}; baris di
 * `pengaturan_notifikasi` hanya ada bila pengguna menyimpang. Karena itu akun
 * baru - dan akun yang belum pernah membuka layar Pengaturan - sudah punya
 * perilaku yang masuk akal tanpa perlu diisi apa pun lebih dulu.
 */
final class PengaturanKanal
{
    /**
     * @var array<string, bool> kunci "user:peristiwa:kanal" -> aktif
     */
    private array $tembolok = [];

    public function aktif(User $user, string $peristiwa, string $kanal): bool
    {
        $kunci = $user->getKey().'|'.$peristiwa.'|'.$kanal;

        if (array_key_exists($kunci, $this->tembolok)) {
            return $this->tembolok[$kunci];
        }

        $baris = PengaturanNotifikasi::query()
            ->where('user_id', $user->getKey())
            ->where('peristiwa', $peristiwa)
            ->where('kanal', $kanal)
            ->first();

        $aktif = $baris !== null
            ? (bool) $baris->aktif
            : in_array($kanal, Peristiwa::kanalBawaan($peristiwa), true);

        return $this->tembolok[$kunci] = $aktif;
    }

    /**
     * Matriks lengkap untuk layar Pengaturan.
     *
     * Dikirim sebagai nilai yang sudah berlaku (bawaan sudah diterapkan), bukan
     * sebagai daftar penimpa. Layar yang harus menebak bawaannya sendiri adalah
     * layar yang akan menampilkan kotak centang berbeda dari perilaku server
     * begitu satu bawaan diubah.
     *
     * @return array<string, array{in_app: bool, email: bool}>
     */
    public function matriks(User $user): array
    {
        $hasil = [];

        foreach (Peristiwa::SEMUA as $peristiwa) {
            foreach ([Peristiwa::KANAL_IN_APP, Peristiwa::KANAL_EMAIL] as $kanal) {
                $hasil[$peristiwa][$kanal] = $this->aktif($user, $peristiwa, $kanal);
            }
        }

        return $hasil;
    }

    /**
     * Lupakan tembolok.
     *
     * Dipanggil setelah pengaturan diubah, supaya permintaan yang sama tidak
     * memakai jawaban lama. Tanpa ini, layar Pengaturan akan menyimpan lalu
     * membaca ulang nilai sebelum perubahan - dan terlihat seperti tombol yang
     * tidak bekerja.
     */
    public function lupakan(): void
    {
        $this->tembolok = [];
    }
}
