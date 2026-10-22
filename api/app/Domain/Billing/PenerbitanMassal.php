<?php

namespace App\Domain\Billing;

use App\Models\Kontrak;
use App\Models\Tagihan;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;

/**
 * Penerbitan tagihan untuk semua kontrak aktif sekaligus (R-02).
 *
 * Bentuk hasilnya sengaja membedakan tiga nasib yang berbeda, karena pemilik
 * perlu tahu mana yang harus ditindaklanjuti dan mana yang memang begitu:
 *
 *   - **terbit**   — tagihan baru dibuat.
 *   - **dilewati** — wajar dan tidak perlu tindakan: kontrak sudah punya
 *                    tagihan periode itu, atau kontrak belum mulai pada periode
 *                    tersebut. Ini yang membuat menekan tombol dua kali aman.
 *   - **gagal**    — butuh perhatian pemilik: kamar belum punya tarif, atau
 *                    parameter denda belum ditetapkan.
 *
 * Kalau ketiganya digabung menjadi satu angka "berhasil", batch yang separuh
 * gagal karena tarif belum diisi akan tampak sama dengan batch yang berhasil
 * penuh — dan pemilik tidak akan tahu ada kamar yang tidak tertagih.
 *
 * **Satu kontrak, satu transaksi.** Kegagalan pada satu kontrak tidak boleh
 * membatalkan tagihan yang sudah terbit untuk kontrak lain. Kalau seluruh batch
 * dibungkus satu transaksi, satu kamar tanpa tarif akan menggagalkan penerbitan
 * sebulan penuh untuk semua penghuni.
 */
class PenerbitanMassal
{
    public const TERBIT = 'terbit';

    public const DILEWATI = 'dilewati';

    public const GAGAL = 'gagal';

    /** Sebab diloloskannya sebuah kontrak, dipakai menentukan kode status. */
    public const SEBAB_DUPLIKAT = 'duplikat';

    public const SEBAB_BELUM_MULAI = 'belum_mulai';

    public function __construct(private readonly PenerbitTagihan $penerbit) {}

    /**
     * @return array{
     *     periode: string,
     *     terbit: int,
     *     dilewati: int,
     *     dilewati_duplikat: int,
     *     gagal: int,
     *     rincian: list<array<string, mixed>>
     * }
     */
    public function jalankan(CarbonInterface $periode): array
    {
        $awalPeriode = $periode->copy()->startOfMonth();

        $kontrakAktif = Kontrak::query()
            ->where('status', 'aktif')
            ->with(['kamar', 'penghuni'])
            ->orderBy('id')
            ->get();

        $rincian = [];
        $terbit = 0;
        $dilewati = 0;
        $dilewatiDuplikat = 0;
        $gagal = 0;

        foreach ($kontrakAktif as $kontrak) {
            $hasil = $this->terbitkanSatu($kontrak, $awalPeriode);
            $rincian[] = $hasil;

            match ($hasil['status']) {
                self::TERBIT => $terbit++,
                self::DILEWATI => $dilewati++,
                default => $gagal++,
            };

            if ($hasil['status'] === self::DILEWATI
                && ($hasil['sebab'] ?? null) === self::SEBAB_DUPLIKAT) {
                $dilewatiDuplikat++;
            }
        }

        return [
            'periode' => $awalPeriode->toDateString(),
            'terbit' => $terbit,
            'dilewati' => $dilewati,
            // Dipisahkan dari `dilewati` karena artinya berbeda bagi pemilik:
            // duplikat berarti "sudah pernah diterbitkan", belum mulai berarti
            // "memang belum waktunya". Hanya yang pertama berarti tombol ditekan
            // dua kali.
            'dilewati_duplikat' => $dilewatiDuplikat,
            'gagal' => $gagal,
            'rincian' => $rincian,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function terbitkanSatu(Kontrak $kontrak, CarbonInterface $awalPeriode): array
    {
        $dasar = [
            'kontrak_id' => $kontrak->id,
            'penghuni' => $kontrak->penghuni?->nama,
            'kamar' => $kontrak->kamar?->nomor,
        ];

        // Kontrak yang baru mulai setelah periode berakhir tidak punya bulan
        // yang bisa ditagih. Dilewati, bukan gagal: ini keadaan yang normal
        // ketika pemilik menerbitkan untuk bulan berjalan sementara ada
        // penghuni yang baru akan masuk bulan depan.
        if ($kontrak->tgl_masuk->copy()->startOfMonth()->greaterThan($awalPeriode)) {
            return $dasar + [
                'status' => self::DILEWATI,
                'sebab' => self::SEBAB_BELUM_MULAI,
                'alasan' => sprintf(
                    'Belum mulai: tanggal masuk %s setelah periode %s.',
                    $kontrak->tgl_masuk->toDateString(),
                    $awalPeriode->toDateString(),
                ),
            ];
        }

        // Pemeriksaan duplikat di aplikasi. Ini bukan pengaman utama — unique
        // index di bawah yang mengamankan balapan — tetapi memberi alasan yang
        // terbaca, bukan pesan constraint.
        $sudahAda = Tagihan::query()
            ->where('kontrak_id', $kontrak->id)
            ->whereDate('periode', $awalPeriode)
            ->exists();

        if ($sudahAda) {
            return $dasar + [
                'status' => self::DILEWATI,
                'sebab' => self::SEBAB_DUPLIKAT,
                'alasan' => 'Tagihan periode ini sudah pernah diterbitkan.',
            ];
        }

        try {
            $tagihan = $this->penerbit->terbitkan($kontrak, $awalPeriode);
        } catch (QueryException $e) {
            // SQLSTATE 23505: dua permintaan berjalan bersamaan, keduanya lolos
            // pemeriksaan exists() di atas. Constraint yang menang; yang kalah
            // sampai di sini dan diperlakukan sama dengan duplikat biasa.
            if ($e->getCode() === '23505') {
                return $dasar + [
                    'status' => self::DILEWATI,
                    'sebab' => self::SEBAB_DUPLIKAT,
                    'alasan' => 'Tagihan periode ini baru saja diterbitkan oleh proses lain.',
                ];
            }

            throw $e;
        } catch (TagihanTidakDapatDiterbitkan $e) {
            return $dasar + [
                'status' => self::GAGAL,
                'alasan' => $e->getMessage(),
            ];
        }

        return $dasar + [
            'status' => self::TERBIT,
            'tagihan_id' => $tagihan->id,
            'pokok' => (int) $tagihan->pokok,
            'jatuh_tempo' => $tagihan->jatuh_tempo->toDateString(),
        ];
    }
}
