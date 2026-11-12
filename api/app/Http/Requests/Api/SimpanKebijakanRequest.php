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
 *
 * Sejak T26 aturannya dibangun dari array masukan (`aturan()`), bukan dari
 * `$this->input()`. Jalur usulan memvalidasi payload yang datang sebagai
 * kiriman biasa sebelum disimpan, dan jalur persetujuan tidak memvalidasi ulang
 * — payload-nya sudah divalidasi saat diajukan.
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
        $tipe = (string) ($data['tipe'] ?? '');
        $nilai = (string) ($data['nilai'] ?? '');
        $kamarId = $data['kamar_id'] ?? null;

        // Matriks "kolom angka mana yang wajib" diambil dari satu sumber yang
        // sama dengan CHECK constraint dan dengan layar. Menyalinnya ke sini
        // berarti tiga salinan harus setuju soal bentuk denda, dan yang paling
        // sering tertinggal adalah salinan yang paling jarang dibaca.
        $kolom = PilihanKebijakan::kolomAngka($tipe, $nilai);

        // Tipe teks bebas (nama kos) tidak punya daftar nilai yang sah, jadi
        // `Rule::in` akan menolak semuanya. Batasnya panjang, bukan pilihan.
        $teks = in_array($tipe, PilihanKebijakan::tipeTeks(), true);

        return [
            'tipe' => ['required', 'string', Rule::in(PilihanKebijakan::TIPE_TERSEDIA)],
            'nilai' => $teks
                ? ['required', 'string', 'max:'.PilihanKebijakan::maksTeks($tipe)]
                : [
                    'required',
                    'string',
                    // Tanpa `tipe` yang sah, daftar pilihannya kosong dan `in`
                    // menolak semua nilai. Itu perilaku yang benar: baris dengan
                    // tipe tak dikenal memang tidak boleh tersimpan.
                    Rule::in(PilihanKebijakan::nilaiValid($tipe)),
                ],
            // NULL = kebijakan default seluruh kos; terisi = penimpa kamar itu.
            // Pola tagihan dan tanggal terbit dikecualikan: keduanya satu
            // saklar untuk seluruh kos (K-14d), dan baris per kamar akan
            // menjadi konfigurasi yang tersimpan tetapi tidak pernah dibaca
            // penerbitan.
            'kamar_id' => [
                Rule::prohibitedIf(in_array($tipe, PilihanKebijakan::tipeGlobal(), true)),
                'nullable',
                'integer',
                Rule::exists('kamar', 'id'),
            ],
            'berlaku_dari' => [
                'required',
                'date_format:Y-m-d',
                Rule::unique('kebijakan_kos', 'berlaku_dari')
                    ->where('tipe', $tipe)
                    ->where('kamar_id', $kamarId),
            ],
            // Hanya `jatuh_tempo` + `tanggal_tetap` dan `tanggal_terbit` yang
            // memakai kolom ini. Wajib diisi di sana (tanpa tanggal, "tanggal
            // tetap" tidak berarti apa pun), dan dilarang di tempat lain supaya
            // tidak ada angka menganggur yang membuat baris terbaca seolah-olah
            // dipakai. Batas atasnya dari PilihanKebijakan: 31 untuk jatuh tempo,
            // 28 untuk tanggal terbit.
            'tanggal' => [
                Rule::requiredIf(in_array('tanggal', $kolom['wajib'], true)),
                Rule::prohibitedIf(in_array('tanggal', $kolom['terlarang'], true)),
                'integer',
                'between:1,'.PilihanKebijakan::maksTanggal($tipe),
            ],

            // Kolom angka lain mengikuti matriks yang sama. `persen` dan
            // `plafon_persen` disimpan sebagai decimal(5,2): batas atasnya 100
            // karena keduanya persentase, dan plafon di atas 100% berarti
            // denda boleh melebihi pokok — bukan yang dimaksud "plafon".
            'persen' => [
                Rule::requiredIf(in_array('persen', $kolom['wajib'], true)),
                Rule::prohibitedIf(in_array('persen', $kolom['terlarang'], true)),
                'numeric',
                'gt:0',
                'max:100',
            ],
            'plafon_persen' => [
                Rule::requiredIf(in_array('plafon_persen', $kolom['wajib'], true)),
                Rule::prohibitedIf(in_array('plafon_persen', $kolom['terlarang'], true)),
                'numeric',
                'gt:0',
                'max:100',
            ],
            'masa_tenggang' => [
                Rule::requiredIf(in_array('masa_tenggang', $kolom['wajib'], true)),
                Rule::prohibitedIf(in_array('masa_tenggang', $kolom['terlarang'], true)),
                'integer',
                'min:0',
                'max:365',
            ],
            // `nominal` rupiah bulat, sama seperti kolom uang lain di proyek
            // ini. Batas atas 1 miliar cukup untuk kos mana pun dan menahan
            // salah ketik yang menambahkan satu nol terlalu banyak.
            'nominal' => [
                Rule::requiredIf(in_array('nominal', $kolom['wajib'], true)),
                Rule::prohibitedIf(in_array('nominal', $kolom['terlarang'], true)),
                'integer',
                'min:0',
                'max:1000000000',
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
            'tipe.required' => 'Jenis kebijakan wajib dipilih.',
            'tipe.in' => 'Jenis kebijakan tidak dikenal.',
            'nilai.required' => 'Pilihan kebijakan wajib diisi.',
            'nilai.in' => 'Pilihan itu tidak berlaku untuk jenis kebijakan yang dipilih.',
            'nilai.max' => 'Nama kos maksimal 60 karakter.',
            'kamar_id.exists' => 'Kamar tidak ditemukan.',
            'kamar_id.prohibited' => 'Pola tagihan, tanggal terbit, dan nama kos berlaku untuk seluruh kos, bukan per kamar.',
            'berlaku_dari.required' => 'Tanggal berlaku wajib diisi.',
            'berlaku_dari.date_format' => 'Format tanggal berlaku harus YYYY-MM-DD.',
            'berlaku_dari.unique' => 'Kebijakan untuk jenis dan tanggal berlaku itu sudah ada. Pilih tanggal lain.',
            'tanggal.required' => 'Tanggal wajib diisi untuk pilihan ini.',
            'tanggal.prohibited' => 'Tanggal hanya dipakai oleh jatuh tempo "tanggal tetap" dan tanggal terbit.',
            'tanggal.between' => 'Tanggal terbit harus di antara 1 dan 28; tanggal jatuh tempo di antara 1 dan 31.',
            'persen.required' => 'Tarif persen wajib diisi untuk denda persen.',
            'persen.prohibited' => 'Tarif persen hanya dipakai oleh denda persen.',
            'persen.numeric' => 'Tarif persen harus berupa angka.',
            'persen.gt' => 'Tarif persen harus lebih besar dari nol.',
            'persen.max' => 'Tarif persen tidak boleh melebihi 100.',
            'plafon_persen.required' => 'Plafon denda wajib diisi untuk denda persen.',
            'plafon_persen.prohibited' => 'Plafon hanya dipakai oleh denda persen.',
            'plafon_persen.numeric' => 'Plafon denda harus berupa angka.',
            'plafon_persen.gt' => 'Plafon denda harus lebih besar dari nol.',
            'plafon_persen.max' => 'Plafon denda tidak boleh melebihi 100% dari pokok.',
            'masa_tenggang.required' => 'Masa tenggang wajib diisi untuk denda persen. Isi 0 bila denda mulai sejak hari pertama.',
            'masa_tenggang.prohibited' => 'Masa tenggang hanya dipakai oleh denda persen.',
            'masa_tenggang.integer' => 'Masa tenggang harus bilangan bulat hari.',
            'masa_tenggang.min' => 'Masa tenggang tidak boleh negatif.',
            'masa_tenggang.max' => 'Masa tenggang tidak masuk akal bila lebih dari 365 hari.',
            'nominal.required' => 'Nominal wajib diisi untuk pilihan ini.',
            'nominal.prohibited' => 'Nominal hanya dipakai oleh denda nominal tetap.',
            'nominal.integer' => 'Nominal harus bilangan bulat rupiah.',
            'nominal.min' => 'Nominal tidak boleh negatif.',
            'nominal.max' => 'Nominal terlalu besar.',
        ];
    }
}
