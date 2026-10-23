<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimpanFasilitasRequest extends FormRequest
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
        $id = $this->route('fasilitas')?->id;

        return [
            'nama' => [
                'required',
                'string',
                'max:60',
                Rule::unique('fasilitas', 'nama')->ignore($id),
            ],
            'ikon' => ['nullable', 'string', 'max:40'],
            'urutan' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.required' => 'Nama fasilitas wajib diisi.',
            'nama.unique' => 'Fasilitas dengan nama ini sudah ada.',
            'nama.max' => 'Nama fasilitas maksimal 60 karakter.',
        ];
    }
}
