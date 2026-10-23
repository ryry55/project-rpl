<?php

namespace App\Http\Requests\Api;

use App\Domain\Akses\Wewenang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Membuat atau mengubah akun pengelola (T24, T27).
 *
 * Peran tidak diterima dari klien. Endpoint ini hanya untuk membuat pengelola,
 * jadi `peran` diisi server. Kalau `peran` bisa dikirim pemanggil, siapa pun
 * yang punya wewenang mengelola akun bisa membuat akun pemilik - dan wewenang
 * yang seharusnya membatasi justru menjadi jalan untuk melewatinya.
 *
 * **Tidak ada medan `password`** (T27). Pemilik yang tahu sandi pengelolanya
 * bisa mengubah tarif atas nama pengelola itu, dan jejak audit yang tertulis
 * adalah nama orang yang tidak melakukannya. Sandi kini ditetapkan pemilik akun
 * sendiri lewat tautan aktivasi.
 *
 * Mengganti sandi pengelola juga bukan lagi tugas pemilik. Kalau sandinya lupa,
 * pengelolanya memakai alur "lupa sandi" di halaman masuk - satu-satunya alur
 * yang bisa membuktikan kepemilikan alamat emailnya.
 */
class SimpanPengelolaRequest extends FormRequest
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
        $pengelola = $this->route('pengelola');
        $id = $pengelola?->id;

        return [
            'nama' => ['required', 'string', 'max:120'],
            'email' => [
                'required',
                'email',
                'max:120',
                // Unik di seluruh tabel users, bukan hanya antar-pengelola:
                // email adalah kunci login, dan dua akun berbagi email berarti
                // yang satu tidak bisa masuk sama sekali.
                Rule::unique('users', 'email')->ignore($id),
            ],

            // Daftar wewenang yang diberikan. Boleh kosong: pengelola tanpa
            // wewenang adalah keadaan yang sah (akun baru yang belum diberi
            // tugas apa pun), dan lebih baik daripada akun yang langsung
            // memegang izin yang belum diminta.
            'wewenang' => ['present', 'array'],
            'wewenang.*' => ['string', Rule::in(Wewenang::SEMUA)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.required' => 'Nama pengelola wajib diisi.',
            'email.required' => 'Email wajib diisi karena dipakai untuk masuk.',
            'email.unique' => 'Email sudah dipakai akun lain.',
            // Pesan ini yang muncul bila layar mengirim wewenang yang tidak
            // dikenal - mis. karena daftar di peramban tertinggal dari server.
            'wewenang.*.in' => 'Ada wewenang yang tidak dikenal. Muat ulang halaman lalu coba lagi.',
        ];
    }
}
