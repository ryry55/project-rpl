<?php

namespace App\Http\Requests\Api;

use App\Models\LampiranKerusakan;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi laporan kerusakan.
 *
 * Dua jalur memakai aturan yang sama: pemilik/pengelola yang melaporkan kamar
 * mana pun (`kamar_id` dari formulir), dan penghuni yang melaporkan kamarnya
 * sendiri (`kamar_id` diambil server dari kontrak aktif). Karena itu aturan isi
 * laporan dipisah ke {@see aturanIsi()} - menyalinnya ke dua tempat berarti dua
 * batas foto yang cepat atau lambat berbeda.
 *
 * Batas foto diambil dari `LampiranKerusakan`, sumber yang sama yang dipakai
 * layar. `max` untuk berkas dihitung dalam kilobita, sedangkan konstanta itu
 * dalam byte.
 */
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
        ] + self::aturanIsi();
    }

    /**
     * Aturan isi laporan, tanpa `kamar_id`.
     *
     * @return array<string, mixed>
     */
    public static function aturanIsi(): array
    {
        return [
            // Lima huruf, bukan satu: "rusak" sudah memenuhi, sedangkan satu
            // huruf yang terkirim karena tombol tertekan tidak bisa dipakai
            // siapa pun untuk mencari kerusakannya.
            'deskripsi' => ['required', 'string', 'min:5', 'max:2000'],

            // `image` memeriksa isi berkas lewat `getimagesize`, dan `mimes`
            // mempersempitnya ke tiga jenis yang diputuskan pemilik. Tanpa
            // `mimes`, `image` masih menerima SVG - dokumen yang bisa memuat
            // skrip.
            'foto' => ['nullable', 'array', 'max:'.LampiranKerusakan::MAKS_PER_LAPORAN],
            'foto.*' => [
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.(LampiranKerusakan::BATAS_UKURAN / 1024),
            ],
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
        ] + self::pesanIsi();
    }

    /**
     * @return array<string, string>
     */
    public static function pesanIsi(): array
    {
        return [
            'deskripsi.required' => 'Kerusakannya wajib dijelaskan.',
            'deskripsi.min' => 'Jelaskan kerusakannya sedikit lebih lengkap (minimal 5 huruf).',
            'deskripsi.max' => 'Penjelasan kerusakan maksimal 2000 huruf.',
            'foto.max' => 'Maksimal '.LampiranKerusakan::MAKS_PER_LAPORAN.' foto per laporan.',
            'foto.*.image' => 'Setiap lampiran harus berupa gambar.',
            'foto.*.mimes' => 'Foto harus berjenis JPG, PNG, atau WEBP.',
            'foto.*.max' => 'Setiap foto maksimal 5 MB.',
            'foto.*.uploaded' => 'Foto gagal diunggah. Biasanya karena ukurannya melebihi batas server.',
        ];
    }
}
