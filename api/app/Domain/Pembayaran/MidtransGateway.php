<?php

namespace App\Domain\Pembayaran;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Jembatan ke Midtrans (T22).
 *
 * **Kenapa kelas ini terpisah dari controller.** Dua hal yang paling mudah
 * salah pada integrasi payment gateway adalah (a) membangun tanda tangan
 * notifikasi dan (b) memutuskan kapan uang dianggap masuk. Keduanya bukan
 * urusan HTTP. Menaruhnya di controller berarti setiap endpoint baru yang
 * menyentuh gateway harus menyalinnya, dan salinan kedua selalu yang lupa
 * diperbarui saat aturan berubah.
 *
 * **Kenapa verifikasi tanda tangan dilakukan di sini, bukan di middleware.**
 * Middleware tidak tahu `server_key` mana yang dipakai untuk transaksi tertentu
 * dan tidak bisa membedakan sandbox dari produksi tanpa membaca konfigurasi
 * yang sama. Menyimpannya di satu tempat membuat hanya ada satu jalan.
 *
 * **Bentuk tanda tangan notifikasi Midtrans:**
 *
 *     SHA512(order_id + status_code + gross_amount + server_key)
 *
 * Ketiga nilai pertama datang dari badan notifikasi itu sendiri, jadi
 * penyerang yang mengirim notifikasi palsu bisa memilih ketiganya - tetapi
 * tidak bisa menghitung hash tanpa `server_key`. Karena itu perbandingannya
 * memakai `hash_equals` (waktu tetap), bukan `===`: perbandingan biasa
 * membocorkan berapa karakter awal yang sudah benar lewat selisih waktu, dan
 * pada nilai rahasia itu cukup untuk menebak sedikit demi sedikit.
 *
 * **Yang tidak dilakukan kelas ini:** memutuskan tagihan mana yang dibayar.
 * Itu urusan pemanggil, karena hanya pemanggil yang tahu `order_id` berasal
 * dari tagihan mana. Gateway yang "tahu" tentang tagihan akan mencampur dua
 * tanggung jawab yang berbeda.
 */
class MidtransGateway
{
    public function __construct(
        private readonly ?string $serverKey,
        private readonly ?string $clientKey,
        private readonly bool $produksi,
    ) {}

    public static function dariKonfigurasi(): self
    {
        return new self(
            serverKey: config('services.midtrans.server_key'),
            clientKey: config('services.midtrans.client_key'),
            produksi: (bool) config('services.midtrans.is_production'),
        );
    }

    /**
     * Apakah gateway bisa dipakai. Diperiksa pemanggil SEBELUM membuat
     * transaksi, supaya kegagalan konfigurasi muncul sebagai 503 dengan
     * penjelasan, bukan sebagai galat HTTP dari Midtrans yang membingungkan.
     */
    public function siap(): bool
    {
        return is_string($this->serverKey) && trim($this->serverKey) !== '';
    }

    public function clientKey(): ?string
    {
        return $this->clientKey;
    }

    /** Alamat Snap: sandbox dan produksi memakai host yang berbeda. */
    public function alamatSnap(): string
    {
        return $this->produksi
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
    }

    /**
     * Buat transaksi Snap dan kembalikan tautan pembayarannya.
     *
     * `order_id` wajib unik dan tidak boleh dipakai ulang: Midtrans menolak
     * order_id yang sudah pernah dipakai, dan itu justru pengaman yang
     * diinginkan - satu tagihan tidak bisa dibayar dua kali lewat gateway.
     *
     * `keterangan` menjadi `item_details`, supaya penghuni melihat apa yang ia
     * bayar di halaman gateway (aliran O-8). Midtrans menolak transaksi bila
     * `price x quantity` tidak sama dengan `gross_amount`, jadi harga item
     * WAJIB diturunkan dari nominal yang sama - bukan dihitung ulang.
     *
     * @return array{token: string, redirect_url: string}
     *
     * @throws GatewayTidakSiap
     * @throws GatewayMenolak
     */
    public function buatTransaksi(
        string $orderId,
        int $jumlah,
        string $namaPenyewa,
        ?string $emailPenyewa = null,
        ?int $kedaluwarsaMenit = null,
        ?string $keterangan = null,
    ): array {
        if (! $this->siap()) {
            throw GatewayTidakSiap::kunciKosong();
        }

        $badan = [
            'transaction_details' => [
                'order_id' => $orderId,
                // Midtrans memakai rupiah tanpa desimal. Dikirim sebagai int,
                // bukan float: 1500000.0 bisa terkirim sebagai "1.5e6" pada
                // encoder tertentu, dan gateway menolaknya.
                'gross_amount' => $jumlah,
            ],
            'customer_details' => array_filter([
                'first_name' => $namaPenyewa,
                'email' => $emailPenyewa,
            ], fn ($v) => $v !== null && $v !== ''),
        ];

        if ($keterangan !== null && trim($keterangan) !== '') {
            $badan['item_details'] = [[
                'id' => $orderId,
                'price' => $jumlah,
                'quantity' => 1,
                // Batas `name` di Midtrans 50 karakter; dipotong di sini,
                // bukan di pemanggil, karena ini batas gateway.
                'name' => mb_substr(trim($keterangan), 0, 50),
            ]];
        }

        if ($kedaluwarsaMenit !== null && $kedaluwarsaMenit > 0) {
            $badan['expiry'] = ['unit' => 'minutes', 'duration' => $kedaluwarsaMenit];
        }

        $jawaban = Http::withBasicAuth((string) $this->serverKey, '')
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post($this->alamatSnap(), $badan);

        if (! $jawaban->successful()) {
            // Badan jawaban Midtrans memuat `error_messages`. Ia dicatat, tetapi
            // TIDAK dikembalikan apa adanya ke klien: pesan itu menyebut nama
            // parameter internal dan kadang `server_key` yang salah, dan itu
            // bukan informasi yang perlu diketahui penghuni.
            Log::warning('midtrans.buat_transaksi_gagal', [
                'status' => $jawaban->status(),
                'pesan' => $jawaban->json('error_messages'),
                'order_id' => $orderId,
            ]);

            throw GatewayMenolak::dariJawaban($jawaban->status(), $jawaban->json('error_messages'));
        }

        $token = $jawaban->json('token');
        $redirect = $jawaban->json('redirect_url');

        if (! is_string($token) || $token === '') {
            Log::warning('midtrans.token_kosong', ['order_id' => $orderId]);

            throw GatewayMenolak::tanpaToken();
        }

        return ['token' => $token, 'redirect_url' => (string) $redirect];
    }

    /**
     * Verifikasi tanda tangan notifikasi.
     *
     * Nilai yang dibandingkan dihitung ulang dari `server_key` yang kita
     * pegang, bukan dari yang dikirim pemanggil. Inilah satu-satunya hal yang
     * membuat endpoint webhook bisa dibuka tanpa autentikasi: tanpa kunci,
     * notifikasi palsu tidak bisa dibuat.
     */
    public function tandaTanganSah(
        string $orderId,
        string $statusCode,
        string $grossAmount,
        string $signatureKey,
    ): bool {
        if (! $this->siap() || $signatureKey === '') {
            return false;
        }

        $harapan = hash('sha512', $orderId.$statusCode.$grossAmount.(string) $this->serverKey);

        return hash_equals($harapan, $signatureKey);
    }

    /**
     * Status transaksi yang berarti uang benar-benar sudah diterima.
     *
     * `settlement` adalah pembayaran yang sudah lunas. `capture` muncul untuk
     * kartu kredit dan **belum tentu** lunas - ia bisa berstatus `challenge`
     * karena pemeriksaan risiko. Karena itu `capture` hanya diterima bila
     * `fraud_status`-nya `accept`; memperlakukannya sebagai lunas tanpa syarat
     * berarti memberi penghuni status "sudah bayar" untuk transaksi yang masih
     * ditahan bank.
     */
    public function statusBerartiDibayar(string $transactionStatus, ?string $fraudStatus): bool
    {
        if ($transactionStatus === 'settlement') {
            return true;
        }

        if ($transactionStatus === 'capture') {
            return $fraudStatus === null || $fraudStatus === 'accept';
        }

        return false;
    }

    /**
     * Status yang berarti transaksi tidak akan pernah dibayar.
     *
     * `expire` dan `cancel` adalah dua yang paling sering. `deny` muncul untuk
     * kartu yang ditolak. Ketiganya hanya dicatat sebagai kegagalan, bukan
     * sebagai pembayaran - dan yang penting, tidak mengubah tagihan apa pun.
     */
    public function statusBerartiGagal(string $transactionStatus): bool
    {
        return in_array($transactionStatus, ['expire', 'cancel', 'deny', 'failure'], true);
    }
}
