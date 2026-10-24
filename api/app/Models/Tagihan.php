<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tagihan extends Model
{
    use HasFactory;

    public const STATUS_BELUM_BAYAR = 'belum_bayar';

    public const STATUS_SEBAGIAN = 'sebagian';

    public const STATUS_LUNAS = 'lunas';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $table = 'tagihan';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_BELUM_BAYAR,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kontrak_id',
        'periode',
        'pokok',
        'total_final',
        'jatuh_tempo',
        'status',
        'tarif_terpakai',
        'kebijakan_terpakai',
        'tarif_denda_persen',
        'plafon_persen',
        'masa_tenggang',
        'terbit_pada',
        'alasan_batal',
        'dibatalkan_oleh',
        'dibatalkan_pada',
        'denda_dimaafkan',
        'alasan_maaf_denda',
        'denda_dimaafkan_oleh',
        'denda_dimaafkan_pada',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'periode' => 'date',
            'jatuh_tempo' => 'date',
            'pokok' => 'decimal:0',
            'total_final' => 'decimal:0',
            'tarif_terpakai' => 'array',
            // Dokumen beku kebijakan (T17). `array` dipakai, bukan model
            // tersendiri, karena isinya arsip: bentuknya harus boleh berbeda
            // antar waktu tanpa migrasi.
            'kebijakan_terpakai' => 'array',
            'tarif_denda_persen' => 'decimal:2',
            'plafon_persen' => 'decimal:2',
            'masa_tenggang' => 'integer',
            'terbit_pada' => 'datetime',
            'dibatalkan_pada' => 'datetime',
            'denda_dimaafkan' => 'decimal:0',
            'denda_dimaafkan_pada' => 'datetime',
        ];
    }

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class);
    }

    /**
     * Batasi query ke tagihan milik satu penghuni (R-07).
     *
     * Penyaringan dilakukan **di query**, bukan dengan memeriksa hasil setelah
     * diambil. Kalau tagihan milik orang lain tidak pernah dimuat, tidak ada
     * kesempatan untuk membocorkannya lewat resource, serialisasi, atau
     * `whenLoaded` yang lupa diperiksa.
     *
     * Ditulis sebagai scope di model, bukan sebagai `if` di tiap controller:
     * isolasi yang disalin ke setiap endpoint akan lupa diterapkan pada endpoint
     * yang ditambahkan belakangan, dan tidak ada yang menyadarinya sampai ada
     * kebocoran.
     */
    public function scopeMilikPenghuni(Builder $query, int $penghuniId): Builder
    {
        return $query->whereHas('kontrak', fn (Builder $q) => $q->where('penghuni_id', $penghuniId));
    }

    /**
     * Batasi query ke tagihan yang boleh dilihat pengguna ini (R-07).
     *
     * Satu tempat untuk seluruh jalur baca. Kalau aturannya ditulis ulang di
     * tiap controller — `if ($user->isPenghuni()) { ... }` — maka satu endpoint
     * baru yang lupa menyalinnya langsung menjadi kebocoran, dan tidak ada yang
     * menyadarinya sampai ada yang membuka URL milik orang lain.
     *
     * Penghuni tanpa `penghuni_id` (akun yatim) tidak melihat apa pun: id 0
     * tidak pernah cocok dengan kontrak mana pun. Itu lebih aman daripada
     * melempar exception, karena akun seperti itu tetap bisa login.
     *
     * Pengelola ikut lolos seluruhnya sejak T24. Itu bukan pelonggaran R-07:
     * pengelola memang berhak melihat semua data **milik pemiliknya**, dan
     * karena satu instalasi hanya melayani satu kos, "milik pemiliknya" adalah
     * seluruh isi basis data ini. Yang tetap tidak boleh adalah penghuni
     * melihat tagihan penghuni lain - dan jalur itu tidak berubah.
     */
    public function scopeTerlihatOleh(Builder $query, User $user): Builder
    {
        if ($user->mengurusKos()) {
            return $query;
        }

        return $query->milikPenghuni((int) ($user->penghuni_id ?? 0));
    }

    public function rincian(): HasMany
    {
        return $this->hasMany(TagihanRincian::class)->orderBy('urutan');
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(Pembayaran::class)->orderBy('tanggal_pembayaran')->orderBy('id');
    }

    public function pembatal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibatalkan_oleh');
    }

    public function pemaafDenda(): BelongsTo
    {
        return $this->belongsTo(User::class, 'denda_dimaafkan_oleh');
    }

    /**
     * Tanggal saat pokok tagihan ini terbayar penuh, atau null bila belum.
     *
     * Dipakai `PenghitungDenda` untuk membekukan denda pada hari pokoknya lunas
     * (REV-FIN-07). Sebelum ini denda terus bertambah sampai plafon meski
     * pokoknya sudah dibayar penuh — dan itu bukan lagi menagih keterlambatan,
     * itu menagih sesuatu yang sudah dibayar.
     *
     * **Denda dibekukan, bukan dinolkan.** Membuatnya nol akan memberi hadiah
     * kepada penghuni yang menunda pokok 100 hari: ia membayar pokoknya, lalu
     * seluruh dendanya lenyap. Yang benar adalah denda berhenti bertambah pada
     * hari pokoknya lunas, sebesar nilai pada hari itu.
     *
     * Ditelusuri dari baris pembayaran berurutan tanggal, bukan dari kolom
     * penanda: penanda bisa basi setelah pembalikan, dan penanda basi tentang
     * uang lebih buruk daripada satu query tambahan.
     *
     * Jalur cepat: bila `totalDibayar()` belum mencapai pokok, tidak perlu
     * menelusuri apa pun. Itu keadaan hampir semua tagihan, sehingga daftar
     * tagihan tidak membayar apa-apa untuk fitur ini.
     */
    public function tanggalPokokLunas(): ?CarbonImmutable
    {
        $pokok = (int) $this->pokok;

        if ($pokok <= 0 || $this->totalDibayar() < $pokok) {
            return null;
        }

        // Baris dibaca berurutan tanggal. Pembalik bernilai negatif dan ikut
        // terjumlah, sehingga pembayaran yang ditarik kembali menunda tanggal
        // lunasnya — persis seperti yang terjadi sebenarnya.
        $kumulatif = 0;

        foreach ($this->pembayaran()->get(['jumlah', 'tanggal_pembayaran']) as $baris) {
            $kumulatif += (int) $baris->jumlah;

            if ($kumulatif >= $pokok) {
                return CarbonImmutable::parse($baris->tanggal_pembayaran)->startOfDay();
            }
        }

        // Jumlahnya sudah cukup tetapi tidak ada baris yang melewatinya: hanya
        // mungkin bila `pokok` nol atau barisnya hilang. Mengembalikan null
        // membuat denda tetap berjalan — pilihan yang salah dengan arah yang
        // aman, karena menagih lebih sedikit tidak bisa dituntut.
        return null;
    }

    /** Total uang yang sudah diterima untuk tagihan ini. */
    public function totalDibayar(): int
    {
        // Kalau pemanggil sudah memuat `withSum('pembayaran as total_dibayar')`,
        // pakai itu. Tanpa jalur ini, daftar 30 tagihan menjalankan 30 query
        // penjumlahan — dan halaman daftar jadi lambat justru karena fitur
        // pembayaran.
        if (array_key_exists('total_dibayar', $this->attributes)) {
            return (int) ($this->attributes['total_dibayar'] ?? 0);
        }

        // Dijumlahkan di basis data, bukan di PHP: daftar pembayaran bisa
        // panjang, dan menjumlahkan seluruh baris hanya untuk satu angka
        // membuat layar memuat puluhan ribu baris.
        return (int) $this->pembayaran()->sum('jumlah');
    }
}
