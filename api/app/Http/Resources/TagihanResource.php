<?php

namespace App\Http\Resources;

use App\Domain\Billing\PenghitungDenda;
use App\Models\Tagihan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tagihan
 */
class TagihanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Denda dihitung saat dibaca, bukan disimpan sebagai kolom: nilainya
        // bergantung pada "hari ini", jadi kolom akan selalu basi dan butuh job
        // penyegar. Perhatikan bahwa ini hanya dihitung bila relasi/tagihan
        // memang diminta — daftar tagihan tetap ringan.
        $hasilDenda = app(PenghitungDenda::class)->hitung($this->resource);

        $dibayar = $this->totalDibayar();

        // Tagihan yang sudah lunas memakai `total_final` yang dibekukan saat
        // pelunasan. Tanpa itu, denda yang berhenti berjalan akan membuat
        // "total" turun menjadi sebesar pokok saja sementara "dibayar" tetap
        // sebesar pokok + denda — dua angka yang bertentangan di satu layar.
        $total = $this->total_final !== null
            ? (int) $this->total_final
            : $hasilDenda->total();

        $denda = $this->total_final !== null
            ? max(0, $total - $hasilDenda->pokok)
            : $hasilDenda->denda;

        return [
            'id' => $this->id,
            'kontrak_id' => $this->kontrak_id,
            'periode' => $this->periode?->toDateString(),
            'pokok' => (int) $this->pokok,
            'jatuh_tempo' => $this->jatuh_tempo?->toDateString(),
            'status' => $this->status,
            'terbit_pada' => $this->terbit_pada?->toIso8601String(),

            'denda' => $denda,
            'total' => $total,

            // Sisa tidak pernah negatif. Kelebihan bayar ditolak sebelum
            // tersimpan, jadi angka negatif di sini hanya akan berarti ada bug —
            // dan `max(0, ...)` membuatnya terlihat sebagai nol, bukan sebagai
            // utang terbalik yang membingungkan.
            'dibayar' => $dibayar,
            'sisa' => max(0, $total - $dibayar),
            'lunas_pada_total' => $this->total_final !== null ? (int) $this->total_final : null,

            // Ringkasan denda ikut dikirim supaya layar tidak perlu memanggil
            // endpoint /denda hanya untuk menampilkan "terlambat 12 hari".
            //
            // Sejak T17 bentuknya bergantung tipe: `plafon` dan
            // `sisa_hari_ke_plafon` bernilai null untuk denda nominal dan
            // "tanpa denda", karena keduanya tidak punya batas atas yang bisa
            // didekati. `berjalan` mengirim kesimpulan itu sekali, supaya layar
            // tidak perlu menyalin daftar tipe mana yang bertambah tiap hari.
            'denda_ringkas' => [
                'tipe' => $hasilDenda->tipe,
                // `bentuk` membedakan tiga kalimat di layar. `berjalan` saja
                // tidak cukup: nilainya sama untuk "nominal tetap" dan "tanpa
                // denda", sehingga penghuni yang kosnya tidak menerapkan denda
                // akan membaca "Nominal tetap per bulan".
                'bentuk' => $hasilDenda->bentuk(),
                'berjalan' => $hasilDenda->berjalan(),
                'hari_terlambat' => $hasilDenda->hariTerlambat,
                'masa_tenggang' => $hasilDenda->masaTenggang,
                'hari_denda' => $hasilDenda->hariDenda,
                'plafon' => $hasilDenda->plafon,
                'sisa_hari_ke_plafon' => $hasilDenda->sisaHariKePlafon,
                'berhenti' => $hasilDenda->berhenti,
                'alasan_berhenti' => $hasilDenda->alasanBerhenti,
            ],

            'rincian' => $this->whenLoaded('rincian', fn () => $this->rincian->map(fn ($baris) => [
                'id' => $baris->id,
                'nama' => $baris->nama,
                'nilai' => (int) $baris->nilai,
                'urutan' => $baris->urutan,
            ])->values()),

            'pembayaran' => $this->whenLoaded('pembayaran', fn () => $this->pembayaran
                ->map(fn ($baris) => [
                    'id' => $baris->id,
                    'jumlah' => (int) $baris->jumlah,
                    'tanggal_pembayaran' => $baris->tanggal_pembayaran?->toDateString(),
                    'catatan' => $baris->catatan,
                    'dicatat_pada' => $baris->created_at?->toIso8601String(),
                ])
                ->values()),

            'kontrak' => $this->whenLoaded('kontrak', fn () => $this->kontrak
                ? [
                    'id' => $this->kontrak->id,
                    'kamar' => $this->kontrak->kamar
                        ? ['id' => $this->kontrak->kamar->id, 'nomor' => $this->kontrak->kamar->nomor]
                        : null,
                    'penghuni' => $this->kontrak->penghuni
                        ? ['id' => $this->kontrak->penghuni->id, 'nama' => $this->kontrak->penghuni->nama]
                        : null,
                ]
                : null),

            // Snapshot beku (R-06 lapis 2). Dikirim apa adanya supaya bisa
            // diperiksa: tarif mana yang dipakai saat tagihan ini terbit.
            'tarif_terpakai' => $this->tarif_terpakai,

            // Bentuk denda yang dibekukan saat terbit. Tanpa ini, layar tidak
            // bisa menjelaskan mengapa denda tagihan ini persen sementara
            // tagihan bulan berikutnya nominal.
            'kebijakan_terpakai' => $this->kebijakan_terpakai,
        ];
    }
}
