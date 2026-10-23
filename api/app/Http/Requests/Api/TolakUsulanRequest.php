<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Penolakan satu usulan perubahan (T26).
 *
 * `alasan` wajib, dan itu bukan formalitas. Penolakan tanpa alasan membuat
 * pengelola mengajukan ulang hal yang sama persis - ia tidak tahu bagian mana
 * yang salah, jadi satu-satunya yang bisa ia lakukan adalah mencoba lagi.
 * Kolom yang wajib diisi memaksa pemilik menuliskan apa yang harus diperbaiki,
 * dan kalimat itu yang dibaca pengelola di kotak masuknya.
 *
 * Persetujuan **tidak** memakai kelas ini: ia tidak punya isi. Endpoint
 * terpisah untuk keduanya membuat "setujui" tidak bisa tidak sengaja membawa
 * `alasan` yang lalu terbaca sebagai syarat oleh orang yang membaca riwayat.
 */
class TolakUsulanRequest extends FormRequest
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
            'alasan' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'alasan.required' => 'Penolakan wajib menyebut alasannya, supaya pengelola tahu apa yang harus diperbaiki.',
            'alasan.max' => 'Alasan terlalu panjang (maksimal 255 karakter).',
        ];
    }

    public function alasan(): string
    {
        return $this->string('alasan')->trim()->value();
    }
}
