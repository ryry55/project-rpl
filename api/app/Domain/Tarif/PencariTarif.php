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
     * @return list<KomponenBiaya>
     */
    public function komponenUntuk(CarbonInterface $periode): array
    {
        $awal = $periode->copy()->startOfMonth();

        $semua = KomponenBiaya::whereDate('berlaku_dari', '<=', $awal)
            ->orderBy('nama')
            ->orderByDesc('berlaku_dari')
            ->orderByDesc('id')
            ->get();

        $terakhir = [];
        foreach ($semua as $baris) {
            // Karena sudah diurutkan, kemunculan pertama per nama adalah yang terbaru.
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
