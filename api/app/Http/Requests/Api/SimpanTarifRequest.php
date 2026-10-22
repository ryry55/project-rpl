<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimpanTarifRequest extends FormRequest
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
            'nilai' => ['required', 'integer', 'min:0'],
            'berlaku_dari' => [
                'required',
                'date_format:Y-m-d',
                // Unik per kamar: dua tarif mulai tanggal sama membuat "tarif
                // efektif" tidak terdefinisi.
                Rule::unique('tarif_sewa', 'berlaku_dari')
                    ->where('kamar_id', $this->integer('kamar_id')),
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
            'nilai.required' => 'Nilai tarif wajib diisi.',
            'nilai.integer' => 'Tarif harus angka bulat rupiah, tanpa desimal.',
            'nilai.min' => 'Tarif tidak boleh negatif.',
            'berlaku_dari.required' => 'Tanggal berlaku wajib diisi.',
            'berlaku_dari.date_format' => 'Format tanggal harus YYYY-MM-DD.',
            'berlaku_dari.unique' => 'Kamar ini sudah punya tarif dengan tanggal berlaku tersebut.',
        ];
    }
}
