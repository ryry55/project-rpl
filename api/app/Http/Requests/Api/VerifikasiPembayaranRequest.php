<?php

namespace App\Http\Requests\Api;

use App\Models\VerifikasiPembayaran;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi pemeriksaan satu pembayaran.
 *
 * Aturan yang butuh data pembayaran - sudah diperiksa atau belum - tidak ada di
 * sini. Aturan itu milik `PencatatVerifikasi`, di dalam transaksi yang sama
 * dengan penulisannya.
 *
 * `catatan` wajib untuk penolakan dan opsional untuk penerimaan. Aturan itu
 * ditulis di sini **dan** sebagai CHECK di basis data. Dua lapis bukan
 * kemubaziran: validasi memberi pesan yang bisa dibaca pengguna, CHECK menjaga
 * jalur tulis lain (seeder, perintah artisan, SQL langsung) yang tidak melewati
 * FormRequest sama sekali.
 */
class VerifikasiPembayaranRequest extends FormRequest
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
            'keputusan' => ['required', 'string', Rule::in([
                VerifikasiPembayaran::KEPUTUSAN_TERIMA,
                VerifikasiPembayaran::KEPUTUSAN_TOLAK,
            ])],
            // `required_if` dipilih daripada `required` bersyarat di kode:
            // pesannya otomatis menyebut bidang yang salah, dan layarnya sudah
            // punya tempat untuk galat per bidang.
            'catatan' => ['nullable', 'required_if:keputusan,tolak', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'keputusan.required' => 'Keputusan verifikasi wajib dipilih.',
            'keputusan.in' => 'Keputusan verifikasi harus "terima" atau "tolak".',
            'catatan.required_if' => 'Penolakan harus menyebutkan alasannya.',
            'catatan.max' => 'Catatan terlalu panjang (maksimal 255 karakter).',
        ];
    }
}
