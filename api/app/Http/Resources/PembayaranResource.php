<?php

namespace App\Http\Resources;

use App\Domain\Pembayaran\MetodePembayaran;
use App\Models\Pembayaran;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris pembayaran.
 *
 * `jumlah` dikirim sebagai int rupiah, bukan string desimal. Cast
 * `decimal:0` mengembalikan string ("1500000"), dan mengirimkannya apa adanya
 * membuat frontend harus ingat mengubahnya sendiri — satu tempat yang lupa akan
 * menampilkan "1500000" tanpa pemisah ribuan.
 *
 * Sejak T20 resource ini membawa tiga hal yang bukan angka: metode, sumber, dan
 * hasil pemeriksaan. `verifikasi_terakhir` sengaja mengirim `null` untuk "belum
 * pernah diperiksa" - bukan `'terima'`. Layar yang membedakan "belum diperiksa"
 * dari "diterima" hanya bisa melakukannya kalau server membedakannya lebih
 * dulu; menebak di peramban berarti dua layar bisa menebak berbeda.
 *
 * @mixin Pembayaran
 */
class PembayaranResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Dibaca dari relasi yang sudah dimuat bila ada, supaya daftar
        // pembayaran tidak menjalankan satu query per baris.
        $verifikasi = $this->relationLoaded('verifikasi')
            ? $this->verifikasi->last()
            : $this->verifikasi()->latest('id')->first();

        // `metode` null hanya mungkin untuk baris yang dibuat sebelum T20.
        // Layar harus bisa mengatakan itu, bukan menampilkannya sebagai
        // "Tunai" - menganggap baris lama sebagai tunai adalah mengarang fakta
        // tentang uang.
        $metode = $this->metode;

        return [
            'id' => $this->id,
            'tagihan_id' => $this->tagihan_id,
            'jumlah' => (int) $this->jumlah,
            'tanggal_pembayaran' => $this->tanggal_pembayaran?->toDateString(),

            'metode' => $metode,
            'metode_label' => $metode === null
                ? 'Tidak tercatat'
                : (MetodePembayaran::label()[$metode] ?? $metode),
            'sumber' => $this->sumber,
            'sumber_label' => MetodePembayaran::labelSumber()[$this->sumber] ?? $this->sumber,
            'referensi' => $this->referensi,

            // Nama pemegang uang, bukan hanya idnya. Layar Tunggakan dan
            // Tagihan menampilkan "diterima Sari" tanpa perlu memuat daftar
            // pengguna lebih dulu.
            'dicatat_oleh' => $this->dicatat_oleh,
            'dicatat_oleh_nama' => $this->whenLoaded('pencatat', fn () => $this->pencatat?->name),
            'diterima_oleh' => $this->diterima_oleh,
            'diterima_oleh_nama' => $this->whenLoaded('penerima', fn () => $this->penerima?->name),

            // Tagihan yang dibayar, beserta penghuni dan kamarnya. Daftar uang
            // di layar Rekap tanpa nama penghuni hanya berisi tanggal dan angka:
            // pemilik bisa melihat "Rp 1.500.000 masuk" tanpa tahu dari siapa,
            // dan pertanyaan pertama yang muncul saat rekonsiliasi justru itu.
            //
            // Diperiksa lewat `relationLoaded('tagihan')`, bukan
            // `whenLoaded('tagihan.kontrak.penghuni')`: `whenLoaded` tidak
            // mengenal jalur bertitik, sehingga memakainya di sini akan
            // diam-diam menghilangkan ketiga kunci ini dari respons - dan
            // hilangnya field tidak menimbulkan galat apa pun.
            'penghuni_nama' => $this->relationLoaded('tagihan')
                ? $this->tagihan?->kontrak?->penghuni?->nama
                : null,
            'kamar_nomor' => $this->relationLoaded('tagihan')
                ? $this->tagihan?->kontrak?->kamar?->nomor
                : null,
            'tagihan_periode' => $this->relationLoaded('tagihan')
                ? $this->tagihan?->periode?->toDateString()
                : null,

            'verifikasi_terakhir' => $verifikasi === null ? null : [
                'id' => $verifikasi->id,
                'keputusan' => $verifikasi->keputusan,
                'catatan' => $verifikasi->catatan,
                'diverifikasi_oleh' => $verifikasi->diverifikasi_oleh,
                'diverifikasi_pada' => $verifikasi->created_at?->toIso8601String(),
            ],

            'catatan' => $this->catatan,
            'dicatat_pada' => $this->created_at?->toIso8601String(),
        ];
    }
}
