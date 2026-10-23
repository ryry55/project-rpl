<?php

namespace App\Domain\Billing;

use App\Domain\Audit\PencatatAudit;
use App\Domain\Kebijakan\PenyelesaiKebijakan;
use App\Domain\Kebijakan\PilihanKebijakan;
use App\Domain\Tarif\PencariTarif;
use App\Models\KebijakanKos;
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
 *   2. `tarif_terpakai` — salinan tarif, komponen, dan kebijakan yang dipakai;
 *      `kebijakan_terpakai` menyimpan bentuk denda yang berlaku saat terbit.
 *
 * Tanpa keduanya, menaikkan tarif bulan depan akan mengubah tagihan bulan lalu
 * lewat join, walau `tarif_sewa` sudah append-only.
 */
class PenerbitTagihan
{
    public function __construct(
        private readonly Kalkulator $kalkulator,
        private readonly PencariTarif $pencari,
        private readonly PenyelesaiKebijakan $kebijakan,
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

        // Gerbang kebijakan (T16) sengaja diperiksa SEBELUM tarif. Urutannya
        // menentukan pesan mana yang dibaca pemilik lebih dulu, dan pesan yang
        // paling bisa ditindaklanjuti adalah "kebijakan belum diisi": itu
        // satu-satunya langkah yang memperbaiki semua kamar sekaligus.
        // Sebaliknya, "kamar A1 belum punya tarif" untuk kos 30 kamar baru akan
        // menuntun pemilik ke 30 pesan yang berbeda.
        //
        // Diperiksa per kamar, bukan sekali untuk seluruh kos: satu kamar boleh
        // punya penimpa (mis. kamar harian), dan kamar itu tetap wajib lengkap
        // walau default kos sudah diisi.
        $berlaku = $this->kebijakan->untukPenerbitan($awalPeriode, $kontrak->kamar);

        $tarif = $this->pencari->sewaUntuk($kontrak->kamar, $awalPeriode);

        if ($tarif === null) {
            throw TagihanTidakDapatDiterbitkan::karenaTarifKosong($kontrak, $awalPeriode);
        }

        $satuanSewa = $berlaku[KebijakanKos::TIPE_SATUAN_SEWA];
        $caraJatuhTempo = $berlaku[KebijakanKos::TIPE_JATUH_TEMPO];

        // T17: bentuk denda ikut dibekukan ke tagihan. Inilah satu-satunya
        // angka di sistem ini yang dihitung ulang tiap kali dibaca, jadi tanpa
        // snapshot pemilik yang menaikkan denda bulan depan akan mengubah
        // denda tagihan bulan lalu.
        $kebijakanDenda = $berlaku[KebijakanKos::TIPE_DENDA];
        $dendaTerpakai = $this->bentukDenda($kebijakanDenda);

        // R-01 hanya berlaku pada bulan penghuni masuk. Bulan berikutnya selalu
        // tarif penuh — termasuk bulan ketika penghuni keluar di tengah bulan:
        // tidak ada prorata keluar, karena sisa bulan tetap disewa.
        //
        // T16: yang berubah hanya cara harga diterjemahkan, bukan kapan prorata
        // berlaku. `hariDitagih` tetap sama untuk ketiga satuan, sehingga kos
        // mingguan pun tidak menagih sebulan penuh untuk penghuni yang masuk
        // tanggal 28.
        $baris = [];
        $bulanMasuk = $awalPeriode->isSameMonth($tanggalMasuk);
        $hariDalamBulan = $awalPeriode->daysInMonth;
        $hariDitagih = $bulanMasuk
            ? $this->kalkulator->hariTinggal($tanggalMasuk)
            : $hariDalamBulan;

        $sewa = $this->kalkulator->hitungSewa(
            $satuanSewa->nilai,
            (int) $tarif->nilai,
            $hariDitagih,
            $hariDalamBulan,
        );

        // Label rincian mengikuti satuan yang benar-benar dipakai. Tanpa ini,
        // tagihan kos harian tetap berbunyi "Sewa November 2026" — dan pemilik
        // yang menagih Rp 50.000 untuk kamar Rp 1.500.000/bulan tidak punya
        // penjelasan apa pun di layar.
        $namaBaris = $this->namaBarisSewa($satuanSewa->nilai, $awalPeriode, $hariDitagih, $hariDalamBulan);

        $baris[] = [
            'nama' => $namaBaris,
            'nilai' => $sewa,
        ];

        // Komponen biaya tetap selalu tarif penuh, tidak diprorata: kebersihan
        // dan parkir tidak berkurang hanya karena penghuni masuk di tengah bulan.
        //
        // T11: komponen dicari untuk kamar kontrak ini, sehingga biaya yang
        // hanya berlaku sebagian kamar tidak bocor ke penghuni lain. Snapshot
        // mencatat `kamar_id` supaya bisa dibedakan mana yang global.
        $komponenTerpakai = [];
        foreach ($this->pencari->komponenUntuk($awalPeriode, $kontrak->kamar) as $komponen) {
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
                'kamar_id' => $komponen->kamar_id,
            ];
        }

        $pokok = array_sum(array_column($baris, 'nilai'));

        return DB::transaction(function () use (
            $kontrak,
            $awalPeriode,
            $tanggalMasuk,
            $tarif,
            $baris,
            $pokok,
            $komponenTerpakai,
            $bulanMasuk,
            $satuanSewa,
            $caraJatuhTempo,
            $hariDitagih,
            $hariDalamBulan,
            $kebijakanDenda,
            $dendaTerpakai,
        ) {
            $jatuhTempo = $this->kalkulator->tanggalJatuhTempoMenurut(
                $caraJatuhTempo->nilai,
                $awalPeriode,
                $tanggalMasuk,
                $caraJatuhTempo->tanggal,
            );

            $tagihan = Tagihan::create([
                'kontrak_id' => $kontrak->id,
                'periode' => $awalPeriode->toDateString(),
                'pokok' => $pokok,
                'jatuh_tempo' => $jatuhTempo->toDateString(),
                'status' => Tagihan::STATUS_BELUM_BAYAR,
                'tarif_terpakai' => [
                    'tarif_sewa' => [
                        'id' => $tarif->id,
                        'nilai' => (int) $tarif->nilai,
                        'berlaku_dari' => $tarif->berlaku_dari->toDateString(),
                    ],
                    'komponen_biaya' => $komponenTerpakai,
                    // T16: kebijakan yang dipakai ikut dibekukan. Tanpa ini,
                    // pemilik yang mengubah satuan sewa menjadi harian bulan
                    // depan tidak bisa menjelaskan mengapa tagihan bulan lalu
                    // sebesar itu — `kebijakan_kos` sudah berisi baris baru,
                    // dan baris lama tidak menunjuk tagihan mana pun.
                    'kebijakan' => [
                        'satuan_sewa' => [
                            'id' => $satuanSewa->id,
                            'nilai' => $satuanSewa->nilai,
                            'berlaku_dari' => $satuanSewa->berlaku_dari->toDateString(),
                            'kamar_id' => $satuanSewa->kamar_id,
                        ],
                        'jatuh_tempo' => [
                            'id' => $caraJatuhTempo->id,
                            'nilai' => $caraJatuhTempo->nilai,
                            'tanggal' => $caraJatuhTempo->tanggal,
                            'berlaku_dari' => $caraJatuhTempo->berlaku_dari->toDateString(),
                            'kamar_id' => $caraJatuhTempo->kamar_id,
                        ],
                        'hari_ditagih' => $hariDitagih,
                        'hari_dalam_bulan' => $hariDalamBulan,
                    ],
                    'prorata' => $bulanMasuk,
                ],
                // Dokumen beku tersendiri untuk denda (T17). Bentuknya boleh
                // berbeda antar waktu tanpa migrasi, karena ini arsip — bukan
                // sumber kebenaran. `id` dan `berlaku_dari` ikut disimpan
                // supaya baris kebijakan asalnya bisa ditunjuk saat ditanya.
                'kebijakan_terpakai' => [
                    'denda' => $dendaTerpakai->toArray() + [
                        'id' => $kebijakanDenda->id,
                        'nilai' => $kebijakanDenda->nilai,
                        'berlaku_dari' => $kebijakanDenda->berlaku_dari->toDateString(),
                        'kamar_id' => $kebijakanDenda->kamar_id,
                    ],
                ],
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
                        'kebijakan_denda_id' => $kebijakanDenda->id,
                    ],
                ],
            );

            return $tagihan;
        });
    }

    /**
     * Terjemahkan baris kebijakan `denda` menjadi bentuk yang bisa dihitung.
     *
     * Dipisah supaya `PenerbitTagihan` tidak perlu tahu bahwa denda persen
     * membawa dua angka sementara denda nominal membawa satu. Pengetahuan itu
     * tinggal di {@see DendaTerpilih}, satu tempat, dan `PenghitungDenda`
     * memakai penerjemah yang sama saat membaca arsipnya kembali.
     */
    private function bentukDenda(KebijakanKos $kebijakan): DendaTerpilih
    {
        return match ($kebijakan->nilai) {
            PilihanKebijakan::DENDA_TANPA_DENDA => DendaTerpilih::tanpaDenda(),

            PilihanKebijakan::DENDA_NOMINAL_TETAP => DendaTerpilih::nominalTetap(
                (int) $kebijakan->nominal,
            ),

            PilihanKebijakan::DENDA_PERSEN_BULANAN => DendaTerpilih::persenBulanan(
                (string) $kebijakan->persen,
                (string) $kebijakan->plafon_persen,
                (int) ($kebijakan->masa_tenggang ?? 0),
            ),

            PilihanKebijakan::DENDA_PERSEN_HARIAN => DendaTerpilih::persenHarian(
                (string) $kebijakan->persen,
                (string) $kebijakan->plafon_persen,
                (int) ($kebijakan->masa_tenggang ?? 0),
            ),

            default => throw new \UnexpectedValueException(
                "Nilai kebijakan denda \"{$kebijakan->nilai}\" tidak dikenal."
            ),
        };
    }

    /**
     * Nama baris rincian untuk sewa, mengikuti satuan yang dipakai.
     *
     * Dipisah dari alur utama supaya bisa dibaca sebagai aturan penamaan, bukan
     * sebagai tiga kondisi bertingkat di tengah perhitungan uang. Yang dijamin
     * di sini hanya satu hal: baris rincian menjelaskan angka di sebelahnya.
     *
     * Kata "prorata" hanya muncul bila memang diprorata. Masuk tanggal 1
     * menghasilkan sewa penuh, dan menuliskannya sebagai "prorata 31/31" hanya
     * membingungkan pembaca tagihan.
     */
    private function namaBarisSewa(string $satuan, CarbonInterface $awalPeriode, int $hariDitagih, int $hariDalamBulan): string
    {
        $bulan = $awalPeriode->translatedFormat('F Y');
        $penuh = $hariDitagih === $hariDalamBulan;

        // Satuan selain bulanan selalu menyebut satuannya, karena jumlahnya
        // tidak akan cocok dengan tarif bulanan yang tertulis di menu Kamar.
        // Tanpa keterangan itu, pemilik sendiri tidak bisa menjelaskan asal
        // angka Rp 350.000 pada kamar bertarif Rp 1.500.000/bulan.
        $satuanLabel = match ($satuan) {
            PilihanKebijakan::SATUAN_MINGGUAN => 'mingguan',
            PilihanKebijakan::SATUAN_HARIAN => 'harian',
            default => null,
        };

        if ($satuanLabel !== null) {
            return $penuh
                ? sprintf('Sewa %s (%s %d hari)', $bulan, $satuanLabel, $hariDitagih)
                : sprintf('Sewa %s (%s, %d/%d hari)', $bulan, $satuanLabel, $hariDitagih, $hariDalamBulan);
        }

        return $penuh
            ? sprintf('Sewa %s', $bulan)
            : sprintf('Sewa %s (prorata %d/%d hari)', $bulan, $hariDitagih, $hariDalamBulan);
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
