<?php

namespace App\Http\Resources;

use App\Domain\Kebijakan\PilihanKebijakan;
use App\Models\KebijakanKos;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris kebijakan.
 *
 * `label_tipe` dan `label_nilai` dikirim dari server, bukan disusun di layar.
 * Alasannya sama dengan `dihentikan` di KomponenBiayaResource: teks yang
 * menjelaskan arti sebuah nilai adalah bagian dari arti nilai itu, dan
 * menyalinnya ke JavaScript membuat dua tempat harus setuju soal istilah.
 *
 * @mixin KebijakanKos
 */
class KebijakanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // null berarti berlaku untuk seluruh kos.
            'kamar_id' => $this->kamar_id,
            'tipe' => $this->tipe,
            'nilai' => $this->nilai,
            'label_tipe' => PilihanKebijakan::labelTipe($this->tipe),
            'label_nilai' => PilihanKebijakan::labelNilai($this->tipe, $this->nilai),
            // Hanya terisi untuk jatuh tempo "tanggal tetap".
            'tanggal' => $this->tanggal,
            // Kolom angka dikirim apa adanya, termasuk yang NULL. Layar butuh
            // membedakan "tidak dipakai" dari "dipakai tetapi nol", dan
            // mengirimnya sebagai 0 akan menghapus perbedaan itu. Persentase
            // dikirim sebagai string agar tidak pernah melewati float.
            'persen' => $this->persen,
            'plafon_persen' => $this->plafon_persen,
            'masa_tenggang' => $this->masa_tenggang,
            'nominal' => $this->nominal,
            // Ringkasan satu baris untuk riwayat, disusun server supaya layar
            // tidak perlu tahu bentuk tiap tipe denda.
            'ringkasan' => $this->ringkasan(),
            'berlaku_dari' => $this->berlaku_dari?->toDateString(),
        ];
    }

    /**
     * Satu kalimat yang menjelaskan angka-angka di baris ini.
     *
     * Ditaruh di resource, bukan di Vue, karena kalimatnya bergantung pada
     * pasangan tipe+nilai yang hanya diketahui pasti di sini — dan karena
     * riwayat kebijakan dibaca sebagai penjelasan, bukan sebagai angka mentah.
     */
    private function ringkasan(): string
    {
        return match (true) {
            $this->tipe === PilihanKebijakan::TIPE_DENDA
                && in_array($this->nilai, [PilihanKebijakan::DENDA_PERSEN_HARIAN, PilihanKebijakan::DENDA_PERSEN_BULANAN], true) => sprintf(
                    '%s%% per %s, plafon %s%%, tenggang %d hari',
                    rtrim(rtrim((string) $this->persen, '0'), '.'),
                    $this->nilai === PilihanKebijakan::DENDA_PERSEN_HARIAN ? 'hari' : 'bulan',
                    rtrim(rtrim((string) $this->plafon_persen, '0'), '.'),
                    (int) $this->masa_tenggang,
                ),

            $this->tipe === PilihanKebijakan::TIPE_DENDA
                && $this->nilai === PilihanKebijakan::DENDA_NOMINAL_TETAP => sprintf(
                    'Rp %s per bulan keterlambatan',
                    number_format((int) $this->nominal, 0, ',', '.'),
                ),

            $this->tipe === PilihanKebijakan::TIPE_DENDA
                && $this->nilai === PilihanKebijakan::DENDA_TANPA_DENDA => 'Keterlambatan tidak didenda.',

            $this->tipe === PilihanKebijakan::TIPE_JATUH_TEMPO
                && $this->nilai === PilihanKebijakan::JATUH_TEMPO_TANGGAL_TETAP => sprintf(
                    'Tanggal %d tiap bulan',
                    (int) $this->tanggal,
                ),

            default => '',
        };
    }
}
