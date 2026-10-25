<?php

namespace App\Domain\Pembayaran;

use RuntimeException;

/**
 * Gateway belum bisa dipakai karena konfigurasinya kosong.
 *
 * Dibedakan dari {@see GatewayMenolak} karena akibatnya berbeda: yang ini
 * kesalahan operator (kunci belum diisi) dan tidak akan sembuh dengan
 * mengulang permintaan; yang itu jawaban gateway dan bisa saja sementara.
 * Pemanggil memetakannya ke kode HTTP yang berbeda - 503 untuk yang ini,
 * 502 untuk yang itu - sehingga pesan yang dibaca penghuni tepat.
 */
class GatewayTidakSiap extends RuntimeException
{
    public static function kunciKosong(): self
    {
        return new self(
            'Pembayaran daring belum diaktifkan: MIDTRANS_SERVER_KEY belum diisi. '
            .'Hubungi pemilik kos, atau bayar melalui transfer/QRIS seperti biasa.'
        );
    }
}
