<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Penerbitan massal hanya butuh periode.
 *
 * Tidak ada `kontrak_id`: justru seluruh kontrak aktif yang diproses. Kalau
 * pemilik ingin satu kontrak saja, endpoint `POST /tagihan` yang dipakai —
 * dan itu memang jalur yang berbeda, bukan varian dari yang ini.
 */
class TerbitkanMassalRequest extends FormRequest
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
            'periode' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'periode.required' => 'Periode wajib diisi.',
            'periode.date_format' => 'Format periode harus YYYY-MM-DD.',
        ];
    }
}
