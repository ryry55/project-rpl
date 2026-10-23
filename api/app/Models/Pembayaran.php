<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Satu pembayaran yang diterima (proses 4.0).
 *
 * Tabel ini **append-only**; trigger basis data menolak UPDATE dan DELETE.
 * Model ini karena itu tidak menyediakan jalur perubahan — memperbaiki salah
 * input dilakukan dengan baris pembalik, bukan dengan menyunting yang lama.
 *
 * `jumlah` selalu positif. Uang yang diterima tidak pernah negatif; kelebihan
 * bayar ditolak sebelum sempat tersimpan.
 *
 * Sejak T20 baris ini juga menjawab **bagaimana** uangnya masuk (`metode`),
 * **siapa yang mengetiknya** (`dicatat_oleh`), dan **siapa yang memegang
 * uangnya** (`diterima_oleh`). Tiga pertanyaan berbeda yang dulu dijawab satu
 * kolom "catatan" - dan catatan teks bebas tidak bisa dijumlahkan.
 *
 * `metode` nullable **hanya** untuk baris lama yang dibuat sebelum kolomnya
 * ada. Baris baru wajib mengisinya; aturan itu dijaga validasi HTTP dan CHECK
 * `pembayaran_tunai_ada_pemegang` di basis data. Model ini tidak menegakkannya
 * sendiri karena seeder dan tes perlu bisa membuat baris lama untuk menguji
 * justru perilaku "tanpa metode" itu.
 *
 * @property int $id
 * @property int $tagihan_id
 * @property string $jumlah
 * @property string|null $metode
 * @property string $sumber
 * @property string|null $referensi
 * @property int|null $dicatat_oleh
 * @property int|null $diterima_oleh
 * @property Carbon $tanggal_pembayaran
 * @property string|null $catatan
 */
class Pembayaran extends Model
{
    use HasFactory;

    protected $table = 'pembayaran';

    /** Tidak ada `updated_at`: baris pembayaran tidak pernah berubah. */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tagihan_id',
        'jumlah',
        'metode',
        'sumber',
        'referensi',
        'dicatat_oleh',
        'diterima_oleh',
        'tanggal_pembayaran',
        'catatan',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jumlah' => 'decimal:0',
            'tanggal_pembayaran' => 'date',
        ];
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(Tagihan::class);
    }

    /** Siapa yang mengetik baris ini. */
    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh');
    }

    /**
     * Siapa yang memegang uangnya.
     *
     * Untuk tunai, ini pertanyaan yang sebenarnya: setoran mana yang masih di
     * tangan pengelola. Untuk transfer, QRIS, dan gateway, kolomnya kosong
     * karena uangnya sudah di rekening sejak awal - tidak ada yang memegang.
     */
    public function penerima(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diterima_oleh');
    }

    /**
     * Seluruh pemeriksaan atas baris ini, terlama dulu.
     *
     * Urutan menaik dipilih supaya `->last()` memberi pemeriksaan yang
     * berlaku tanpa perlu membalik urutan di setiap pemanggil.
     */
    public function verifikasi(): HasMany
    {
        return $this->hasMany(VerifikasiPembayaran::class)->orderBy('id');
    }

    /**
     * Status verifikasi: baris pemeriksaan terakhir menentukan.
     *
     * `null` berarti belum pernah diperiksa - bukan "ditolak", dan bukan
     * "diterima". Membedakan ketiganya penting: rekap hanya boleh menganggap
     * uang tunai sudah disetor setelah ada pemeriksaan yang menerimanya.
     */
    public function statusVerifikasi(): ?string
    {
        return $this->verifikasi->last()?->keputusan;
    }
}
