<?php

namespace App\Domain\Pembayaran;

use App\Domain\Audit\PencatatAudit;
use App\Models\Pembayaran;
use App\Models\VerifikasiPembayaran;
use Illuminate\Support\Facades\DB;

/**
 * Mencatat pemeriksaan atas sebuah pembayaran (T20).
 *
 * Kenapa pemeriksaan menjadi baris tersendiri, bukan kolom status yang di-UPDATE:
 * `pembayaran` append-only lewat trigger, dan begitu satu UPDATE diizinkan,
 * janji append-only kehilangan nilainya sebagai bukti. Precedent-nya `log_audit`.
 *
 * Tiga keputusan yang menentukan kelas ini benar atau tidak:
 *
 * 1. **Yang berlaku adalah baris TERAKHIR, bukan baris mana pun yang bernilai
 *    "terima".** Pembayaran yang pernah diterima lalu ditolak pada pemeriksaan
 *    ulang harus berakhir "ditolak". Kalau yang dicari hanya "apakah pernah ada
 *    baris terima", hasilnya akan tetap "terverifikasi" selamanya - dan uang
 *    yang sudah diketahui salah tidak bisa ditarik kembali statusnya.
 *
 * 2. **Menolak wajib beralasan.** Penolakan tanpa alasan membuat pengelola yang
 *    mencatat pembayaran tidak tahu apa yang harus diperbaiki, dan ia akan
 *    mencatat ulang baris yang sama - sehingga uangnya tercatat dua kali. CHECK
 *    di basis data menjaga ini juga, bukan hanya validasi.
 *
 * Verifikasi **tidak** mengubah `pembayaran.jumlah` maupun `tagihan.status`.
 * Ia hanya menyatakan "uang ini sudah diperiksa dan cocok" - angka uangnya
 * tetap seperti yang tercatat saat diterima. Pemeriksaan yang menemukan angka
 * salah tidak memperbaiki angkanya; ia menandainya, dan koreksi tetap menuntut
 * baris pembalik yang belum ada (dicatat sebagai batasan di SPEC).
 */
class PencatatVerifikasi
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function terima(Pembayaran $pembayaran, ?string $catatan = null): VerifikasiPembayaran
    {
        return $this->catat($pembayaran, VerifikasiPembayaran::KEPUTUSAN_TERIMA, $catatan);
    }

    public function tolak(Pembayaran $pembayaran, string $catatan): VerifikasiPembayaran
    {
        return $this->catat($pembayaran, VerifikasiPembayaran::KEPUTUSAN_TOLAK, $catatan);
    }

    private function catat(Pembayaran $pembayaran, string $keputusan, ?string $catatan): VerifikasiPembayaran
    {
        $catatan = $catatan !== null && trim($catatan) !== '' ? trim($catatan) : null;

        if ($keputusan === VerifikasiPembayaran::KEPUTUSAN_TOLAK && $catatan === null) {
            throw VerifikasiDitolak::tanpaAlasan();
        }

        return DB::transaction(function () use ($pembayaran, $keputusan, $catatan) {
            // Baris dikunci sebelum diperiksa. Dua pemeriksaan yang tiba
            // bersamaan pada pembayaran yang sama akan menulis dua baris, dan
            // "yang berlaku adalah yang terakhir" menjadi tidak bisa ditentukan
            // kalau keduanya menganggap dirinya yang terakhir.
            $pembayaran = Pembayaran::query()
                ->with('tagihan')
                ->whereKey($pembayaran->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $sebelum = $pembayaran->verifikasi()->latest('id')->first()?->keputusan;

            $baris = VerifikasiPembayaran::create([
                'pembayaran_id' => $pembayaran->id,
                'diverifikasi_oleh' => auth()->id(),
                'keputusan' => $keputusan,
                'catatan' => $catatan,
            ]);

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_PEMBAYARAN_VERIFIKASI,
                entitas: 'pembayaran',
                entitasId: $pembayaran->id,
                nilaiLama: ['verifikasi' => $sebelum],
                nilaiBaru: [
                    'verifikasi' => $keputusan,
                    'catatan' => $catatan,
                    'jumlah' => (int) $pembayaran->jumlah,
                    'metode' => $pembayaran->metode,
                ],
            );

            return $baris;
        });
    }
}
