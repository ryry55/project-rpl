<?php

namespace App\Http\Requests\Api;

use App\Domain\Notifikasi\Peristiwa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validasi matriks pengaturan notifikasi (T25).
 *
 * Bentuk kirimannya `{ "matriks": { "tarif.diubah": { "in_app": true, "email": false }, ... } }`
 * - seluruh matriks sekaligus, bukan satu baris per permintaan. Layar
 * menampilkan semuanya dalam satu tabel centang, jadi menyimpan sebagian
 * membuat dua pengguna yang menyimpan bersamaan saling menimpa baris yang tidak
 * mereka sentuh.
 */
class SimpanPengaturanNotifikasiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'matriks' => ['required', 'array'],
            'matriks.*' => ['array'],
            'matriks.*.*' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'matriks.required' => 'Matriks pengaturan wajib dikirim.',
            'matriks.array' => 'Matriks pengaturan harus berupa objek.',
            'matriks.*.array' => 'Setiap peristiwa harus berisi pasangan kanal dan nilai.',
            'matriks.*.*.boolean' => 'Nilai kanal harus benar atau salah.',
        ];
    }

    /**
     * Nama peristiwa dan kanal yang tidak dikenal ditolak di sini, bukan
     * diabaikan.
     *
     * Mengabaikannya akan membuat salah ketik pada nama peristiwa terlihat
     * seperti "pengaturan tersimpan" - padahal barisnya tidak ditulis, dan
     * notifikasinya tetap datang. Pengguna lalu menyimpulkan tombolnya rusak.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach (array_keys($this->input('matriks', [])) as $peristiwa) {
                    if (! Peristiwa::dikenal((string) $peristiwa)) {
                        $validator->errors()->add(
                            'matriks',
                            "Peristiwa \"{$peristiwa}\" tidak dikenal.",
                        );

                        return;
                    }
                }

                foreach ($this->input('matriks', []) as $peristiwa => $kanal) {
                    foreach (array_keys($kanal) as $nama) {
                        if (! Peristiwa::dikenalKanal((string) $nama)) {
                            $validator->errors()->add(
                                'matriks',
                                "Kanal \"{$nama}\" pada peristiwa \"{$peristiwa}\" tidak dikenal.",
                            );

                            return;
                        }
                    }
                }
            },
        ];
    }

    /**
     * Matriks yang sudah dinormalkan: **hanya yang benar-benar dikirim**.
     *
     * Peristiwa yang tidak dikirim tidak dikembalikan di sini, dan itu
     * disengaja. Tabel `pengaturan_notifikasi` hanya menyimpan yang menyimpang
     * dari bawaan; mengembalikan seluruh delapan peristiwa akan menulis enam
     * belas baris setiap kali layar menyimpan, yang isinya sama untuk semua
     * orang. Akibatnya bukan cuma tabel yang membengkak: pertanyaan "apakah
     * akun ini menerima email untuk tarif" berubah dari satu pembacaan
     * konstanta menjadi query yang bisa gagal, dan setiap perubahan bawaan di
     * kode berhenti berlaku bagi akun yang pernah membuka layar ini sekali.
     *
     * Kanal yang tidak disebut untuk peristiwa yang dikirim juga tidak
     * dikembalikan: ketiadaannya berarti "pakai bawaan", bukan "matikan".
     *
     * @return array<string, array<string, bool>>
     */
    public function matriks(): array
    {
        $dikirim = $this->input('matriks', []);
        $hasil = [];

        foreach ($dikirim as $peristiwa => $kanal) {
            foreach ($kanal as $nama => $aktif) {
                $hasil[$peristiwa][$nama] = (bool) $aktif;
            }
        }

        return $hasil;
    }
}
