<?php

namespace App\Http\Requests\Api;

use App\Domain\Kebijakan\PilihanKebijakan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi penyimpanan satu baris kebijakan.
 *
 * Dua lapis, disengaja. FormRequest menolak lebih dulu dengan pesan Indonesia
 * per bidang, karena itulah yang dibaca pemilik. CHECK constraint di basis data
 * tetap ada sebagai lapis kedua, karena validasi PHP hanya berlaku pada jalur
 * HTTP — seeder, perintah artisan, dan SQL langsung melewatinya.
 *
 * Aturan `nilai` bergantung pada `tipe`: "bulanan" sah untuk satuan sewa dan
 * tidak sah untuk jatuh tempo. Karena itu daftar pilihannya diambil dari
 * {@see PilihanKebijakan::menu} — sumber yang sama yang dipakai layar. Kalau
 * daftarnya disalin ke sini, cepat atau lambat satu salinan tertinggal dan
 * gejalanya adalah pilihan yang tampil di layar tetapi ditolak server.
 */
class SimpanKebijakanRequest extends FormRequest
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
        $tipe = (string) $this->input('tipe', '');

        return [
            'tipe' => ['required', 'string', Rule::in(PilihanKebijakan::TIPE_TERSEDIA)],
            'nilai' => [
                'required',
                'string',
                // Tanpa `tipe` yang sah, daftar pilihannya kosong dan `in`
                // menolak semua nilai. Itu perilaku yang benar: baris dengan
                // tipe tak dikenal memang tidak boleh tersimpan.
                Rule::in(PilihanKebijakan::nilaiValid($tipe)),
            ],
            // NULL = kebijakan default seluruh kos; terisi = penimpa kamar itu.
            'kamar_id' => ['nullable', 'integer', Rule::exists('kamar', 'id')],
            'berlaku_dari' => [
                'required',
                'date_format:Y-m-d',
                Rule::unique('kebijakan_kos', 'berlaku_dari')
                    ->where('tipe', $tipe)
                    ->where('kamar_id', $this->input('kamar_id')),
            ],
            // Hanya `jatuh_tempo` + `tanggal_tetap` yang memakai kolom ini.
            // Wajib diisi di sana (tanpa tanggal, "tanggal tetap" tidak berarti
            // apa pun), dan dilarang di tempat lain supaya tidak ada angka
            // menganggur yang membuat baris terbaca seolah-olah dipakai.
            'tanggal' => [
                Rule::requiredIf(PilihanKebijakan::butuhTanggal($tipe, (string) $this->input('nilai', ''))),
                Rule::prohibitedIf(! PilihanKebijakan::butuhTanggal($tipe, (string) $this->input('nilai', ''))),
                'integer',
                'between:1,31',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipe.required' => 'Jenis kebijakan wajib dipilih.',
            'tipe.in' => 'Jenis kebijakan tidak dikenal.',
            'nilai.required' => 'Pilihan kebijakan wajib diisi.',
            'nilai.in' => 'Pilihan itu tidak berlaku untuk jenis kebijakan yang dipilih.',
            'kamar_id.exists' => 'Kamar tidak ditemukan.',
            'berlaku_dari.required' => 'Tanggal berlaku wajib diisi.',
            'berlaku_dari.date_format' => 'Format tanggal berlaku harus YYYY-MM-DD.',
            'berlaku_dari.unique' => 'Kebijakan untuk jenis dan tanggal berlaku itu sudah ada. Pilih tanggal lain.',
            'tanggal.required' => 'Tanggal jatuh tempo wajib diisi bila memilih "tanggal tetap".',
            'tanggal.prohibited' => 'Tanggal hanya dipakai oleh jatuh tempo "tanggal tetap".',
            'tanggal.between' => 'Tanggal jatuh tempo harus di antara 1 dan 31.',
        ];
    }
}
