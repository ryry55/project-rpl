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
            'nama' => ['required', 'string', 'max:80'],
            'nilai' => ['required', 'integer', 'min:0'],
            'satuan' => ['nullable', 'string', 'max:30'],
            'berlaku_dari' => [
                'required',
                'date_format:Y-m-d',
                Rule::unique('komponen_biaya', 'berlaku_dari')
                    ->where('nama', $this->string('nama')->value()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.required' => 'Nama komponen wajib diisi.',
            'nilai.required' => 'Nilai komponen wajib diisi.',
            'nilai.integer' => 'Nilai harus angka bulat rupiah.',
            'nilai.min' => 'Nilai tidak boleh negatif. Untuk menghentikan komponen, isi 0.',
            'berlaku_dari.required' => 'Tanggal berlaku wajib diisi.',
            'berlaku_dari.unique' => 'Komponen dengan nama dan tanggal berlaku itu sudah ada.',
        ];
    }
}
