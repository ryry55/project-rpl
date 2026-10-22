<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimpanPenghuniRequest extends FormRequest
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
        $penghuni = $this->route('penghuni');
        $id = $penghuni?->id;

        return [
            'nama' => ['required', 'string', 'max:120'],
            'email' => [
                'required',
                'email',
                'max:120',
                Rule::unique('penghuni', 'email')->ignore($id),
            ],
            'no_hp' => ['nullable', 'string', 'max:30'],
            // Kata sandi hanya wajib saat membuat baru. Saat mengubah data induk,
            // kata sandi tidak diubah.
            'password' => [$penghuni ? 'nullable' : 'required', 'string', 'min:6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.required' => 'Nama penghuni wajib diisi.',
            'email.required' => 'Email wajib diisi karena dipakai untuk masuk.',
            'email.unique' => 'Email sudah dipakai penghuni lain.',
            'password.required' => 'Kata sandi wajib diisi.',
            'password.min' => 'Kata sandi minimal 6 karakter.',
        ];
    }
}
