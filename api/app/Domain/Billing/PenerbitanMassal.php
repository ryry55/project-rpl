<?php

namespace App\Domain\Billing;

use App\Domain\Kebijakan\PenyelesaiKebijakan;
use App\Models\KebijakanKos;
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

    /**
     * Sebab dilewatinya kontrak karena siklusnya belum tiba pada jalur
     * terjadwal (K-14).
     *
     * Dipisahkan dari `belum_mulai`: kontraknya sudah aktif dan sudah menempati
     * kamar, hanya saja siklus tanggal 15 belum sampai saat penerbitan harian
     * berjalan tanggal 2. Pemilik tidak perlu memperbaiki apa pun.
     */
    public const SEBAB_BELUM_WAKTUNYA = 'belum_waktunya';

    /**
     * Sebab gagalnya sebuah kontrak karena kebijakan kos belum lengkap (T16).
     *
     * Dipisahkan dari gagal biasa karena tindakannya berbeda: tarif kosong
     * diperbaiki per kamar, sedangkan kebijakan kosong diperbaiki sekali untuk
     * seluruh kos. Pemilik yang melihat 30 baris "gagal" tanpa sebab akan
     * mengira ada 30 masalah.
     */
    public const SEBAB_KEBIJAKAN = 'kebijakan';

    public function __construct(
        private readonly PenerbitTagihan $penerbit,
        private readonly PenyelesaiKebijakan $kebijakan,
    ) {}

    /**
     * @param  CarbonInterface|null  $hariIni  Bila diisi, siklus yang belum tiba
     *                                         pada tanggal itu dilewati. Jalur
     *                                         terjadwal mengisinya; penerbitan
     *                                         manual tidak, supaya pemilik tetap
     *                                         boleh menerbitkan lebih awal.
     * @return array{
     *     periode: string,
     *     terbit: int,
     *     dilewati: int,
     *     dilewati_duplikat: int,
     *     gagal: int,
     *     rincian: list<array<string, mixed>>
     * }
     */
    public function jalankan(CarbonInterface $periode, ?CarbonInterface $hariIni = null): array
    {
        // Pola global, diselesaikan sekali untuk seluruh batch (K-14d).
        $pola = PolaTagihan::dari(
            $this->kebijakan->kebijakan(KebijakanKos::TIPE_POLA_TAGIHAN, $periode)
        );

        // Tanggal terbit juga satu setelan untuk seluruh kos (K-14 Level 1).
        // `null` berarti terbit pada hari siklusnya mulai.
        $tanggalTerbit = $this->kebijakan
            ->kebijakan(KebijakanKos::TIPE_TANGGAL_TERBIT, $periode)
            ?->tanggal;

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
            $hasil = $this->terbitkanSatu($kontrak, $periode, $pola, $tanggalTerbit, $hariIni);
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
            'periode' => $periode->copy()->startOfMonth()->toDateString(),
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
    private function terbitkanSatu(
        Kontrak $kontrak,
        CarbonInterface $periode,
        string $pola,
        ?int $tanggalTerbit,
        ?CarbonInterface $hariIni,
    ): array {
        $dasar = [
            'kontrak_id' => $kontrak->id,
            'penghuni_id' => $kontrak->penghuni_id,
            'penghuni' => $kontrak->penghuni?->nama,
            'kamar' => $kontrak->kamar?->nomor,
        ];

        $awalPeriode = PolaTagihan::awalSiklus($pola, $kontrak->tgl_masuk, $periode);

        // Kontrak yang siklus pertamanya belum tiba tidak punya bulan yang bisa
        // ditagih. Dilewati, bukan gagal: ini keadaan yang normal ketika
        // pemilik menerbitkan untuk bulan berjalan sementara ada penghuni yang
        // baru akan masuk bulan depan.
        if ($awalPeriode->lt(PolaTagihan::siklusPertama($pola, $kontrak->tgl_masuk))) {
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

        // Siklus belum tiba, atau tanggal terbitnya belum tiba. Tanpa penjagaan
        // ini, penerbitan harian akan menagih siklus tanggal 15 pada tanggal 2 —
        // dan tagihan itu lahir sebelum penghuni berutang apa pun.
        //
        // `tanggalTerbit` hanya bisa menunda (lihat PolaTagihan::tanggalTerbit):
        // siklus yang mulai setelah tanggal terbit tetap terbit pada hari
        // siklusnya.
        $terbitPada = PolaTagihan::tanggalTerbit($awalPeriode, $tanggalTerbit);

        if ($hariIni !== null && $terbitPada->gt($hariIni->copy()->startOfDay())) {
            return $dasar + [
                'status' => self::DILEWATI,
                'sebab' => self::SEBAB_BELUM_WAKTUNYA,
                'alasan' => sprintf(
                    'Belum waktunya: tagihan siklus %s dijadwalkan terbit %s.',
                    $awalPeriode->toDateString(),
                    $terbitPada->toDateString(),
                ),
            ];
        }

        // Pemeriksaan duplikat di aplikasi. Ini bukan pengaman utama — unique
        // index di bawah yang mengamankan balapan — tetapi memberi alasan yang
        // terbaca, bukan pesan constraint.
        //
        // Diperiksa per BULAN, bukan per tanggal periode. Satu kontrak hanya
        // boleh punya satu tagihan per bulan; tanpa syarat bulan, perpindahan
        // pola dari tanggal 1 ke anniversary akan menerbitkan tagihan kedua di
        // bulan yang sama — tepat "menagih mundur" yang dilarang K-14.
        //
        // `status <> dibatalkan` wajib ada di sini (REV-FIN-02): index uniknya
        // kini parsial, jadi tagihan yang dibatalkan memang tidak lagi memegang
        // periode itu. Tanpa syarat ini, pemeriksaan di aplikasi akan menolak
        // penerbitan ulang yang justru sudah diizinkan basis data — dan pemilik
        // melihat "sudah pernah diterbitkan" untuk periode yang sudah ia
        // batalkan sendiri.
        $sudahAda = Tagihan::query()
            ->where('kontrak_id', $kontrak->id)
            ->where('status', '<>', Tagihan::STATUS_DIBATALKAN)
            ->whereBetween('periode', [
                $awalPeriode->copy()->startOfMonth()->toDateString(),
                $awalPeriode->copy()->endOfMonth()->toDateString(),
            ])
            ->exists();

        if ($sudahAda) {
            return $dasar + [
                'status' => self::DILEWATI,
                'sebab' => self::SEBAB_DUPLIKAT,
                'alasan' => 'Tagihan periode ini sudah pernah diterbitkan.',
            ];
        }

        try {
            $tagihan = $this->penerbit->terbitkan($kontrak, $periode, $pola);
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
                'sebab' => self::SEBAB_KEBIJAKAN,
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
