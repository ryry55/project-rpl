<?php

namespace App\Http\Resources;

use App\Models\Perangkat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Perangkat kos untuk layar pengurus (K-16).
 *
 * **Sandi tidak pernah ikut di sini.** Daftar perangkat dibuka setiap kali
 * halaman diragukan isinya, dan mengirim seluruh sandi pada setiap pembukaan
 * berarti sandi CCTV ikut melintas di layar yang mungkin sedang dipresentasikan
 * atau diproyeksikan. Endpoint `GET /perangkat/{id}/sandi` yang mengirimkannya,
 * saat benar-benar diminta.
 *
 * @mixin Perangkat
 */
class PerangkatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'jenis' => $this->jenis,
            'label_jenis' => Perangkat::LABEL_JENIS[$this->jenis] ?? $this->jenis,
            'nama' => $this->nama,
            'lokasi' => $this->lokasi,
            'nama_pengguna' => $this->nama_pengguna,
            'catatan' => $this->catatan,
            // Layar perlu tahu apakah tombol "Lihat sandi" ada gunanya, tanpa
            // harus meminta sandinya lebih dulu.
            'ada_sandi' => $this->sandi !== null && $this->sandi !== '',
            'aktif' => $this->aktif,
            'urutan' => $this->urutan,
        ];
    }
}
