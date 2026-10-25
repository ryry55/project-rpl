<?php

namespace App\Domain\Pembayaran;

use App\Domain\Billing\PembayaranDitolak;
use App\Domain\Billing\PencatatPembayaran;
use App\Domain\Billing\PenghitungDenda;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Models\TransaksiGateway;
use App\Support\WaktuSistem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pembayaran daring lewat Midtrans (T22).
 *
 * Dua pekerjaan yang sengaja berada di kelas yang sama karena keduanya harus
 * memakai aturan yang sama:
 *
 *   1. **Membuat transaksi** - penghuni menekan "Bayar daring", kita minta
 *      tautan ke gateway, dan menyimpannya.
 *   2. **Menangani notifikasi** - gateway memberi tahu bahwa uangnya masuk,
 *      dan kita mencatat pembayaran.
 *
 * Kalau keduanya dipisah, aturan "nominal yang dicocokkan adalah nominal yang
 * diminta" akan ditulis dua kali, dan salinan kedua selalu yang terlupa.
 *
 * **Kenapa webhook tidak langsung memanggil `Pembayaran::create`.** Karena
 * pencatatan pembayaran punya aturan yang tidak boleh diduplikasi: sisa
 * dihitung dari `SUM`, tagihan dikunci `FOR UPDATE`, kelebihan ditolak, status
 * diturunkan ulang, `total_final` dibekukan, dan jejak audit ditulis. Semua itu
 * ada di {@see PencatatPembayaran}. Webhook yang menulis sendiri akan menjadi
 * jalur kedua yang perlahan menyimpang dari jalur pertama.
 *
 * **Kenapa nominal dicocokkan, bukan dipercaya.** Notifikasi membawa
 * `gross_amount`. Kalau kita mencatat sebesar nilai itu tanpa memeriksa, sebuah
 * notifikasi sah untuk transaksi Rp 1.000 pada tagihan Rp 1.500.000 akan
 * tercatat sebagai pembayaran Rp 1.000 - benar secara akuntansi, tetapi hampir
 * pasti bukan yang dimaksud. Yang lebih berbahaya: `gross_amount` yang lebih
 * besar dari sisa akan ditolak `PencatatPembayaran` dan meninggalkan uang yang
 * sudah diterima gateway tetapi tidak tercatat di mana pun. Karena itu nominal
 * yang diminta **dibekukan** saat transaksi dibuat, dan notifikasi hanya
 * diterima bila jumlahnya sama persis.
 */
class PembayaranGateway
{
    public function __construct(
        private readonly MidtransGateway $gateway,
        private readonly PencatatPembayaran $pencatat,
        private readonly PenghitungDenda $penghitung,
        private readonly WaktuSistem $waktu,
    ) {}

    /**
     * Buat transaksi baru untuk sebuah tagihan, atau kembalikan yang sudah ada.
     *
     * Mengembalikan yang sudah ada, bukan membuat baru: gateway menolak
     * `order_id` berulang, dan penghuni yang menekan Bayar dua kali tidak boleh
     * mendapat dua tautan untuk tagihan yang sama.
     *
     * @return array{transaksi: TransaksiGateway, dibuat: bool}
     *
     * @throws GatewayTidakSiap
     * @throws GatewayMenolak
     * @throws PembayaranDitolak
     */
    public function mulai(Tagihan $tagihan): array
    {
        if (! $this->gateway->siap()) {
            throw GatewayTidakSiap::kunciKosong();
        }

        if ($tagihan->status === Tagihan::STATUS_DIBATALKAN) {
            throw PembayaranDitolak::dibatalkan();
        }

        if ($tagihan->status === Tagihan::STATUS_LUNAS) {
            throw PembayaranDitolak::sudahLunas($tagihan->totalDibayar());
        }

        // Transaksi yang masih menunggu dipakai ulang. Tautannya masih berlaku
        // sampai `kedaluwarsa_pada`; membuat yang baru hanya akan ditolak
        // gateway karena `order_id`-nya berbeda tetapi tagihannya sama.
        $menunggu = TransaksiGateway::query()
            ->where('tagihan_id', $tagihan->id)
            ->where('status', TransaksiGateway::STATUS_MENUNGGU)
            ->first();

        if ($menunggu !== null && $menunggu->redirect_url !== null) {
            return ['transaksi' => $menunggu, 'dibuat' => false];
        }

        // Nominal yang diminta: sisa tagihan HARI INI, termasuk denda berjalan.
        // Dibekukan ke baris transaksi supaya notifikasi nanti dicocokkan
        // dengan angka ini, bukan dengan angka yang mungkin sudah berubah.
        $jumlah = $this->penghitung->hitung($tagihan)->total() - $tagihan->totalDibayar();

        if ($jumlah <= 0) {
            throw PembayaranDitolak::sudahLunas($tagihan->totalDibayar());
        }

        $orderId = $this->buatOrderId($tagihan);

        $transaksi = TransaksiGateway::create([
            'tagihan_id' => $tagihan->id,
            'order_id' => $orderId,
            'jumlah' => $jumlah,
            'status' => TransaksiGateway::STATUS_MENUNGGU,
            'kedaluwarsa_pada' => $this->waktu->sekarang()->addMinutes(
                (int) config('services.midtrans.kedaluwarsa_menit', 60)
            ),
        ]);

        $penghuni = $tagihan->kontrak?->penghuni;

        $hasil = $this->gateway->buatTransaksi(
            orderId: $orderId,
            jumlah: $jumlah,
            namaPenyewa: $penghuni?->nama ?? 'Penghuni',
            emailPenyewa: $penghuni?->email,
            kedaluwarsaMenit: (int) config('services.midtrans.kedaluwarsa_menit', 60),
        );

        $transaksi->update([
            'token' => $hasil['token'],
            'redirect_url' => $hasil['redirect_url'],
        ]);

        return ['transaksi' => $transaksi->fresh(), 'dibuat' => true];
    }

    /**
     * Tangani notifikasi gateway.
     *
     * @param  array<string, mixed>  $notifikasi
     *
     * @throws TandaTanganTidakSah
     */
    public function tanganiNotifikasi(array $notifikasi): HasilNotifikasi
    {
        $orderId = (string) ($notifikasi['order_id'] ?? '');
        $statusCode = (string) ($notifikasi['status_code'] ?? '');
        $grossAmount = (string) ($notifikasi['gross_amount'] ?? '');
        $signature = (string) ($notifikasi['signature_key'] ?? '');

        if (! $this->gateway->tandaTanganSah($orderId, $statusCode, $grossAmount, $signature)) {
            // TIDAK dicatat ke log_audit: baris itu append-only dan tidak bisa
            // dihapus, sedangkan notifikasi palsu bisa dikirim siapa saja yang
            // tahu alamat endpoint. Membiarkannya menulis ke tabel permanen
            // berarti siapa pun bisa mengisi riwayat audit kos ini.
            throw new TandaTanganTidakSah($orderId);
        }

        $transaksi = TransaksiGateway::query()->where('order_id', $orderId)->first();

        if ($transaksi === null) {
            // Tanda tangan sah tetapi order_id tidak dikenal. Bisa terjadi bila
            // database dipulihkan dari backup yang lebih lama daripada
            // transaksinya. Dicatat sebagai peringatan, bukan galat: gateway
            // akan mengulang, dan kita tidak boleh menjawab 500 terus-menerus.
            Log::warning('midtrans.order_tidak_dikenal', ['order_id' => $orderId]);

            return HasilNotifikasi::diabaikan('order_id tidak dikenal');
        }

        $transactionStatus = (string) ($notifikasi['transaction_status'] ?? '');
        $fraudStatus = isset($notifikasi['fraud_status']) ? (string) $notifikasi['fraud_status'] : null;

        // --- Uang diterima -------------------------------------------------
        if ($this->gateway->statusBerartiDibayar($transactionStatus, $fraudStatus)) {
            return $this->catatDibayar($transaksi, $notifikasi, $grossAmount);
        }

        // --- Tidak akan dibayar --------------------------------------------
        if ($this->gateway->statusBerartiGagal($transactionStatus)) {
            // Transaksi yang sudah `dibayar` tidak boleh mundur menjadi gagal.
            // Midtrans kadang mengirim `expire` untuk transaksi lama setelah
            // `settlement`; menurunkannya akan membuat tagihan yang sudah lunas
            // tampak belum dibayar.
            if ($transaksi->status === TransaksiGateway::STATUS_DIBAYAR) {
                return HasilNotifikasi::diabaikan('transaksi sudah dibayar, status gagal diabaikan');
            }

            $transaksi->update([
                'status' => $transactionStatus === 'expire'
                    ? TransaksiGateway::STATUS_KEDALUWARSA
                    : TransaksiGateway::STATUS_GAGAL,
                'transaction_id' => (string) ($notifikasi['transaction_id'] ?? '') ?: null,
                'transaction_status' => $transactionStatus,
                'fraud_status' => $fraudStatus,
            ]);

            return HasilNotifikasi::diabaikan('status gateway: '.$transactionStatus);
        }

        // `pending` dan status lain: dicatat apa adanya, tanpa mengubah tagihan.
        $transaksi->update([
            'transaction_id' => (string) ($notifikasi['transaction_id'] ?? '') ?: null,
            'transaction_status' => $transactionStatus,
            'fraud_status' => $fraudStatus,
        ]);

        return HasilNotifikasi::diabaikan('status gateway: '.$transactionStatus);
    }

    /**
     * @param  array<string, mixed>  $notifikasi
     */
    private function catatDibayar(TransaksiGateway $transaksi, array $notifikasi, string $grossAmount): HasilNotifikasi
    {
        // Idempoten. Midtrans mengirim ulang notifikasi yang sama sampai
        // menerima 200, dan jaringan bisa menggandakannya. Tanpa pemeriksaan
        // ini, satu notifikasi ganda menjadi dua baris pembayaran - dan
        // pembayaran tidak bisa dihapus, hanya dibalik dengan alasan.
        if ($transaksi->status === TransaksiGateway::STATUS_DIBAYAR) {
            return HasilNotifikasi::diabaikan('transaksi sudah dibayar sebelumnya');
        }

        // Nominal harus sama persis dengan yang diminta. Perbedaan satu rupiah
        // pun berarti notifikasi ini bukan untuk transaksi yang kita buat.
        $diminta = (int) $transaksi->jumlah;
        $diterima = (int) round((float) $grossAmount);

        if ($diminta !== $diterima) {
            Log::warning('midtrans.nominal_tidak_cocok', [
                'order_id' => $transaksi->order_id,
                'diminta' => $diminta,
                'diterima' => $diterima,
            ]);

            return HasilNotifikasi::diabaikan(sprintf(
                'nominal tidak cocok (diminta %d, diterima %d)', $diminta, $diterima,
            ));
        }

        try {
            $hasil = DB::transaction(function () use ($transaksi, $notifikasi) {
                $tagihan = Tagihan::query()->whereKey($transaksi->tagihan_id)->firstOrFail();

                $hasil = $this->pencatat->catat(
                    tagihan: $tagihan,
                    jumlah: (int) $transaksi->jumlah,
                    tanggalPembayaran: $this->waktu->sekarang(),
                    metode: MetodePembayaran::GATEWAY,
                    // Tidak ada pemegang uang manusia: uangnya masuk ke rekening
                    // merchant, bukan ke tangan pengelola. `diterima_oleh` null
                    // adalah pernyataan yang benar, bukan data yang hilang.
                    diterimaOleh: null,
                    referensi: $transaksi->order_id,
                    catatan: 'Pembayaran daring (Midtrans)',
                    sumber: MetodePembayaran::SUMBER_GATEWAY_WEBHOOK,
                );

                $transaksi->update([
                    'status' => TransaksiGateway::STATUS_DIBAYAR,
                    'transaction_id' => (string) ($notifikasi['transaction_id'] ?? '') ?: null,
                    'transaction_status' => (string) ($notifikasi['transaction_status'] ?? ''),
                    'fraud_status' => isset($notifikasi['fraud_status'])
                        ? (string) $notifikasi['fraud_status']
                        : null,
                    'dibayar_pada' => $this->waktu->sekarang(),
                ]);

                return $hasil;
            });
        } catch (PembayaranDitolak $e) {
            // Tagihan sudah lunas lewat jalur lain (mis. pengelola mencatat
            // transfer pada saat yang sama). Uangnya tetap diterima gateway,
            // jadi ini harus tercatat sebagai peringatan yang dibaca operator -
            // bukan dibuang diam-diam.
            Log::warning('midtrans.pembayaran_ditolak', [
                'order_id' => $transaksi->order_id,
                'alasan' => $e->getMessage(),
            ]);

            $transaksi->update([
                'status' => TransaksiGateway::STATUS_DIBAYAR,
                'transaction_status' => (string) ($notifikasi['transaction_status'] ?? ''),
                'dibayar_pada' => $this->waktu->sekarang(),
            ]);

            return HasilNotifikasi::perluTindakan($e->getMessage());
        } catch (QueryException $e) {
            // 23505 = unique violation. Terjadi bila dua notifikasi yang sama
            // tiba benar-benar bersamaan dan keduanya lolos pemeriksaan status
            // di atas. Yang kedua kalah di basis data, dan itu hasil yang benar.
            if ($e->getCode() === '23505') {
                return HasilNotifikasi::diabaikan('notifikasi ganda (kalah balapan)');
            }

            throw $e;
        }

        return HasilNotifikasi::dibayar($hasil);
    }

    /**
     * `order_id` unik. Bentuknya menyebut tagihan supaya bisa dibaca manusia
     * saat menelusuri dashboard gateway, dan mengandung cap waktu supaya
     * percobaan kedua setelah kedaluwarsa tidak bentrok dengan yang pertama.
     */
    private function buatOrderId(Tagihan $tagihan): string
    {
        return sprintf(
            'KOS-%d-%s',
            $tagihan->id,
            $this->waktu->sekarang()->format('YmdHis'),
        );
    }
}
