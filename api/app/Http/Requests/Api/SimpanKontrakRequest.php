<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimpanKontrakRequest extends FormRequest
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
            'kamar_id' => ['required', 'integer', Rule::exists('kamar', 'id')],
            'penghuni_id' => ['required', 'integer', Rule::exists('penghuni', 'id')],
            // S1.9 (REV-FIN-04): tanggal masuk di masa depan DIIZINKAN, supaya
            // pemilik bisa mencatat penghuni yang sudah menyanggupi kamar
            // untuk bulan depan tanpa harus menunggu harinya tiba.
            //
            // Batas atas tetap ada, tetapi jauh: satu tahun. Tanpa batas sama
            // sekali, salah ketik tahun (2027 menjadi 2072) menghasilkan
            // kontrak yang tidak akan pernah ditagih dan tidak terlihat salah
            // di daftar. Satu tahun cukup untuk reservasi wajar dan menahan
            // salah ketik yang paling mungkin terjadi.
            'tgl_masuk' => ['required', 'date', 'before_or_equal:'.now()->addYear()->toDateString()],
            // Tarif saat menandatangani kontrak. Boleh dikosongkan bila kamar
            // sudah punya tarif yang berlaku pada tanggal masuk — dalam hal itu
            // tarif yang sudah ada yang dipakai, bukan tarif baru.
            'tarif' => ['nullable', 'integer', 'min:0'],
            // Deposit kosong berarti "ikuti kebijakan kos", bukan "nol".
            // `KontrakController::depositUntuk()` membedakan keduanya lewat
            // `filled()`, jadi klien yang mengirim 0 untuk medan kosong akan
            // mematikan kebijakan deposit tanpa sadar.
            'deposit' => ['nullable', 'integer', 'min:0'],
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
            'penghuni_id.required' => 'Penghuni wajib dipilih.',
            'penghuni_id.exists' => 'Penghuni tidak ditemukan.',
            'tgl_masuk.required' => 'Tanggal masuk wajib diisi.',
            'tgl_masuk.before_or_equal' => 'Tanggal masuk terlalu jauh di masa depan (maksimal satu tahun).',
            'tarif.integer' => 'Tarif harus angka bulat rupiah, tanpa desimal.',
            'tarif.min' => 'Tarif tidak boleh negatif.',
            'deposit.integer' => 'Deposit harus berupa angka bulat rupiah.',
            'deposit.min' => 'Deposit tidak boleh negatif.',
        ];
    }
}
