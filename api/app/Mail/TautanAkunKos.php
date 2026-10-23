<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Email berisi tautan aktivasi atau reset sandi (T27).
 *
 * Kelas tersendiri, bukan `PemberitahuanKos`. Dua alasan:
 *
 *   1. Subjeknya tidak boleh berbunyi seperti notifikasi. Surat ini adalah
 *      satu-satunya jalan masuk ke akun, dan surat yang terlihat seperti
 *      pemberitahuan rutin akan diabaikan bersama dua belas surat lain.
 *   2. Isinya memuat tautan berbatas waktu. Menumpangkannya pada templat
 *      notifikasi berarti templat itu harus tahu soal tenggat - dan templat
 *      yang dipakai delapan peristiwa lain tidak perlu tahu.
 *
 * Teks polos, sama seperti notifikasi: tidak bisa tampil rusak, dan tidak
 * menyembunyikan tautan di balik gambar yang diblokir klien email.
 */
class TautanAkunKos extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $jenis,
        public readonly string $nama,
        public readonly string $tautan,
        public readonly Carbon $berlakuSampai,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->jenis === 'aktivasi'
                ? '[Kos-Kosan] Aktifkan akun Anda'
                : '[Kos-Kosan] Atur ulang kata sandi',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'email.tautan-akun',
            with: [
                'jenis' => $this->jenis,
                'nama' => $this->nama,
                'tautan' => $this->tautan,
                'berlakuSampai' => $this->berlakuSampai,
                'alamat' => rtrim((string) config('app.url'), '/'),
            ],
        );
    }
}
