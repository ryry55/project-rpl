<?php

namespace App\Notifications;

use App\Domain\Notifikasi\Peristiwa;
use App\Mail\PemberitahuanKos;
use Illuminate\Notifications\Notification;

/**
 * Satu notifikasi in-app (T25).
 *
 * Kanalnya **hanya** `database`. Email tidak lewat kelas ini, dan pemisahan itu
 * disengaja: `notify()` menjalankan seluruh kanal `via()` dalam satu panggilan,
 * sehingga notifikasi ber-kanal ganda akan mengirim SMTP di tempat yang sama
 * dengan INSERT-nya. Notifikasi ini dipanggil **di dalam transaksi** perubahan,
 * jadi SMTP yang menggantung 30 detik akan menahan transaksi ubah tarif - dan
 * SMTP yang gagal akan membatalkan perubahan tarif itu sendiri. Email adalah
 * kesopanan, bukan syarat sahnya perubahan.
 *
 * Email dikirim terpisah lewat {@see PemberitahuanKos} setelah
 * transaksi selesai.
 */
class NotifikasiKos extends Notification
{
    /**
     * @param  array<string, mixed>  $tautan  mis. ['nama' => 'pemilik-usulan']
     */
    public function __construct(
        public readonly string $peristiwa,
        public readonly string $judul,
        public readonly string $pesan,
        public readonly ?string $tautan = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'peristiwa' => $this->peristiwa,
            'label_peristiwa' => Peristiwa::label($this->peristiwa),
            'judul' => $this->judul,
            'pesan' => $this->pesan,
            'tautan' => $this->tautan,
        ];
    }
}
