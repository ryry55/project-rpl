<?php

namespace App\Domain\Billing;

use App\Domain\Audit\PencatatAudit;
use App\Domain\Tarif\PencariTarif;
use App\Models\Kontrak;
use App\Models\Tagihan;
use App\Models\TagihanRincian;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Penerbitan tagihan.
 *
 * Ini lapisan yang menyentuh basis data; keputusan hitungnya tetap diserahkan ke
 * {@see Kalkulator} yang murni. Pemisahannya disengaja: kalau logika prorata
 * ditulis di sini, mengujinya butuh kontrak, kamar, dan tarif — dan tes batas
 * 17/31 jadi tes integrasi yang lambat.
 *
 * Dua hal yang dibekukan saat terbit, keduanya untuk R-06 lapis 2:
 *
 *   1. `pokok` — angka jadi, tidak dihitung ulang saat dibaca.
 *   2. `tarif_terpakai` — salinan tarif, komponen, dan parameter denda.
 *
 * Tanpa keduanya, menaikkan tarif bulan depan akan mengubah tagihan bulan lalu
 * lewat join, walau `tarif_sewa` sudah append-only.
 */
class PenerbitTagihan
{
    public function __construct(
        private readonly Kalkulator $kalkulator,
        private readonly PencariTarif $pencari,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * Terbitkan satu tagihan untuk satu kontrak pada satu periode.
     *
     * @throws TagihanTidakDapatDiterbitkan
     */
    public function terbitkan(Kontrak $kontrak, CarbonInterface $periode): Tagihan
    {
        $awalPeriode = $periode->copy()->startOfMonth();
        $tanggalMasuk = $kontrak->tgl_masuk->copy();

        $this->pastikanPeriodeMasukAkal($kontrak, $awalPeriode, $tanggalMasuk);

        $tarif = $this->pencari->sewaUntuk($kontrak->kamar, $awalPeriode);

        if ($tarif === null) {
            throw TagihanTidakDapatDiterbitkan::karenaTarifKosong($kontrak, $awalPeriode);
        }

        $parameter = $this->pencari->parameterDendaUntuk($awalPeriode);

        if ($parameter === null) {
            throw TagihanTidakDapatDiterbitkan::karenaParameterDendaKosong($awalPeriode);
        }

        // R-01 hanya berlaku pada bulan penghuni masuk. Bulan berikutnya selalu
        // tarif penuh — termasuk bulan ketika penghuni keluar di tengah bulan:
        // tidak ada prorata keluar, karena sisa bulan tetap disewa.
        $baris = [];
        $bulanMasuk = $awalPeriode->isSameMonth($tanggalMasuk);

        if ($bulanMasuk) {
            $hariTinggal = $this->kalkulator->hariTinggal($tanggalMasuk);
            $hariDalamBulan = $awalPeriode->daysInMonth;
            $sewa = $this->kalkulator->hitungSewaBulanPertama(
                (int) $tarif->nilai,
                $hariTinggal,
                $hariDalamBulan,
            );

            // Sebut "prorata" hanya bila memang diprorata. Masuk tanggal 1
            // menghasilkan sewa penuh, dan menuliskannya sebagai "prorata 31/31"
            // hanya membingungkan pembaca tagihan.
            $namaBaris = $hariTinggal === $hariDalamBulan
                ? sprintf('Sewa %s', $awalPeriode->translatedFormat('F Y'))
                : sprintf(
                    'Sewa %s (prorata %d/%d hari)',
                    $awalPeriode->translatedFormat('F Y'),
                    $hariTinggal,
                    $hariDalamBulan,
                );

            $baris[] = [
                'nama' => $namaBaris,
                'nilai' => $sewa,
            ];
        } else {
            $baris[] = [
                'nama' => sprintf('Sewa %s', $awalPeriode->translatedFormat('F Y')),
                'nilai' => (int) $tarif->nilai,
            ];
        }

        // Komponen biaya tetap selalu tarif penuh, tidak diprorata: kebersihan
        // dan parkir tidak berkurang hanya karena penghuni masuk di tengah bulan.
        $komponenTerpakai = [];
        foreach ($this->pencari->komponenUntuk($awalPeriode) as $komponen) {
            $nilai = (int) $komponen->nilai;

            // Baris bernilai 0 adalah cara komponen dihentikan, bukan komponen
            // gratis. Tidak perlu muncul sebagai baris rincian.
            if ($nilai === 0) {
                continue;
            }

            $baris[] = [
                'nama' => $komponen->nama,
                'nilai' => $nilai,
            ];

            $komponenTerpakai[] = [
                'nama' => $komponen->nama,
                'nilai' => $nilai,
                'berlaku_dari' => $komponen->berlaku_dari->toDateString(),
            ];
        }

        $pokok = array_sum(array_column($baris, 'nilai'));

        return DB::transaction(function () use (
            $kontrak,
            $awalPeriode,
            $tanggalMasuk,
            $tarif,
            $parameter,
            $baris,
            $pokok,
            $komponenTerpakai,
            $bulanMasuk,
        ) {
            $tagihan = Tagihan::create([
                'kontrak_id' => $kontrak->id,
                'periode' => $awalPeriode->toDateString(),
                'pokok' => $pokok,
                'jatuh_tempo' => $this->kalkulator
                    ->tanggalJatuhTempo($tanggalMasuk, $awalPeriode)
                    ->toDateString(),
                'status' => Tagihan::STATUS_BELUM_BAYAR,
                'tarif_terpakai' => [
                    'tarif_sewa' => [
                        'id' => $tarif->id,
                        'nilai' => (int) $tarif->nilai,
                        'berlaku_dari' => $tarif->berlaku_dari->toDateString(),
                    ],
                    'komponen_biaya' => $komponenTerpakai,
                    'parameter_denda' => [
                        'id' => $parameter->id,
                        'tarif_persen' => (string) $parameter->tarif_persen,
                        'plafon_persen' => (string) $parameter->plafon_persen,
                        'masa_tenggang' => $parameter->masa_tenggang,
                        'berlaku_dari' => $parameter->berlaku_dari->toDateString(),
                    ],
                    'prorata' => $bulanMasuk,
                ],
                'tarif_denda_persen' => $parameter->tarif_persen,
                'plafon_persen' => $parameter->plafon_persen,
                'masa_tenggang' => $parameter->masa_tenggang,
                'terbit_pada' => now(),
            ]);

            foreach ($baris as $urutan => $satuBaris) {
                TagihanRincian::create([
                    'tagihan_id' => $tagihan->id,
                    'nama' => $satuBaris['nama'],
                    'nilai' => $satuBaris['nilai'],
                    'urutan' => $urutan,
                ]);
            }

            // Jejak audit ditulis di dalam transaksi yang sama dengan tagihannya.
            // Kalau ditulis setelah commit, ada jendela waktu di mana tagihan
            // sudah ada tetapi jejaknya belum — dan kegagalan tepat di jendela
            // itu menghasilkan tagihan tanpa jejak, persis yang ingin dicegah.
            $this->audit->catat(
                aksi: PencatatAudit::AKSI_TAGIHAN_TERBIT,
                entitas: 'tagihan',
                entitasId: $tagihan->id,
                nilaiLama: null,
                nilaiBaru: [
                    'kontrak_id' => $kontrak->id,
                    'periode' => $awalPeriode->toDateString(),
                    'pokok' => $pokok,
                    'jatuh_tempo' => $tagihan->jatuh_tempo->toDateString(),
                    'status' => Tagihan::STATUS_BELUM_BAYAR,
                    'tarif_terpakai' => [
                        'tarif_sewa_id' => $tarif->id,
                        'nilai' => (int) $tarif->nilai,
                        'parameter_denda_id' => $parameter->id,
                    ],
                ],
            );

            return $tagihan;
        });
    }

    /**
     * Tolak periode yang tidak masuk akal untuk kontrak ini.
     *
     * Tanpa pemeriksaan ini, periode sebelum penghuni masuk akan menghasilkan
     * tagihan penuh untuk bulan yang tidak pernah dihuni, dan periode setelah
     * kontrak selesai akan menagih orang yang sudah keluar.
     */
    private function pastikanPeriodeMasukAkal(
        Kontrak $kontrak,
        CarbonInterface $awalPeriode,
        CarbonInterface $tanggalMasuk,
    ): void {
        if ($awalPeriode->lessThan($tanggalMasuk->copy()->startOfMonth())) {
            throw TagihanTidakDapatDiterbitkan::karenaPeriodeSebelumMasuk($awalPeriode, $tanggalMasuk);
        }

        if ($kontrak->tgl_keluar !== null
            && $awalPeriode->greaterThan($kontrak->tgl_keluar->copy()->startOfMonth())) {
            throw TagihanTidakDapatDiterbitkan::karenaKontrakSudahSelesai($awalPeriode, $kontrak->tgl_keluar);
        }
    }
}
