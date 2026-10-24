<?php

namespace App\Domain\Billing;

use App\Domain\Audit\PencatatAudit;
use App\Models\Tagihan;
use App\Support\WaktuSistem;
use Illuminate\Support\Facades\DB;

/**
 * Memaafkan sebagian atau seluruh denda sebuah tagihan (REV-FIN-07).
 *
 * Sebelum ini denda adalah satu-satunya angka uang di sistem yang tidak bisa
 * diputuskan manusia. Ia dihitung dari kebijakan dan waktu, dan pemilik yang
 * ingin memaafkan penghuni lama — karena sakit, karena telat setor gaji, karena
 * memang salah kos — tidak punya jalan sama sekali.
 *
 * Empat keputusan yang menentukan kelas ini benar:
 *
 *   1. **Pemaafan mengurangi, bukan menghapus.** `denda_dimaafkan` disimpan
 *      sebagai angka, dan denda yang dihitung tetap dihitung penuh. Layar bisa
 *      menulis "denda Rp 450.000, dimaafkan Rp 450.000" — dua fakta yang
 *      berbeda artinya dari "tidak ada denda".
 *
 *   2. **Boleh memaafkan lebih dari denda saat ini.** Pemilik yang memaafkan
 *      Rp 500.000 saat denda baru Rp 300.000 sedang memaafkan denda yang akan
 *      datang juga — dan itu keputusan yang sah. Hasil akhirnya dijepit ke nol
 *      di `PenghitungDenda`, bukan ditolak di sini, karena menolaknya memaksa
 *      pemilik menghitung sendiri berapa denda pada hari pelunasan.
 *
 *   3. **Tidak bisa dipakai untuk menaikkan tagihan.** Nilainya dijaga tidak
 *      negatif oleh CHECK, sehingga pemaafan tidak bisa menyamar menjadi
 *      penalti.
 *
 *   4. **Alasan wajib, dan pelakunya dicatat.** Pemaafan mengurangi uang yang
 *      seharusnya diterima pemilik; pertanyaan "siapa yang memaafkan denda ini,
 *      dan kenapa" harus bisa dijawab dari barisnya sendiri tanpa membuka log
 *      audit.
 *
 * Yang **tidak** dilakukan kelas ini: memeriksa wewenang. Itu milik middleware
 * dan controller. Pemaafan denda memakai wewenang yang sama dengan menetapkan
 * denda itu sendiri — orang yang boleh menentukan dendanya adalah orang yang
 * boleh memaafkannya.
 */
class PemaafDenda
{
    public function __construct(
        private readonly PencatatAudit $audit,
        private readonly WaktuSistem $waktu,
        private readonly PenghitungDenda $penghitung,
    ) {}

    /**
     * Maafkan denda sebuah tagihan.
     *
     * @param  int  $jumlah  Rupiah yang dimaafkan. Nol membatalkan pemaafan.
     *
     * @throws PembayaranDitolak bila jumlahnya negatif atau alasannya kosong
     */
    public function maafkan(Tagihan $tagihan, int $jumlah, string $alasan): Tagihan
    {
        if ($jumlah < 0) {
            throw PembayaranDitolak::maafNegatif($jumlah);
        }

        $alasan = trim($alasan);

        // Alasan hanya dituntut bila ada yang dimaafkan. Membatalkan pemaafan
        // (jumlah 0) tidak mengubah uang siapa pun, jadi menuntut alasan untuk
        // itu hanya menambah satu medan yang diisi asal-asalan.
        if ($jumlah > 0 && $alasan === '') {
            throw PembayaranDitolak::maafTanpaAlasan();
        }

        return DB::transaction(function () use ($tagihan, $jumlah, $alasan) {
            // Dikunci dan dibaca ulang: instance dari pemanggil bisa sudah basi,
            // dan dua permintaan bersamaan yang sama-sama menulis pemaafan akan
            // saling menimpa tanpa ada yang tahu nilai mana yang kalah.
            /** @var Tagihan $tagihan */
            $tagihan = Tagihan::query()
                ->whereKey($tagihan->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($tagihan->status === Tagihan::STATUS_DIBATALKAN) {
                // Tagihan batal tidak menagih denda sama sekali, jadi pemaafan
                // di sana tidak punya arti. CHECK `tagihan_batal_tanpa_pemaafan`
                // menahan ini di basis data; pemeriksaan di sini hanya supaya
                // pesannya bisa dibaca.
                throw PembayaranDitolak::maafTagihanDibatalkan();
            }

            $sebelum = (int) $tagihan->denda_dimaafkan;

            // Denda saat ini dibaca lewat penghitung yang sama dengan endpoint
            // `/denda`. Pemeriksaan "tidak ada denda untuk dimaafkan" sengaja
            // TIDAK dilakukan: pemilik boleh memaafkan denda yang belum
            // berjalan, dan menolaknya memaksa ia menunggu jatuh tempo dulu.
            $dendaSekarang = $this->penghitung->hitung($tagihan)->denda;

            $tagihan->update([
                'denda_dimaafkan' => $jumlah,
                'alasan_maaf_denda' => $jumlah > 0 ? $alasan : null,
                'denda_dimaafkan_oleh' => $jumlah > 0 ? auth()->id() : null,
                'denda_dimaafkan_pada' => $jumlah > 0 ? $this->waktu->sekarang() : null,
            ]);

            // Jejak audit ditulis untuk kedua arah, termasuk pembatalan
            // pemaafan. Pemaafan yang ditarik kembali adalah peristiwa yang
            // sama pentingnya dengan pemaafan itu sendiri: uang yang tadinya
            // dilepas kembali ditagih, dan penghuni berhak tahu kapan.
            $this->audit->catat(
                aksi: $jumlah > 0
                    ? PencatatAudit::AKSI_DENDA_MAAFKAN
                    : PencatatAudit::AKSI_DENDA_MAAF_DIBATALKAN,
                entitas: 'tagihan',
                entitasId: $tagihan->id,
                nilaiLama: ['denda_dimaafkan' => $sebelum, 'denda' => $dendaSekarang],
                nilaiBaru: [
                    'denda_dimaafkan' => $jumlah,
                    'alasan' => $jumlah > 0 ? $alasan : null,
                ],
            );

            return $tagihan;
        });
    }
}
