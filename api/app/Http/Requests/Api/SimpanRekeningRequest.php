<?php

namespace App\Http\Requests\Api;

use App\Models\Rekening;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimpanRekeningRequest extends FormRequest
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
        // `sometimes` dipakai untuk seluruh medan, bukan `required`, karena
        // permintaan yang sama melayani POST (tambah) dan PUT (ubah). Yang
        // membedakan kelengkapan keduanya adalah `nama`, yang memang wajib di
        // dua-duanya: rekening tanpa nama tidak bisa ditampilkan bermakna.
        //
        // `gambar` hanya diperiksa bentuk luarnya di sini (berkas, ukuran
        // maksimal). Isi sebenarnya diperiksa `PenyimpanGambarQris` lewat
        // `getimagesize`, karena aturan `image` Laravel bergantung pada
        // ekstensi dan `mimes` pada tebakan - keduanya bisa dikelabui dengan
        // mengganti nama berkas.
        return [
            'jenis' => ['sometimes', 'string', Rule::in(Rekening::SEMUA_JENIS)],
            'nama' => ['required', 'string', 'max:60'],
            'nomor' => ['nullable', 'string', 'max:60'],
            'atas_nama' => ['nullable', 'string', 'max:80'],
            'catatan' => ['nullable', 'string', 'max:200'],
            'gambar' => ['nullable', 'file', 'max:2048'],
            'hapus_gambar' => ['sometimes', 'boolean'],
            'aktif' => ['sometimes', 'boolean'],
            'urutan' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama.required' => 'Sebutkan nama bank atau penyedia QRIS-nya.',
            'nama.max' => 'Nama bank atau penyedia maksimal 60 karakter.',
            'jenis.in' => 'Jenis rekening harus transfer atau qris.',
            'nomor.max' => 'Nomor rekening maksimal 60 karakter.',
            'atas_nama.max' => 'Nama pemilik rekening maksimal 80 karakter.',
            'catatan.max' => 'Catatan maksimal 200 karakter.',
            // Pesan ini menyebut batasnya dalam MB, bukan kB, karena yang
            // dibaca pemilik adalah ukuran berkas di ponselnya.
            'gambar.max' => 'Gambar maksimal 2 MB. Foto QRIS dari ponsel biasanya di bawah 400 kB.',
            'gambar.file' => 'Gambar gagal diunggah. Coba pilih ulang berkasnya.',
        ];
    }
}
