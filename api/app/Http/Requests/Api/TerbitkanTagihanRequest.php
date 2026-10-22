<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TerbitkanTagihanRequest extends FormRequest
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
            'kontrak_id' => ['required', 'integer', Rule::exists('kontrak', 'id')],
            'periode' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kontrak_id.required' => 'Kontrak wajib dipilih.',
            'kontrak_id.exists' => 'Kontrak tidak ditemukan.',
            'periode.required' => 'Periode wajib diisi.',
            'periode.date_format' => 'Format periode harus YYYY-MM-DD.',
        ];
    }
}
