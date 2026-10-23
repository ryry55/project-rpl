<?php

namespace App\Domain\Tarif;

use App\Domain\Kebijakan\PenyelesaiKebijakan;
use App\Models\Kamar;
use App\Models\KomponenBiaya;
use App\Models\ParameterDenda;
use App\Models\TarifSewa;
use Carbon\CarbonInterface;

/**
 * Pencarian tarif yang berlaku pada suatu periode — lapis pertama R-06.
 *
 * Aturan: tarif untuk periode P adalah baris dengan `berlaku_dari` terbesar
 * yang masih <= awal periode P. Baris lama tidak pernah diubah, jadi hasil
 * fungsi ini stabil terhadap waktu: periode yang sama selalu memberi tarif
 * yang sama, berapa kali pun dipanggil.
 *
 * Sengaja tidak memakai Eloquent di dalam logika pemilihan: hanya query, lalu
 * keputusan diambil di PHP agar bisa diuji tanpa basis data.
 */
class PencariTarif
{
    public function __construct(private readonly PenyelesaiKebijakan $penyelesai) {}

    /**
     * Tarif sewa kamar yang berlaku pada periode tertentu.
     */
    public function sewaUntuk(Kamar $kamar, CarbonInterface $periode): ?TarifSewa
    {
        return TarifSewa::where('kamar_id', $kamar->id)
            ->whereDate('berlaku_dari', '<=', $periode->copy()->startOfMonth())
            ->orderByDesc('berlaku_dari')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Komponen biaya yang berlaku pada periode tertentu, dikelompokkan per nama.
     *
     * Satu nama bisa punya banyak baris riwayat; yang dipakai hanya baris
     * terakhir yang <= periode. Baris bernilai 0 berarti komponen itu
     * dihentikan — tetap dikembalikan supaya pemanggil bisa membedakan
     * "tidak pernah ada" dari "dihentikan".
     *
     * Bila `$kamar` diberikan, komponen khusus kamar itu ikut dipertimbangkan
     * dan **menimpa** komponen global dengan nama yang sama. Itu yang membuat
     * "air hanya kamar 3" bisa dinyatakan tanpa mengubah tagihan kamar lain.
     *
     * Tanpa `$kamar`, hanya komponen global yang dikembalikan. Pemanggil lama
     * (mis. daftar komponen di UI) tetap mendapat perilaku yang sama seperti
     * sebelum T11.
     *
     * Sejak T16 logikanya tinggal di {@see PenyelesaiKebijakan::komponenBiaya},
     * karena `kebijakan_kos` memakai pola "khusus kamar menimpa global" yang
     * sama. Metode ini dipertahankan sebagai pintu masuk lama supaya pemanggil
     * dan tes yang sudah ada tidak perlu diubah sekaligus — bukan karena masih
     * ada dua implementasi.
     *
     * @return list<KomponenBiaya>
     */
    public function komponenUntuk(CarbonInterface $periode, ?Kamar $kamar = null): array
    {
        return $this->penyelesai->komponenBiaya($periode, $kamar);
    }

    /**
     * Parameter denda yang berlaku pada periode tertentu.
     */
    public function parameterDendaUntuk(CarbonInterface $periode): ?ParameterDenda
    {
        return ParameterDenda::whereDate('berlaku_dari', '<=', $periode->copy()->startOfMonth())
            ->orderByDesc('berlaku_dari')
            ->orderByDesc('id')
            ->first();
    }
}
