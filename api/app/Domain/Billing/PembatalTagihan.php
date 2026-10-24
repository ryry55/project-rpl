<?php

namespace App\Domain\Billing;

use App\Domain\Audit\PencatatAudit;
use App\Models\Tagihan;
use App\Support\WaktuSistem;
use Illuminate\Support\Facades\DB;

/**
 * Membatalkan tagihan yang salah terbit (REV-FIN-02).
 *
 * Pembatalan ada karena R-02 menolak menerbitkan dua tagihan untuk satu kontrak
 * pada satu periode. Tanpa jalur batal, satu tagihan yang salah terbit — kamar
 * keliru, periode keliru, penghuni sudah keluar — mengunci periode itu
 * selamanya: penerbitan ulang selalu ditolak, dan tidak ada cara memperbaikinya
 * lewat aplikasi.
 *
 * Empat keputusan yang menentukan kelas ini benar:
 *
 *   1. **Baris tidak dihapus.** Tagihan yang batal tetap terbaca lengkap dengan
 *      pokok dan rinciannya; yang berubah hanya statusnya. Menghapus baris akan
 *      membuat nomor tagihan yang pernah disebut ke penghuni lenyap, dan
 *      pertanyaan "tagihan yang saya terima kemarin ke mana" tidak bisa
 *      dijawab.
 *
 *   2. **Tagihan yang sudah menerima uang tidak boleh dibatalkan.** Membatalkan
 *      tagihan yang sudah dibayar akan meninggalkan uang yang tidak menempel
 *      pada apa pun: `SUM(pembayaran.jumlah)` tetap menghitungnya sebagai
 *      pendapatan, sementara tagihannya berstatus batal. Jalannya adalah
 *      menarik pembayarannya lebih dulu lewat baris pembalik, lalu membatalkan.
 *      Urutan itu juga yang benar secara akuntansi: uangnya ditarik dulu, baru
 *      tagihannya batal.
 *
 *   3. **Alasan wajib.** Dijaga CHECK `tagihan_batal_ada_alasan` di basis data,
 *      bukan hanya validator, karena pembatalan lewat SQL langsung juga harus
 *      meninggalkan alasan.
 *
 *   4. **Index unik menjadi parsial.** Itu perubahan skema, bukan perubahan
 *      kelas ini — tetapi keduanya hanya berguna bersama: tanpa index parsial,
 *      membatalkan tagihan tidak membebaskan periodenya, dan seluruh kelas ini
 *      menjadi tombol yang tidak melakukan apa yang dijanjikan.
 */
class PembatalTagihan
{
    public function __construct(
        private readonly PencatatAudit $audit,
        private readonly WaktuSistem $waktu,
    ) {}

    /**
     * Batalkan satu tagihan.
     *
     * @throws PembayaranDitolak bila tagihan itu tidak boleh dibatalkan
     */
    public function batalkan(Tagihan $tagihan, string $alasan): Tagihan
    {
        $alasan = trim($alasan);

        if ($alasan === '') {
            throw PembayaranDitolak::batalTanpaAlasan();
        }

        return DB::transaction(function () use ($tagihan, $alasan) {
            // Dibaca ulang dengan kunci: instance dari pemanggil bisa sudah
            // basi, dan dua permintaan bersamaan yang sama-sama melihat
            // "belum batal" akan sama-sama menulis.
            /** @var Tagihan $tagihan */
            $tagihan = Tagihan::query()
                ->whereKey($tagihan->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($tagihan->status === Tagihan::STATUS_DIBATALKAN) {
                throw PembayaranDitolak::sudahDibatalkan();
            }

            $dibayar = $tagihan->totalDibayar();

            if ($dibayar !== 0) {
                throw PembayaranDitolak::batalSetelahDibayar($dibayar);
            }

            $sebelum = (string) $tagihan->status;

            $tagihan->update([
                'status' => Tagihan::STATUS_DIBATALKAN,
                'alasan_batal' => $alasan,
                'dibatalkan_oleh' => auth()->id(),
                // Waktu dari `WaktuSistem`, bukan `now()`: di mode demo
                // pembatalan harus tercatat pada tanggal simulasi, sama seperti
                // pembayaran dan pembalik.
                'dibatalkan_pada' => $this->waktu->sekarang(),
                // Total beku dilepas: tagihan batal tidak lagi menagih apa pun,
                // dan angka beku dari pelunasan sebelumnya tidak menggambarkan
                // keadaan sekarang.
                'total_final' => null,
            ]);

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_TAGIHAN_BATAL,
                entitas: 'tagihan',
                entitasId: $tagihan->id,
                nilaiLama: [
                    'status' => $sebelum,
                    'pokok' => (int) $tagihan->pokok,
                    'jatuh_tempo' => $tagihan->jatuh_tempo?->toDateString(),
                ],
                nilaiBaru: [
                    'status' => Tagihan::STATUS_DIBATALKAN,
                    'alasan' => $alasan,
                ],
            );

            return $tagihan;
        });
    }
}
