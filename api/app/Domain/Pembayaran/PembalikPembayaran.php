<?php

namespace App\Domain\Pembayaran;

use App\Domain\Audit\PencatatAudit;
use App\Domain\Billing\PembayaranDitolak;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Support\WaktuSistem;
use Illuminate\Support\Facades\DB;

/**
 * Menarik kembali pembayaran yang sudah tercatat (REV-FIN-01).
 *
 * Kenapa kelas ini ada, padahal `pembayaran` append-only. Append-only menjaga
 * **jejak**, bukan **kebenaran**. Baris yang salah tetap harus terbaca, tetapi
 * uang yang tidak pernah diterima tidak boleh terus dihitung sebagai
 * pendapatan. Dua tuntutan itu hanya bisa dipenuhi bersama oleh satu bentuk:
 * baris pembalik - baris baru bernilai negatif yang menunjuk baris asalnya.
 *
 * Empat keputusan yang menentukan kelas ini benar atau tidak:
 *
 *   1. **Jumlahnya selalu penuh, tidak boleh sebagian.** Membalik sebagian
 *      membuat "berapa yang sudah ditarik" menjadi pertanyaan yang jawabannya
 *      tersebar di banyak baris. Yang salah di sini hampir selalu salah
 *      seluruhnya - pembayaran yang tidak pernah terjadi, atau tercatat dua
 *      kali. Kalau memang hanya sebagian yang keliru, jalannya adalah membalik
 *      seluruh baris lalu mencatat ulang jumlah yang benar.
 *
 *   2. **Tagihan dikunci dan statusnya diturunkan ulang dari jumlah bersih.**
 *      Tanpa ini, tagihan yang tadinya `lunas` akan tetap `lunas` dengan
 *      pembayaran bersih nol - dan `total_final` yang dibekukan saat lunas
 *      akan terus dipakai sebagai total yang dibebankan.
 *
 *   3. **`total_final` dilepas kembali menjadi null.** Ini yang paling mudah
 *      terlewat. `total_final` dibekukan tepat saat tagihan menjadi lunas,
 *      karena saat itu denda berhenti berjalan. Kalau pembayarannya ditarik,
 *      tagihan kembali berjalan, dan dendanya harus ikut berjalan lagi -
 *      artinya angka beku itu tidak lagi sah. Membiarkannya berarti tagihan
 *      yang kembali menunggak akan memakai total lama selamanya.
 *
 *   4. **Pembalik tidak bisa dibalik, dan satu pembayaran hanya bisa dibalik
 *      sekali.** Keduanya dijaga basis data (unique + trigger), bukan hanya di
 *      sini, karena keduanya kesalahan yang merusak angka tanpa menimbulkan
 *      galat yang kelihatan.
 *
 * Yang **tidak** dilakukan kelas ini: memeriksa apakah pembayaran itu sudah
 * ditolak pemeriksaan, atau siapa yang berhak membaliknya. Keduanya keputusan
 * otorisasi, dan otorisasi milik middleware serta controller - bukan milik
 * lapisan yang sama dengan penulisan angka.
 */
class PembalikPembayaran
{
    public function __construct(
        private readonly PencatatAudit $audit,
        private readonly WaktuSistem $waktu,
    ) {}

    /**
     * Tulis baris pembalik untuk satu pembayaran.
     *
     * @throws PembayaranDitolak bila baris itu tidak boleh dibalik
     */
    public function balik(Pembayaran $pembayaran, string $alasan, ?int $dicatatOleh = null): Pembayaran
    {
        $alasan = trim($alasan);

        if ($alasan === '') {
            throw PembayaranDitolak::pembalikTanpaAlasan();
        }

        return DB::transaction(function () use ($pembayaran, $alasan, $dicatatOleh) {
            // Baris asal dibaca ulang dengan kunci. Instance dari pemanggil bisa
            // sudah basi, dan tanpa kunci dua permintaan bersamaan sama-sama
            // membaca "belum dibalik" lalu sama-sama menulis - unique index
            // menangkapnya, tetapi pesan constraint bukan pesan yang bisa
            // dibaca pemilik kos.
            $asal = Pembayaran::query()
                ->whereKey($pembayaran->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($asal->adalahPembalik()) {
                throw PembayaranDitolak::pembalikTidakBisaDibalik();
            }

            $sudah = Pembayaran::query()->where('pembalik_id', $asal->id)->exists();

            if ($sudah) {
                throw PembayaranDitolak::sudahDibalik((int) $asal->jumlah);
            }

            /** @var Tagihan $tagihan */
            $tagihan = Tagihan::query()
                ->whereKey($asal->tagihan_id)
                ->lockForUpdate()
                ->firstOrFail();

            $sebelum = (string) $tagihan->status;
            $dibayarSebelum = $tagihan->totalDibayar();

            $pembalik = Pembayaran::create([
                'tagihan_id' => $asal->tagihan_id,
                'pembalik_id' => $asal->id,
                // Negatif: lihat catatan migrasi. Tanda inilah yang membuat
                // SUM(jumlah) tetap berarti "uang bersih yang diterima".
                'jumlah' => -1 * (int) $asal->jumlah,
                'metode' => $asal->metode,
                'sumber' => MetodePembayaran::SUMBER_DICATAT_PEMILIK,
                'referensi' => $asal->referensi,
                'dicatat_oleh' => $dicatatOleh ?? auth()->id(),
                // Pemegang uang disalin dari baris asal, bukan dikosongkan:
                // kalau uangnya dulu dipegang Sari, pertanyaan "setoran siapa
                // yang berkurang" jawabannya juga Sari. CHECK `tunai_ada_pemegang`
                // menuntut kolom ini terisi untuk baris tunai.
                'diterima_oleh' => $asal->diterima_oleh,
                // Tanggal pembalikan = hari ini, BUKAN tanggal pembayaran asal.
                // Pembalikan adalah peristiwa hari ini; menaruhnya di tanggal
                // asal akan mengubah rekap bulan yang sudah ditutup. "Hari ini"
                // dibaca dari WaktuSistem supaya simulasi tanggal di mode demo
                // juga menggeser tanggal pembalikan - tanpa itu, demo koreksi
                // pembayaran akan menulis tanggal nyata dan jatuh di luar
                // rentang rekap yang sedang ditampilkan.
                'tanggal_pembayaran' => $this->waktu->hariIni()->toDateString(),
                'catatan' => $alasan,
            ]);

            // Status diturunkan dari jumlah bersih yang baru, lewat aturan yang
            // sama dengan pencatatan pembayaran: baca SUM, jangan kurangi status
            // sebelumnya satu per satu.
            $dibayarSesudah = $tagihan->totalDibayar();

            $sesudah = match (true) {
                // Tagihan yang dibatalkan tetap dibatalkan. Uangnya tetap harus
                // keluar dari rekap, tetapi statusnya bukan urusan pembalikan.
                $sebelum === Tagihan::STATUS_DIBATALKAN => Tagihan::STATUS_DIBATALKAN,
                $dibayarSesudah <= 0 => Tagihan::STATUS_BELUM_BAYAR,
                // Pembalik hanya mengurangi, jadi tidak mungkin berakhir lunas.
                // Cabangnya tetap ditulis supaya aturannya utuh bila suatu hari
                // ada koreksi bernilai positif.
                default => Tagihan::STATUS_SEBAGIAN,
            };

            $tagihan->update([
                'status' => $sesudah,
                // Total beku dilepas bila tagihan tidak lagi lunas: denda
                // berjalan lagi dari jatuh tempo, dan angka beku lama tidak
                // lagi menggambarkan apa pun.
                'total_final' => $sesudah === Tagihan::STATUS_LUNAS
                    ? $tagihan->total_final
                    : null,
            ]);

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_PEMBAYARAN_BALIK,
                entitas: 'pembayaran',
                entitasId: $pembalik->id,
                // Baris asal disebut lengkap - id dan nominalnya - bukan hanya
                // "dibayar sebelum/sesudah". Tanpa nominal aslinya, pembaca
                // jejak enam bulan lagi harus mencari sendiri baris yang
                // ditarik, dan angka "dibayar" bisa berasal dari beberapa
                // pembayaran sekaligus sehingga nominal yang dibatalkan tidak
                // bisa disimpulkan darinya.
                nilaiLama: [
                    'pembayaran_id' => $asal->id,
                    'jumlah' => (int) $asal->jumlah,
                    'status_tagihan' => $sebelum,
                    'dibayar' => $dibayarSebelum,
                ],
                nilaiBaru: [
                    'status_tagihan' => $sesudah,
                    'dibayar' => $dibayarSesudah,
                    'pembalik_id' => $asal->id,
                    'jumlah' => (int) $pembalik->jumlah,
                    'alasan' => $alasan,
                ],
            );

            return $pembalik;
        });
    }
}
