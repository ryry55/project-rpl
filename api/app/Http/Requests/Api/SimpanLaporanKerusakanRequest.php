<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SimpanLaporanKerusakanRequest extends FormRequest
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
            'kamar_id' => ['required', 'integer', 'exists:kamar,id'],
            // Lima huruf, bukan satu: "rusak" sudah memenuhi, sedangkan satu
            // huruf yang terkirim karena tombol tertekan tidak bisa dipakai
            // siapa pun untuk mencari kerusakannya.
            'deskripsi' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kamar_id.required' => 'Kamar wajib dipilih.',
            'kamar_id.exists' => 'Kamar tidak ditemukan.',
            'deskripsi.required' => 'Kerusakannya wajib dijelaskan.',
            'deskripsi.min' => 'Jelaskan kerusakannya sedikit lebih lengkap (minimal 5 huruf).',
            'deskripsi.max' => 'Penjelasan kerusakan maksimal 2000 huruf.',
        ];
    }
}
