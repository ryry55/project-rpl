<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimpanKomponenBiayaRequest extends FormRequest
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
            // T11: kamar_id opsional. NULL = komponen berlaku untuk semua kamar.
            'kamar_id' => ['nullable', 'integer', 'exists:kamar,id'],
            'nama' => ['required', 'string', 'max:80'],
            'nilai' => ['required', 'integer', 'min:0'],
            'satuan' => ['nullable', 'string', 'max:30'],
            'berlaku_dari' => [
                'required',
                'date_format:Y-m-d',
                Rule::unique('komponen_biaya', 'berlaku_dari')
                    ->where('nama', $this->string('nama')->value())
                    ->where('kamar_id', $this->input('kamar_id')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kamar_id.exists' => 'Kamar yang dipilih tidak ada.',
            'nama.required' => 'Nama komponen wajib diisi.',
            'nilai.required' => 'Nilai komponen wajib diisi.',
            'nilai.integer' => 'Nilai harus angka bulat rupiah.',
            'nilai.min' => 'Nilai tidak boleh negatif. Untuk menghentikan komponen, isi 0.',
            'berlaku_dari.required' => 'Tanggal berlaku wajib diisi.',
            'berlaku_dari.unique' => 'Komponen dengan nama, tanggal berlaku, dan kamar itu sudah ada.',
        ];
    }
}
