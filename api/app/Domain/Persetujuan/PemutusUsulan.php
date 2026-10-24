<?php

namespace App\Domain\Persetujuan;

use App\Domain\Audit\PencatatAudit;
use App\Domain\Notifikasi\Pemberitahu;
use App\Domain\Notifikasi\Peristiwa;
use App\Domain\Notifikasi\Surat;
use App\Models\User;
use App\Models\UsulanPerubahan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Memutuskan usulan: menyetujui atau menolak (T26).
 *
 * Tiga hal yang dijaga di sini dan tidak bisa dijaga di tempat lain:
 *
 *   1. **Baris dikunci sebelum diperiksa.** Dua pemilik yang menekan Setujui
 *      hampir bersamaan akan lolos pemeriksaan status kalau tidak dikunci, dan
 *      keduanya menulis baris tarif - lalu satu di antaranya gagal di unique
 *      index dengan pesan constraint, bukan pesan yang bisa dibaca.
 *
 *   2. **Persetujuan yang gagal tidak menutup usulannya.** Kalau tanggal
 *      berlaku yang diusulkan sudah dipakai tarif lain sejak usulan dibuat,
 *      penerapan gagal dan usulan tetap `menunggu` - pengelola bisa mengajukan
 *      ulang dengan tanggal lain, dan pemilik tidak kehilangan jejak bahwa
 *      usulan itu pernah ada. Menutup usulan sebagai "ditolak" di sini akan
 *      menuliskan alasan yang tidak pernah dipilih siapa pun.
 *
 *   3. **Kedaluwarsa diperiksa saat memutuskan, bukan oleh penjadwal.** Usulan
 *      yang lewat tujuh hari ditolak di sini dengan pesan yang menjelaskan,
 *      tanpa bergantung pada cron yang mungkin tidak pernah berjalan.
 */
class PemutusUsulan
{
    public function __construct(
        private readonly PenerapPerubahan $penerap,
        private readonly PencatatAudit $audit,
        private readonly Pemberitahu $pemberitahu,
    ) {}

    /**
     * @return list<Surat> surat yang harus dikirim setelah transaksi selesai
     *
     * @throws UsulanTidakDapatDiputuskan
     * @throws QueryException SQLSTATE 23505 bila tanggalnya sudah dipakai
     */
    public function setujui(UsulanPerubahan $usulan, User $pemutus): array
    {
        return DB::transaction(function () use ($usulan, $pemutus) {
            $usulan = $this->kunci($usulan);

            $this->pastikanBolehDiputuskan($usulan);

            // Baris sungguhan ditulis di sini, lewat jalur yang sama dengan
            // pemilik yang menekan Simpan langsung. Aksi auditnya berbeda -
            // `tarif.setujui`, bukan `tarif.tambah` - karena kalimat yang
            // dijawabnya berbeda: yang ini menjawab "siapa yang menyetujui",
            // dan pengusulnya sudah tercatat di baris `tarif.usul`.
            //
            // Pemberitahuan `tarif.diubah` tetap dikirim: pengelola lain perlu
            // tahu angkanya berubah, dan merekalah yang menghadapi penghuni
            // yang bertanya. Pengusul menerima dua pemberitahuan - "usulan
            // Anda disetujui" dan "tarif diubah" - dan itu memang dua fakta
            // berbeda; menghapus salah satunya berarti satu di antaranya tidak
            // pernah sampai.
            $hasil = match ($usulan->tipe) {
                UsulanPerubahan::TIPE_TARIF => $this->penerap->tarif(
                    $usulan->payload,
                    JenisUsulan::aksiSetujui($usulan->tipe),
                ),
                UsulanPerubahan::TIPE_KEBIJAKAN => $this->penerap->kebijakan(
                    $usulan->payload,
                    JenisUsulan::aksiSetujui($usulan->tipe),
                ),
                UsulanPerubahan::TIPE_KOMPONEN => $this->penerap->komponen(
                    $usulan->payload,
                    JenisUsulan::aksiSetujui($usulan->tipe),
                ),
                default => throw new \UnexpectedValueException(
                    "Tipe usulan \"{$usulan->tipe}\" tidak dikenal."
                ),
            };

            $usulan->update([
                'status' => UsulanPerubahan::STATUS_DISETUJUI,
                'diputuskan_oleh' => $pemutus->getKey(),
                'diputuskan_pada' => now(),
            ]);

            $pengusul = $usulan->pengusul;

            $keputusan = $this->pemberitahu->dalamTransaksi(
                peristiwa: Peristiwa::USULAN_DIPUTUSKAN,
                penerima: $pengusul !== null ? collect([$pengusul]) : collect(),
                judul: 'Usulan Anda disetujui',
                pesan: sprintf('%s menyetujui usulan Anda. %s Perubahan sudah berlaku.', $pemutus->name, $usulan->ringkasan),
                tautan: '/pemilik/persetujuan',
            );

            return array_values(array_filter([$hasil->surat, $keputusan]));
        });
    }

    /**
     * @return list<Surat>
     *
     * @throws UsulanTidakDapatDiputuskan
     */
    public function tolak(UsulanPerubahan $usulan, User $pemutus, string $alasan): array
    {
        return DB::transaction(function () use ($usulan, $pemutus, $alasan) {
            $usulan = $this->kunci($usulan);

            $this->pastikanBolehDiputuskan($usulan);

            $usulan->update([
                'status' => UsulanPerubahan::STATUS_DITOLAK,
                'diputuskan_oleh' => $pemutus->getKey(),
                'diputuskan_pada' => now(),
                'alasan' => $alasan,
            ]);

            // Penolakan adalah keputusan yang sama nyatanya dengan persetujuan,
            // dan jejaknya harus bisa dibaca dari pertanyaan "kenapa tarif ini
            // tidak jadi naik". Tanpa baris ini, jawabannya hanya ada di kolom
            // `alasan` pada tabel antrean - yang tidak dilihat siapa pun yang
            // membaca log audit.
            $this->audit->catat(
                aksi: JenisUsulan::aksiTolak($usulan->tipe),
                entitas: 'usulan_perubahan',
                entitasId: $usulan->id,
                nilaiLama: ['status' => UsulanPerubahan::STATUS_MENUNGGU],
                nilaiBaru: [
                    'status' => UsulanPerubahan::STATUS_DITOLAK,
                    'alasan' => $alasan,
                    'diputuskan_oleh' => $pemutus->name,
                    'ringkasan' => $usulan->ringkasan,
                ],
            );

            $pengusul = $usulan->pengusul;

            $keputusan = $this->pemberitahu->dalamTransaksi(
                peristiwa: Peristiwa::USULAN_DIPUTUSKAN,
                penerima: $pengusul !== null ? collect([$pengusul]) : collect(),
                judul: 'Usulan Anda ditolak',
                pesan: sprintf('%s menolak usulan "%s". Alasan: %s', $pemutus->name, $usulan->ringkasan, $alasan),
                // Ke halaman persetujuan, bukan ke tagihan: di sanalah usulan
                // itu terlihat beserta alasannya, dan di sana pula pengelola
                // mengajukan ulang. Tautan ke halaman tagihan akan membawa
                // pembacanya ke tempat yang tidak menjawab pertanyaannya.
                tautan: '/pemilik/persetujuan',
            );

            return [$keputusan];
        });
    }

    /**
     * Ambil ulang barisnya dengan kunci tulis.
     *
     * `lockForUpdate` di dalam transaksi: dua pemutus yang berjalan bersamaan
     * akan berbaris, dan yang kedua membaca status yang sudah berubah.
     */
    private function kunci(UsulanPerubahan $usulan): UsulanPerubahan
    {
        return UsulanPerubahan::query()
            ->whereKey($usulan->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @throws UsulanTidakDapatDiputuskan
     */
    private function pastikanBolehDiputuskan(UsulanPerubahan $usulan): void
    {
        if ($usulan->status !== UsulanPerubahan::STATUS_MENUNGGU) {
            throw UsulanTidakDapatDiputuskan::sudahDiputuskan($usulan->status);
        }

        // Diperiksa SETELAH status, bukan sebelumnya: usulan yang sudah
        // disetujui kemarin dan kebetulan lewat tujuh hari harus menjawab
        // "sudah disetujui", bukan "kedaluwarsa".
        if ($usulan->sudahKedaluwarsa()) {
            throw UsulanTidakDapatDiputuskan::kedaluwarsa();
        }
    }
}
