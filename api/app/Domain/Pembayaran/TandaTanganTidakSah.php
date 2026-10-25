<?php

namespace App\Domain\Pembayaran;

use RuntimeException;

/**
 * Notifikasi gateway dengan tanda tangan yang tidak sah.
 *
 * **Bukan** kegagalan yang boleh dijawab 500. Jawaban yang benar adalah 401,
 * dan tidak ada satu pun baris yang ditulis. Endpoint webhook harus terbuka
 * tanpa autentikasi - gateway tidak punya akun di aplikasi ini - sehingga
 * tanda tangan inilah satu-satunya bukti bahwa permintaan itu benar-benar dari
 * Midtrans. Tanpa pemeriksaan ini, siapa pun yang tahu alamat endpoint bisa
 * menandai tagihan mana pun sebagai lunas.
 */
class TandaTanganTidakSah extends RuntimeException
{
    public function __construct(string $orderId)
    {
        parent::__construct(sprintf(
            'Tanda tangan notifikasi tidak sah untuk order_id "%s".',
            $orderId,
        ));
    }
}
