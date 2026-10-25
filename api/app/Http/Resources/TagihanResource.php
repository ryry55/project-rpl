<?php

namespace App\Http\Resources;

use App\Domain\Billing\PenghitungDenda;
use App\Domain\Pembayaran\MetodePembayaran;
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

            // Pemaafan denda (REV-FIN-07). Dikirim terpisah dari `denda` supaya
            // layar bisa menjelaskan angkanya: "denda Rp 450.000, dimaafkan Rp
            // 450.000" berbeda artinya dari "tidak ada denda", dan hanya angka
            // nol di `denda` tidak bisa membedakan keduanya.
            'denda_dimaafkan' => (int) ($this->denda_dimaafkan ?? 0),
            'alasan_maaf_denda' => $this->alasan_maaf_denda,
            'denda_dimaafkan_pada' => $this->denda_dimaafkan_pada?->toIso8601String(),
            'denda_dimaafkan_oleh_nama' => $this->relationLoaded('pemaafDenda')
                ? $this->pemaafDenda?->name
                : null,

            // Sisa tidak pernah negatif. Kelebihan bayar ditolak sebelum
            // tersimpan, jadi angka negatif di sini hanya akan berarti ada bug —
            // dan `max(0, ...)` membuatnya terlihat sebagai nol, bukan sebagai
            // utang terbalik yang membingungkan.
            'dibayar' => $dibayar,
            'sisa' => max(0, $total - $dibayar),
            'lunas_pada_total' => $this->total_final !== null ? (int) $this->total_final : null,

            // Pembatalan tagihan (REV-FIN-02). Baris yang dibatalkan tidak
            // dihapus, jadi keterangannya harus ikut terbaca: tanpa alasan dan
            // pelakunya, "dibatalkan" hanya berarti "seseorang memutuskan
            // sesuatu pada suatu waktu", dan pemilik yang menemukannya enam
            // bulan kemudian tidak bisa menilai apakah keputusannya masih sah.
            'alasan_batal' => $this->alasan_batal,
            'dibatalkan_pada' => $this->dibatalkan_pada?->toIso8601String(),
            'dibatalkan_oleh_nama' => $this->relationLoaded('pembatal')
                ? $this->pembatal?->name
                : null,

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
                // Pemaafan ikut ke ringkasan supaya daftar tagihan bisa
                // menampilkan "denda Rp 450.000, dimaafkan" tanpa memanggil
                // `/denda` untuk setiap baris.
                'denda_sebelum_maaf' => $hasilDenda->dendaSebelumMaaf,
                'dimaafkan' => $hasilDenda->dimaafkan,
                'ada_pemaafan' => $hasilDenda->adaPemaafan(),
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

                    // Sejak REV-FIN-01 satu baris riwayat bisa berupa pembalik:
                    // uang yang ditarik kembali, bukan diterima. Tanpa penanda
                    // ini, baris negatif di daftar pembayaran terbaca sebagai
                    // salah hitung, bukan sebagai koreksi yang disengaja.
                    'pembalik_id' => $baris->pembalik_id,
                    'adalah_pembalik' => $baris->adalahPembalik(),
                    // `null` berarti relasinya tidak dimuat; `false` berarti sudah
                    // diperiksa dan baris ini belum ditarik. Tombol "Balik"
                    // hanya boleh muncul saat nilainya benar-benar `false`.
                    'sudah_dibalik' => $baris->relationLoaded('pembalik')
                        ? $baris->pembalik !== null
                        : null,

                    // Sejak T20 riwayat ini juga menjawab "bagaimana uangnya
                    // masuk" dan "sudah diperiksa atau belum". Tanpa keduanya,
                    // layar Tagihan menampilkan deretan angka yang tidak bisa
                    // dicocokkan dengan rekening maupun dengan setoran tunai.
                    //
                    // `metode` sengaja dikirim mentah DAN berlabel: kode dipakai
                    // untuk memilih ikon/warna, label untuk dibaca. Menyimpulkan
                    // label di peramban berarti daftar kosakata kedua yang harus
                    // dijaga sinkron dengan `MetodePembayaran`.
                    'metode' => $baris->metode,
                    'metode_label' => $baris->metode === null
                        ? 'Tidak tercatat'
                        : (MetodePembayaran::label()[$baris->metode] ?? $baris->metode),
                    'referensi' => $baris->referensi,
                    'diterima_oleh' => $baris->diterima_oleh,
                    'diterima_oleh_nama' => $baris->relationLoaded('penerima')
                        ? $baris->penerima?->name
                        : null,
                    // `null` berarti BELUM diperiksa - bukan ditolak. Layar yang
                    // tidak bisa membedakan keduanya akan menandai uang yang
                    // belum sempat diperiksa sebagai uang bermasalah.
                    'verifikasi_terakhir' => $baris->relationLoaded('verifikasi')
                        ? ($baris->verifikasi->last() === null ? null : [
                            'keputusan' => $baris->verifikasi->last()->keputusan,
                            'catatan' => $baris->verifikasi->last()->catatan,
                            'diverifikasi_pada' => $baris->verifikasi->last()->created_at?->toIso8601String(),
                        ])
                        : null,
                ])
                ->values()),

            'kontrak' => $this->whenLoaded('kontrak', fn () => $this->kontrak
                ? [
                    'id' => $this->kontrak->id,
                    'kamar' => $this->kontrak->kamar
                        ? ['id' => $this->kontrak->kamar->id, 'nomor' => $this->kontrak->kamar->nomor]
                        : null,
                    'penghuni' => $this->kontrak->penghuni
                        ? [
                            'id' => $this->kontrak->penghuni->id,
                            'nama' => $this->kontrak->penghuni->nama,
                            // Nomor telepon ikut dikirim (REV-UI-03) karena
                            // tombol "Kirim lewat WhatsApp" butuh tujuannya.
                            // Tanpa ini, layar harus memanggil `/penghuni/{id}`
                            // terpisah hanya untuk satu nomor - satu permintaan
                            // tambahan per tagihan yang dibuka.
                            //
                            // Ini data pribadi, tetapi sudah berada di tangan
                            // orang yang membacanya: pemilik dan pengelola kos
                            // melihat nama dan kamar penghuni yang sama di
                            // baris ini. Penghuni hanya melihat tagihannya
                            // sendiri (R-07), jadi tidak ada yang bocor.
                            'no_hp' => $this->kontrak->penghuni->no_hp,
                        ]
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
