<?php

namespace App\Http\Requests\Api;

use App\Domain\Pembayaran\MetodePembayaran;
use App\Support\WaktuSistem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validasi pembayaran yang bisa diputuskan tanpa menyentuh basis data.
 *
 * Aturan yang butuh data tagihan — melebihi sisa, sudah lunas, dibatalkan —
 * **tidak** ada di sini. Aturan itu milik `PencatatPembayaran`, di dalam
 * transaksi yang sama dengan penulisannya. Kalau diperiksa di sini, pemeriksaan
 * itu berjalan di luar kunci baris dan hasilnya bisa basi sebelum sempat
 * menulis.
 */
class CatatPembayaranRequest extends FormRequest
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
            // `integer` menolak "1500000.5" dan "1.500.000" sekaligus: uang
            // disimpan sebagai rupiah bulat, dan menerima desimal di sini berarti
            // membulatkannya diam-diam di suatu tempat nanti.
            'jumlah' => ['required', 'integer', 'min:1'],
            'tanggal_pembayaran' => ['required', 'date_format:Y-m-d'],
            // Wajib sejak T20. Tanpa metode, baris pembayaran tidak bisa
            // dimasukkan ke rekap mana pun, dan rekap yang mencampur tunai
            // dengan transfer tidak berguna untuk rekonsiliasi bank.
            'metode' => ['required', 'string', Rule::in(MetodePembayaran::SEMUA)],
            'referensi' => ['nullable', 'string', 'max:100'],
            'diterima_oleh' => ['nullable', 'integer', 'exists:users,id'],
            'catatan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'jumlah.required' => 'Jumlah pembayaran wajib diisi.',
            'jumlah.integer' => 'Jumlah pembayaran harus bilangan bulat rupiah, tanpa desimal atau titik.',
            'jumlah.min' => 'Jumlah pembayaran harus lebih dari nol.',
            'tanggal_pembayaran.required' => 'Tanggal pembayaran wajib diisi.',
            'tanggal_pembayaran.date_format' => 'Format tanggal pembayaran harus YYYY-MM-DD.',
            'metode.required' => 'Metode pembayaran wajib dipilih.',
            'metode.in' => 'Metode pembayaran harus salah satu dari: tunai, transfer, qris, gateway.',
            'referensi.max' => 'Nomor referensi terlalu panjang (maksimal 100 karakter).',
            'diterima_oleh.exists' => 'Akun penerima uang tidak ditemukan.',
            'catatan.max' => 'Catatan terlalu panjang (maksimal 255 karakter).',
        ];
    }

    /**
     * Tanggal pembayaran tidak boleh di masa depan.
     *
     * Memakai "hari ini" dari {@see WaktuSistem}, bukan `now()`, supaya aturan
     * ini ikut bergeser saat mode demo memakai simulasi tanggal. Tanpa itu,
     * demo pada tanggal simulasi 2026-11-21 akan menolak pembayaran bertanggal
     * 2026-11-21 sebagai "masa depan" — karena jam dinding bilang Oktober.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('tanggal_pembayaran')) {
                return;
            }

            $hariIni = app(WaktuSistem::class)->hariIni()->toDateString();
            $tanggal = (string) $this->input('tanggal_pembayaran');

            // Perbandingan string sah untuk format Y-m-d dan tidak melempar
            // exception pada masukan aneh — berbeda dengan parse tanggal yang
            // bisa melempar pada Carbon 3.
            if ($tanggal > $hariIni) {
                $validator->errors()->add(
                    'tanggal_pembayaran',
                    "Tanggal pembayaran tidak boleh di masa depan. Hari ini {$hariIni}.",
                );
            }
        });
    }
}
