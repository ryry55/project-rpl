<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu percobaan pembayaran lewat gateway (T22).
 *
 * Bukan pembayaran. Baris di sini mencatat bahwa penghuni **membuka tautan
 * bayar**; uangnya baru tercatat sebagai {@see Pembayaran} ketika webhook
 * menyatakan `settlement`. Pemisahan ini yang membuat pertanyaan "penghuni
 * bilang sudah bayar, kenapa tagihannya belum lunas" bisa dijawab dengan
 * menunjuk status transaksinya, bukan dengan menebak.
 */
class TransaksiGateway extends Model
{
    use HasFactory;

    protected $table = 'transaksi_gateway';

    /** Menunggu dibayar. Satu tagihan hanya boleh punya satu baris ini. */
    public const STATUS_MENUNGGU = 'menunggu';

    /** Gateway menyatakan uang sudah diterima. */
    public const STATUS_DIBAYAR = 'dibayar';

    /** Gateway menolak, atau transaksi dibatalkan di sisi gateway. */
    public const STATUS_GAGAL = 'gagal';

    /** Tautan kedaluwarsa sebelum dibayar. */
    public const STATUS_KEDALUWARSA = 'kedaluwarsa';

    /** Dibatalkan dari sisi kita (mis. tagihan dibatalkan). */
    public const STATUS_DIBATALKAN = 'dibatalkan';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tagihan_id',
        'order_id',
        'jumlah',
        'status',
        'redirect_url',
        'token',
        'transaction_id',
        'transaction_status',
        'fraud_status',
        'kedaluwarsa_pada',
        'dibayar_pada',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jumlah' => 'decimal:0',
            'kedaluwarsa_pada' => 'datetime',
            'dibayar_pada' => 'datetime',
        ];
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(Tagihan::class);
    }
}
