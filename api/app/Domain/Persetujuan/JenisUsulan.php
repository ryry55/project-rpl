<?php

namespace App\Domain\Persetujuan;

use App\Domain\Akses\Wewenang;
use App\Domain\Audit\PencatatAudit;
use App\Domain\Kebijakan\PilihanKebijakan;
use App\Models\Kamar;
use App\Models\UsulanPerubahan;

/**
 * Tiga hal yang boleh diusulkan pengelola (T26, REV-SEC-07).
 *
 * Daftar tertutup, seperti {@see Wewenang}. Menambah tipe usulan berarti
 * menambah cara baru untuk menyentuh angka uang, dan itu harus lewat kode yang
 * memeriksanya - bukan lewat baris di tabel yang tampil di layar tetapi tidak
 * dipahami siapa pun.
 *
 * Setiap tipe membawa tiga hal yang harus setuju satu sama lain: wewenang yang
 * dituntut untuk mengusulkannya, aksi audit yang dicatat, dan kalimat ringkasan
 * yang dibaca pemilik saat memutuskan. Menaruhnya berdekatan membuat
 * penambahan tipe berikutnya tidak bisa lupa salah satunya.
 */
final class JenisUsulan
{
    public const TARIF = UsulanPerubahan::TIPE_TARIF;

    public const KEBIJAKAN = UsulanPerubahan::TIPE_KEBIJAKAN;

    /**
     * Komponen biaya kamar (REV-SEC-07).
     *
     * Wewenangnya `ubah_tarif`, bukan wewenang baru. Komponen biaya adalah
     * angka uang yang ditagihkan setiap bulan - pekerjaan yang sama dengan
     * tarif, pada kolom yang berbeda. Membuat wewenang `ubah_komponen` berarti
     * semua akun pengelola yang sudah ada kehilangan haknya setelah migrasi,
     * dan kegagalan seperti itu tidak menimbulkan galat apa pun.
     */
    public const KOMPONEN = UsulanPerubahan::TIPE_KOMPONEN;

    /**
     * @var list<string>
     */
    public const SEMUA = [self::TARIF, self::KEBIJAKAN, self::KOMPONEN];

    public static function dikenal(string $tipe): bool
    {
        return in_array($tipe, self::SEMUA, true);
    }

    /**
     * Wewenang yang harus dimiliki pengelola untuk mengusulkan tipe ini.
     *
     * Perhatikan apa yang TIDAK berubah: wewenangnya tetap bernama `ubah_tarif`.
     * Nama itu sekarang berarti "boleh mengusulkan perubahan tarif", dan
     * pemilik adalah satu-satunya yang boleh menetapkannya. Mengganti nama
     * wewenangnya menjadi `usul_tarif` akan membuat akun pengelola yang sudah
     * ada kehilangan wewenangnya setelah migrasi - kegagalan yang senyap dan
     * sulit dijelaskan.
     */
    public static function wewenang(string $tipe): string
    {
        return match ($tipe) {
            self::TARIF => Wewenang::UBAH_TARIF,
            self::KEBIJAKAN => Wewenang::UBAH_KEBIJAKAN,
            // Komponen biaya ikut `ubah_tarif`: keduanya menetapkan berapa
            // rupiah yang ditagihkan, hanya pada kolom yang berbeda.
            self::KOMPONEN => Wewenang::UBAH_TARIF,
            default => throw new \UnexpectedValueException("Tipe usulan \"{$tipe}\" tidak dikenal."),
        };
    }

    /**
     * Aksi audit saat usulan diajukan, dan saat diputuskan.
     *
     * Nilainya diambil dari konstanta {@see PencatatAudit}, bukan ditulis
     * sebagai teks di sini. Dua tempat yang menyebut nama aksi yang sama akan
     * menyimpang begitu salah satunya berganti nama, dan penyimpangannya tidak
     * menimbulkan galat - hanya log yang tidak lagi bisa disaring.
     */
    public static function aksiUsul(string $tipe): string
    {
        return match ($tipe) {
            self::TARIF => PencatatAudit::AKSI_TARIF_USUL,
            self::KEBIJAKAN => PencatatAudit::AKSI_KEBIJAKAN_USUL,
            self::KOMPONEN => PencatatAudit::AKSI_KOMPONEN_USUL,
            default => throw new \UnexpectedValueException("Tipe usulan \"{$tipe}\" tidak dikenal."),
        };
    }

    public static function aksiSetujui(string $tipe): string
    {
        return match ($tipe) {
            self::TARIF => PencatatAudit::AKSI_TARIF_SETUJUI,
            self::KEBIJAKAN => PencatatAudit::AKSI_KEBIJAKAN_SETUJUI,
            self::KOMPONEN => PencatatAudit::AKSI_KOMPONEN_SETUJUI,
            default => throw new \UnexpectedValueException("Tipe usulan \"{$tipe}\" tidak dikenal."),
        };
    }

    public static function aksiTolak(string $tipe): string
    {
        return match ($tipe) {
            self::TARIF => PencatatAudit::AKSI_TARIF_TOLAK,
            self::KEBIJAKAN => PencatatAudit::AKSI_KEBIJAKAN_TOLAK,
            self::KOMPONEN => PencatatAudit::AKSI_KOMPONEN_TOLAK,
            default => throw new \UnexpectedValueException("Tipe usulan \"{$tipe}\" tidak dikenal."),
        };
    }

    public static function label(string $tipe): string
    {
        return match ($tipe) {
            self::TARIF => 'Tarif sewa',
            self::KEBIJAKAN => 'Kebijakan kos',
            self::KOMPONEN => 'Komponen biaya',
            default => $tipe,
        };
    }

    /**
     * Kalimat yang dibaca pemilik di layar persetujuan.
     *
     * Disusun di sini dan **dibekukan** ke kolom `ringkasan`, bukan disusun
     * ulang setiap kali dibaca. Alasannya bukan kemalasan: kamar bisa berganti
     * nomor, dan usulan yang dibaca ulang akan menceritakan kamar yang berbeda
     * dari yang disetujui.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function ringkasan(string $tipe, array $payload): string
    {
        return match ($tipe) {
            self::TARIF => self::ringkasanTarif($payload),
            self::KEBIJAKAN => self::ringkasanKebijakan($payload),
            self::KOMPONEN => self::ringkasanKomponen($payload),
            default => throw new \UnexpectedValueException("Tipe usulan \"{$tipe}\" tidak dikenal."),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function ringkasanTarif(array $payload): string
    {
        $nomor = self::nomorKamar($payload['kamar_id'] ?? null);

        return sprintf(
            'Tarif kamar %s menjadi Rp %s mulai %s.',
            $nomor,
            number_format((int) ($payload['nilai'] ?? 0), 0, ',', '.'),
            self::tanggalIndonesia($payload['berlaku_dari'] ?? null),
        );
    }

    private static function nomorKamar(mixed $kamarId): string
    {
        if ($kamarId === null) {
            return '—';
        }

        // Dibaca apa adanya, tanpa cache: ini hanya dipanggil sekali saat usulan
        // dibuat, bukan di dalam perulangan.
        return Kamar::query()->whereKey($kamarId)->value('nomor') ?? "id {$kamarId}";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function ringkasanKebijakan(array $payload): string
    {
        $tipeKebijakan = (string) ($payload['tipe'] ?? '');
        $nilai = (string) ($payload['nilai'] ?? '');

        $label = PilihanKebijakan::labelNilai($tipeKebijakan, $nilai);
        $cakupan = isset($payload['kamar_id']) && $payload['kamar_id'] !== null
            ? 'kamar '.self::nomorKamar($payload['kamar_id'])
            : 'seluruh kos';

        return sprintf(
            '%s untuk %s menjadi "%s" mulai %s.',
            PilihanKebijakan::labelTipe($tipeKebijakan),
            $cakupan,
            $label,
            self::tanggalIndonesia($payload['berlaku_dari'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function ringkasanKomponen(array $payload): string
    {
        $nama = (string) ($payload['nama'] ?? '');
        $nilai = (int) ($payload['nilai'] ?? 0);
        $satuan = trim((string) ($payload['satuan'] ?? ''));

        // Cakupan disebut eksplisit. "Biaya kebersihan Rp 50.000" tanpa
        // keterangan kamar mana akan dibaca pemilik sebagai biaya untuk seluruh
        // kos - dan itu perbedaan yang menentukan berapa tagihan yang berubah.
        $cakupan = isset($payload['kamar_id']) && $payload['kamar_id'] !== null
            ? 'kamar '.self::nomorKamar($payload['kamar_id'])
            : 'seluruh kamar';

        return sprintf(
            'Komponen biaya "%s" sebesar Rp %s%s untuk %s mulai %s.',
            $nama,
            number_format($nilai, 0, ',', '.'),
            $satuan !== '' ? ' per '.$satuan : '',
            $cakupan,
            self::tanggalIndonesia($payload['berlaku_dari'] ?? null),
        );
    }

    /**
     * Tanggal ISO menjadi bentuk yang dibaca orang, tanpa `Carbon::parse` yang
     * melempar exception untuk masukan yang tidak terduga.
     */
    private static function tanggalIndonesia(mixed $iso): string
    {
        if (! is_string($iso) || $iso === '') {
            return '—';
        }

        $bulan = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];

        $bagian = explode('-', $iso);

        if (count($bagian) !== 3) {
            return $iso;
        }

        return sprintf(
            '%d %s %s',
            (int) $bagian[2],
            $bulan[(int) $bagian[1]] ?? $bagian[1],
            $bagian[0],
        );
    }
}
