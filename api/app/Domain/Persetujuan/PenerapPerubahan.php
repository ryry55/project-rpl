<?php

namespace App\Domain\Persetujuan;

use App\Domain\Audit\NilaiLama;
use App\Domain\Audit\PencatatAudit;
use App\Domain\Kebijakan\PilihanKebijakan;
use App\Domain\Notifikasi\Pemberitahu;
use App\Domain\Notifikasi\Penerima;
use App\Domain\Notifikasi\Peristiwa;
use App\Models\KebijakanKos;
use App\Models\TarifSewa;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya tempat baris tarif dan kebijakan ditulis.
 *
 * Kenapa dipisah dari controller, padahal sebelumnya blok yang sama hidup di
 * sana. Sejak T26 ada DUA jalan menuju baris yang sama:
 *
 *   1. pemilik menekan Simpan              -> langsung
 *   2. pemilik menyetujui usulan pengelola -> lewat layar persetujuan
 *
 * Kalau masing-masing menulis sendiri, cepat atau lambat keduanya berbeda -
 * yang satu menyimpan `nilai_lama`, yang lain lupa; yang satu menormalkan
 * persen ke dua desimal, yang lain tidak. Bedanya tidak akan terlihat sampai
 * laporan "dari berapa menjadi berapa" menampilkan baris kosong untuk
 * perubahan yang disetujui. Satu kelas, dua pemanggil.
 *
 * `$aksi` dioper, bukan disimpulkan di sini. Barisnya sama, tetapi pertanyaan
 * yang dijawab jejak berbeda: "pemilik menetapkan tarif" dan "pemilik
 * menyetujui usulan pengelola" bukan kalimat yang sama, dan log yang
 * menyebutnya sama menghapus fakta bahwa ada usulan sebelumnya.
 *
 * Notifikasi in-app ditulis **di dalam** transaksi yang sama, bukan oleh
 * pemanggil sesudahnya. Kalau ditulis sesudahnya, ada jendela waktu di mana
 * tarif sudah berubah tetapi pemberitahuannya belum ada - dan kegagalan tepat
 * di jendela itu menghasilkan perubahan tanpa pemberitahuan. Itu kelas
 * kegagalan yang sama dengan jejak audit, jadi diperlakukan dengan cara yang
 * sama. Emailnya tetap dikirim setelah transaksi selesai; lihat Pemberitahu.
 */
class PenerapPerubahan
{
    public function __construct(
        private readonly PencatatAudit $audit,
        private readonly Pemberitahu $pemberitahu,
    ) {}

    /**
     * Tulis satu baris tarif baru beserta jejak dan pemberitahuannya.
     *
     * @param  array{kamar_id: int|string, nilai: int|string, berlaku_dari: string}  $data
     * @param  bool  $beritahu  false pada jalur persetujuan, karena pemberitahuan
     *                          "usulan diputuskan" sudah menjawab pertanyaan yang
     *                          sama kepada orang yang sama
     *
     * @throws QueryException SQLSTATE 23505 bila tanggal
     *                        berlaku itu sudah dipakai
     */
    public function tarif(array $data, string $aksi = PencatatAudit::AKSI_TARIF_TAMBAH, bool $beritahu = true): HasilPenerapan
    {
        return DB::transaction(function () use ($data, $aksi, $beritahu) {
            // Baris yang digantikan: tarif terakhir yang berlaku sebelum
            // tanggal berlaku tarif baru ini. Dicari SEBELUM baris baru dibuat,
            // karena setelahnya "terakhir sebelum tanggal itu" akan menunjuk ke
            // baris baru itu sendiri.
            $sebelumnya = TarifSewa::query()
                ->where('kamar_id', $data['kamar_id'])
                ->where('berlaku_dari', '<', $data['berlaku_dari'])
                ->orderByDesc('berlaku_dari')
                ->orderByDesc('id')
                ->first();

            $tarif = TarifSewa::create($data);

            $this->audit->catat(
                aksi: $aksi,
                entitas: 'tarif_sewa',
                entitasId: $tarif->id,
                nilaiLama: NilaiLama::tarif($sebelumnya),
                nilaiBaru: [
                    'kamar_id' => $tarif->kamar_id,
                    'nilai' => (int) $tarif->nilai,
                    'berlaku_dari' => $tarif->berlaku_dari->toDateString(),
                ],
            );

            $surat = null;
            $pelaku = auth()->user();

            if ($beritahu && $pelaku instanceof User) {
                $surat = $this->pemberitahu->dalamTransaksi(
                    peristiwa: Peristiwa::TARIF_DIUBAH,
                    penerima: Penerima::pengurus($pelaku),
                    judul: 'Tarif sewa diubah',
                    pesan: sprintf(
                        '%s mengubah tarif kamar %s menjadi Rp %s mulai %s.',
                        $pelaku->name,
                        $tarif->kamar?->nomor ?? "id {$tarif->kamar_id}",
                        number_format((int) $tarif->nilai, 0, ',', '.'),
                        $tarif->berlaku_dari->toDateString(),
                    ),
                    tautan: '/pemilik/kamar',
                );
            }

            return new HasilPenerapan($tarif, $surat);
        });
    }

    /**
     * Tulis satu baris kebijakan baru beserta jejak dan pemberitahuannya.
     *
     * @param  array<string, mixed>  $data  hasil `validated()` SimpanKebijakanRequest
     *
     * @throws QueryException SQLSTATE 23505 bila kombinasi
     *                        tipe, kamar, dan tanggal sudah ada
     */
    public function kebijakan(array $data, string $aksi = PencatatAudit::AKSI_KEBIJAKAN_TAMBAH, bool $beritahu = true): HasilPenerapan
    {
        $data = $this->normalkanKolom($data);

        return DB::transaction(function () use ($data, $aksi, $beritahu) {
            // Baris yang digantikan: kebijakan terakhir untuk tipe dan kamar
            // yang sama, berlaku sebelum tanggal yang baru.
            //
            // `kamar_id` dibandingkan dengan `whereNull`/`where` eksplisit,
            // bukan `where('kamar_id', ...)`: `= null` tidak pernah cocok di
            // SQL, sehingga kebijakan default kos akan selalu tampak "belum
            // pernah ada" dan nilai lamanya hilang dari jejak.
            $sebelumnya = KebijakanKos::query()
                ->where('tipe', $data['tipe'])
                ->where('berlaku_dari', '<', $data['berlaku_dari'])
                ->when(
                    ($data['kamar_id'] ?? null) === null,
                    fn ($q) => $q->whereNull('kamar_id'),
                    fn ($q) => $q->where('kamar_id', $data['kamar_id']),
                )
                ->orderByDesc('berlaku_dari')
                ->orderByDesc('id')
                ->first();

            $kebijakan = KebijakanKos::create($data);

            $this->audit->catat(
                aksi: $aksi,
                entitas: 'kebijakan_kos',
                entitasId: $kebijakan->id,
                nilaiLama: NilaiLama::kebijakan($sebelumnya),
                nilaiBaru: [
                    'tipe' => $kebijakan->tipe,
                    'nilai' => $kebijakan->nilai,
                    'kamar_id' => $kebijakan->kamar_id,
                    'persen' => $kebijakan->persen,
                    'plafon_persen' => $kebijakan->plafon_persen,
                    'masa_tenggang' => $kebijakan->masa_tenggang,
                    'nominal' => $kebijakan->nominal,
                    'tanggal' => $kebijakan->tanggal,
                    'berlaku_dari' => $kebijakan->berlaku_dari->toDateString(),
                ],
            );

            $surat = null;
            $pelaku = auth()->user();

            if ($beritahu && $pelaku instanceof User) {
                $surat = $this->pemberitahu->dalamTransaksi(
                    peristiwa: Peristiwa::KEBIJAKAN_DIUBAH,
                    penerima: Penerima::pengurus($pelaku),
                    judul: 'Kebijakan kos diubah',
                    pesan: sprintf(
                        '%s mengubah kebijakan %s menjadi "%s" mulai %s.',
                        $pelaku->name,
                        PilihanKebijakan::labelTipe($kebijakan->tipe),
                        PilihanKebijakan::labelNilai($kebijakan->tipe, $kebijakan->nilai),
                        $kebijakan->berlaku_dari->toDateString(),
                    ),
                    tautan: '/pemilik/kebijakan',
                );
            }

            return new HasilPenerapan($kebijakan, $surat);
        });
    }

    /**
     * Kosongkan kolom angka yang tidak dipakai bentuk kebijakan ini.
     *
     * Validasi sudah melarangnya, tetapi menormalkan di sini membuat perilaku
     * tetap benar bila aturan itu dilonggarkan nanti - dan, sejak T26, karena
     * payload usulan datang dari basis data, bukan langsung dari permintaan
     * yang baru saja divalidasi. Nilai yang tersimpan di `payload` bisa berasal
     * dari versi aturan yang lebih lama.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalkanKolom(array $data): array
    {
        $kolom = PilihanKebijakan::kolomAngka($data['tipe'], $data['nilai']);

        $data['persen'] = in_array('persen', $kolom['wajib'], true)
            ? $this->desimal($data['persen'] ?? null)
            : null;
        $data['plafon_persen'] = in_array('plafon_persen', $kolom['wajib'], true)
            ? $this->desimal($data['plafon_persen'] ?? null)
            : null;
        $data['masa_tenggang'] = in_array('masa_tenggang', $kolom['wajib'], true)
            ? (int) $data['masa_tenggang']
            : null;
        $data['nominal'] = in_array('nominal', $kolom['wajib'], true)
            ? (int) $data['nominal']
            : null;
        $data['tanggal'] = in_array('tanggal', $kolom['wajib'], true)
            ? (int) $data['tanggal']
            : null;

        return $data;
    }

    /**
     * Persentase disimpan sebagai decimal(5,2), jadi nilainya dinormalkan ke dua
     * desimal di sini. Tanpa normalisasi, "1" dan "1.00" akan tersimpan sebagai
     * dua bentuk berbeda dan perbandingan riwayat jadi membingungkan.
     */
    private function desimal(mixed $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        return number_format((float) $nilai, 2, '.', '');
    }
}
