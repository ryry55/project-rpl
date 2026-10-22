<?php

namespace App\Domain\Tarif;

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
     * @return list<KomponenBiaya>
     */
    public function komponenUntuk(CarbonInterface $periode, ?Kamar $kamar = null): array
    {
        $awal = $periode->copy()->startOfMonth();

        $semua = KomponenBiaya::query()
            ->whereDate('berlaku_dari', '<=', $awal)
            ->when(
                $kamar !== null,
                // Global (kamar_id NULL) + khusus kamar ini.
                fn ($q) => $q->where(fn ($q2) => $q2
                    ->whereNull('kamar_id')
                    ->orWhere('kamar_id', $kamar->id)),
                // Tanpa kamar: hanya global, supaya daftar di UI tidak
                // menampilkan komponen milik kamar lain.
                fn ($q) => $q->whereNull('kamar_id'),
            )
            ->orderBy('nama')
            // Baris khusus kamar menang atas baris global. Diurutkan naik
            // supaya NULL (global) datang lebih dulu, lalu ditimpa.
            ->orderByRaw('kamar_id IS NULL ASC')
            ->orderByDesc('berlaku_dari')
            ->orderByDesc('id')
            ->get();

        $terakhir = [];
        foreach ($semua as $baris) {
            // Karena sudah diurutkan, kemunculan pertama per nama adalah yang
            // berlaku: khusus kamar lebih dulu, lalu yang paling baru.
            if (! isset($terakhir[$baris->nama])) {
                $terakhir[$baris->nama] = $baris;
            }
        }

        return array_values($terakhir);
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
