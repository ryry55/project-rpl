<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SimpanParameterDendaRequest extends FormRequest
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
            'tarif_persen' => ['required', 'numeric', 'min:0', 'max:100'],
            'plafon_persen' => ['required', 'numeric', 'min:0', 'max:100'],
            'masa_tenggang' => ['required', 'integer', 'min:0', 'max:365'],
            'berlaku_dari' => ['required', 'date_format:Y-m-d', 'unique:parameter_denda,berlaku_dari'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tarif_persen.required' => 'Tarif denda wajib diisi.',
            'tarif_persen.max' => 'Tarif denda maksimal 100% per hari.',
            'plafon_persen.required' => 'Plafon denda wajib diisi.',
            'plafon_persen.max' => 'Plafon denda maksimal 100%.',
            'masa_tenggang.required' => 'Masa tenggang wajib diisi.',
            'berlaku_dari.required' => 'Tanggal berlaku wajib diisi.',
            'berlaku_dari.unique' => 'Sudah ada parameter denda dengan tanggal berlaku tersebut.',
        ];
    }
}
