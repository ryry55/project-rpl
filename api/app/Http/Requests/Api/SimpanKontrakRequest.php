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
            'tgl_masuk' => ['required', 'date', 'before_or_equal:today'],
            // Tarif saat menandatangani kontrak. Boleh dikosongkan bila kamar
            // sudah punya tarif yang berlaku pada tanggal masuk — dalam hal itu
            // tarif yang sudah ada yang dipakai, bukan tarif baru.
            'tarif' => ['nullable', 'integer', 'min:0'],
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
            'tgl_masuk.before_or_equal' => 'Tanggal masuk tidak boleh di masa depan.',
            'tarif.integer' => 'Tarif harus angka bulat rupiah, tanpa desimal.',
            'tarif.min' => 'Tarif tidak boleh negatif.',
            'deposit.integer' => 'Deposit harus berupa angka bulat rupiah.',
            'deposit.min' => 'Deposit tidak boleh negatif.',
        ];
    }
}
