<?php

namespace App\Models;

use App\Domain\Pembayaran\PembalikPembayaran;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Satu pembayaran yang diterima (proses 4.0).
 *
 * Tabel ini **append-only**; trigger basis data menolak UPDATE dan DELETE.
 * Model ini karena itu tidak menyediakan jalur perubahan — memperbaiki salah
 * input dilakukan dengan baris pembalik, bukan dengan menyunting yang lama.
 *
 * `jumlah` positif untuk uang yang masuk. Sejak REV-FIN-01 satu-satunya
 * pengecualian adalah **baris pembalik**: baris negatif yang menunjuk baris
 * asalnya lewat `pembalik_id`, dan hanya boleh ditulis oleh
 * {@see PembalikPembayaran}. Tandanya dijaga CHECK di
 * basis data, jadi tidak ada baris positif yang bisa menyamar sebagai pembalik
 * atau sebaliknya.
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
 * @property int|null $pembalik_id
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
        'pembalik_id',
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

    /**
     * Baris yang ditarik kembali oleh baris ini.
     *
     * Hanya terisi pada baris pembalik. Arahnya satu arah: pembalik menunjuk
     * asalnya, asal tidak menunjuk pembaliknya - hubungan sebaliknya dibaca
     * lewat query `where('pembalik_id', $id)`, karena satu pembayaran hanya
     * boleh punya satu pembalik.
     */
    public function asal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'pembalik_id');
    }

    /**
     * Baris pembalik yang menarik kembali baris ini, bila ada.
     *
     * Satu arah, dan paling banyak satu: unique index `pembalik_id` menjamin
     * tidak ada dua pembalik untuk satu pembayaran.
     */
    public function pembalik(): HasOne
    {
        return $this->hasOne(self::class, 'pembalik_id');
    }

    /** Apakah baris ini pembalik (uang keluar), bukan pembayaran (uang masuk). */
    public function adalahPembalik(): bool
    {
        return $this->pembalik_id !== null;
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
