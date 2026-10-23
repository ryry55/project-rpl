<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Membuat atau mengubah data induk penghuni (T27).
 *
 * **Tidak ada medan `password`, dan ketiadaannya disengaja.**
 *
 * Sebelum T27, pemilik mengetik kata sandi awal penghuni. Itu membuat pemilik
 * bisa masuk sebagai penghuni, dan klaim isolasi R-07 - "penghuni hanya
 * melihat tagihannya sendiri" - menjadi janji yang tidak bisa dibuktikan:
 * isolasi terhadap orang yang memegang sandinya bukan isolasi.
 *
 * Sejak T27 akun dibuat dengan sandi acak yang tidak diketahui siapa pun, dan
 * penghuni menetapkan sandinya sendiri lewat tautan aktivasi. Klien yang masih
 * mengirim `password` tidak ditolak dengan galat validasi - medan itu memang
 * tidak ada lagi di kontrak, jadi ia diabaikan seperti medan asing lainnya.
 * Menolaknya akan membuat layar lama menampilkan galat yang membingungkan
 * tentang medan yang tidak lagi ditampilkan.
 */
class SimpanPenghuniRequest extends FormRequest
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
        $penghuni = $this->route('penghuni');
        $id = $penghuni?->id;

        return [
            'nama' => ['required', 'string', 'max:120'],
            'email' => [
                'required',
                'email',
                'max:120',
                Rule::unique('penghuni', 'email')->ignore($id),
                // Email adalah kunci login, dan tabel `users` punya unique
                // sendiri. Tanpa pemeriksaan ini, penghuni dengan email yang
                // sudah dipakai akun pengelola lolos validasi lalu gagal di
                // basis data dengan 500 - pesan yang tidak menjelaskan apa pun
                // kepada pemilik yang mengetiknya.
                Rule::unique('users', 'email')->ignore($penghuni?->user?->id),
            ],
            'no_hp' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.required' => 'Nama penghuni wajib diisi.',
            'email.required' => 'Email wajib diisi karena dipakai untuk masuk.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah dipakai akun lain.',
            'no_hp.max' => 'Nomor HP terlalu panjang.',
        ];
    }
}
