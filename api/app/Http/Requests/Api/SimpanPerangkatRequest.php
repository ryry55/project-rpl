<?php

namespace App\Http\Requests\Api;

use App\Models\Perangkat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi perangkat kos (K-16).
 *
 * `sandi` boleh kosong saat mengubah: formulir yang membiarkan kolomnya kosong
 * berarti "jangan ganti sandinya", bukan "hapus sandinya". Tanpa aturan ini,
 * setiap kali pemilik memperbaiki letak perangkat, sandinya ikut terhapus -
 * dan penghuni yang meminta sandi WiFi sesudahnya tidak mendapat apa-apa.
 */
class SimpanPerangkatRequest extends FormRequest
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
            'jenis' => ['sometimes', 'string', Rule::in(Perangkat::SEMUA_JENIS)],
            'nama' => ['required', 'string', 'min:2', 'max:60'],
            'lokasi' => ['nullable', 'string', 'max:80'],
            'nama_pengguna' => ['nullable', 'string', 'max:80'],
            // Sandi tidak diwajibkan: kartu RFID hanya punya nomor, dan
            // perangkat lama kadang hanya dicatat keberadaannya.
            'sandi' => ['nullable', 'string', 'max:120'],
            'catatan' => ['nullable', 'string', 'max:200'],
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
            'jenis.in' => 'Jenis perangkat harus WiFi, CCTV, kartu RFID, atau lainnya.',
            'nama.required' => 'Nama perangkat wajib diisi.',
            'nama.min' => 'Nama perangkat minimal 2 huruf.',
            'nama.max' => 'Nama perangkat maksimal 60 huruf.',
            'lokasi.max' => 'Letak perangkat maksimal 80 huruf.',
            'nama_pengguna.max' => 'Nama pengguna atau SSID maksimal 80 huruf.',
            'sandi.max' => 'Sandi maksimal 120 huruf.',
            'catatan.max' => 'Catatan maksimal 200 huruf.',
            'urutan.integer' => 'Urutan harus angka bulat.',
        ];
    }
}
