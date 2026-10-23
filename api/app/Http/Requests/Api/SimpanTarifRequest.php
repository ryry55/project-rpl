<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi satu baris tarif sewa.
 *
 * Aturannya dikumpulkan di `aturan()` yang menerima array masukan, bukan dibaca
 * dari `$this->input()` di dalam `rules()`. Itu bukan kerapian: sejak T26
 * aturan yang sama dipakai dua jalur - pemilik yang menetapkan langsung, dan
 * pengelola yang mengusulkan - dan salinan kedua akan tertinggal begitu salah
 * satu jalur menambah aturan. Gejalanya adalah usulan yang lolos saat diajukan
 * tetapi gagal saat disetujui, dan tidak ada yang tahu siapa yang salah.
 */
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
        return static::aturan($this->all());
    }

    /**
     * Aturan yang sama, dibangun dari array masukan apa pun.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function aturan(array $data): array
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
                    ->where('kamar_id', (int) ($data['kamar_id'] ?? 0)),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return static::pesan();
    }

    /**
     * @return array<string, string>
     */
    public static function pesan(): array
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
