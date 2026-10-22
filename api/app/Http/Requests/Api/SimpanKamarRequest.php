<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimpanKamarRequest extends FormRequest
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
        $id = $this->route('kamar')?->id;

        return [
            'nomor' => [
                'required',
                'string',
                'max:20',
                Rule::unique('kamar', 'nomor')->ignore($id),
            ],
            'tipe' => ['nullable', 'string', 'max:40'],
            'status_huni' => ['sometimes', Rule::in(['kosong', 'terisi'])],
            'catatan' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nomor.required' => 'Nomor kamar wajib diisi.',
            'nomor.unique' => 'Nomor kamar sudah dipakai.',
            'nomor.max' => 'Nomor kamar maksimal 20 karakter.',
        ];
    }
}
