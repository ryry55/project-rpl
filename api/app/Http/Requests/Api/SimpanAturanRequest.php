<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SimpanAturanRequest extends FormRequest
{
    /**
     * Kategori yang disarankan.
     *
     * Bukan enum di basis data: pemilik kos mengelompokkan aturannya dengan
     * cara masing-masing, dan daftar tertutup akan menolak kategori yang wajar
     * hanya karena tidak ada di kode. Daftar ini dipakai untuk saran di layar,
     * bukan untuk memvalidasi.
     */
    public const KATEGORI_UMUM = [
        'umum',
        'kebersihan',
        'keamanan',
        'tamu',
        'pembayaran',
        'lainnya',
    ];

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
            'judul' => ['required', 'string', 'max:120'],
            'isi' => ['required', 'string', 'max:5000'],
            'kategori' => ['sometimes', 'string', 'max:40'],
            'sanksi' => ['nullable', 'string', 'max:200'],
            'aktif' => ['sometimes', 'boolean'],
            'urutan' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'judul.required' => 'Judul aturan wajib diisi.',
            'judul.max' => 'Judul aturan maksimal 120 karakter.',
            'isi.required' => 'Isi aturan wajib diisi.',
            'isi.max' => 'Isi aturan maksimal 5000 karakter.',
        ];
    }
}
