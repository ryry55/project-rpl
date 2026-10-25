<?php

namespace App\Domain\Pembayaran;

use App\Domain\Billing\HasilPembayaran;

/**
 * Akibat sebuah notifikasi gateway.
 *
 * Dibedakan dari `HasilPembayaran` karena pertanyaannya berbeda: yang ini
 * menjawab "apa yang harus dijawab ke gateway dan dicatat operator", sedangkan
 * yang itu menjawab "bagaimana status tagihannya sekarang".
 *
 * Tiga kemungkinan, dan ketiganya harus dibedakan karena jawaban HTTP-nya
 * berbeda:
 *
 *   - **dibayar**    - pembayaran tercatat. Jawab 200.
 *   - **diabaikan**  - tidak ada yang perlu dilakukan (status `pending`,
 *                      notifikasi ganda, nominal tidak cocok). Jawab 200 juga,
 *                      tetapi alasannya dicatat. Menjawab selain 200 akan
 *                      membuat gateway mengulang terus-menerus.
 *   - **perlu tindakan** - uang diterima gateway tetapi tidak bisa dicatat
 *                      (mis. tagihan keburu lunas lewat jalur lain). Jawab 200
 *                      supaya tidak diulang, tetapi operator harus membaca
 *                      lognya.
 */
final class HasilNotifikasi
{
    private function __construct(
        public readonly string $keadaan,
        public readonly string $alasan,
        public readonly ?HasilPembayaran $pembayaran = null,
    ) {}

    public static function dibayar(HasilPembayaran $hasil): self
    {
        return new self('dibayar', 'Pembayaran tercatat.', $hasil);
    }

    public static function diabaikan(string $alasan): self
    {
        return new self('diabaikan', $alasan);
    }

    public static function perluTindakan(string $alasan): self
    {
        return new self('perlu_tindakan', $alasan);
    }

    public function tercatat(): bool
    {
        return $this->keadaan === 'dibayar';
    }
}
