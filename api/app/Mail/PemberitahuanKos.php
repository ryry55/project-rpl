<?php

namespace App\Mail;

use App\Domain\Notifikasi\Peristiwa;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email satu peristiwa (T25).
 *
 * Dikirim **setelah** transaksi perubahan selesai, bukan di dalamnya. Kalau
 * dikirim di dalam, SMTP yang menggantung akan menahan transaksi ubah tarif,
 * dan SMTP yang gagal akan membatalkan perubahan tarif itu sendiri - perubahan
 * yang sah batal karena email tidak terkirim. Email adalah kesopanan.
 *
 * Teks polos, bukan HTML. Alasannya bukan kemalasan: email HTML memerlukan
 * templat yang harus diuji di banyak klien, dan isi email ini hanya tiga baris
 * yang menjelaskan satu kejadian. Teks polos tidak bisa tampil rusak.
 */
class PemberitahuanKos extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $peristiwa,
        public readonly string $judul,
        public readonly string $pesan,
        public readonly ?string $tautan = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Kos-Kosan] '.$this->judul,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'email.pemberitahuan',
            with: [
                'judul' => $this->judul,
                'pesan' => $this->pesan,
                'label' => Peristiwa::label($this->peristiwa),
                'tautan' => $this->tautan,
                'alamat' => rtrim((string) config('app.url'), '/'),
            ],
        );
    }
}
