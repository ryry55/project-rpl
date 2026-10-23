<?php

namespace App\Http\Resources;

use App\Domain\Persetujuan\JenisUsulan;
use App\Models\UsulanPerubahan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu usulan perubahan (T26).
 *
 * `status_efektif` dikirim terpisah dari `status`, dan itu bukan kerapian.
 * Kolom `status` menyimpan apa yang benar-benar diputuskan; kedaluwarsa
 * dihitung dari waktu. Kalau layar memakai `status` saja, usulan yang sudah
 * lewat tujuh hari akan tampil sebagai "Menunggu" dengan tombol Setujui yang
 * pasti ditolak server - dan tombol yang tidak bekerja adalah keluhan yang
 * paling sulit dijelaskan pemilik.
 *
 * @mixin UsulanPerubahan
 */
class UsulanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipe' => $this->tipe,
            'label_tipe' => JenisUsulan::label($this->tipe),
            // Ringkasan beku, disusun saat usulan dibuat. Tidak disusun ulang
            // di sini: kamar yang berganti nomor akan mengubah bunyi usulan
            // lama, dan pemilik yang menyetujui "kamar A1" tiba-tiba membaca
            // "kamar B3" di riwayat.
            'ringkasan' => $this->ringkasan,
            'status' => $this->status,
            'status_efektif' => $this->statusEfektif(),
            'bisa_diputuskan' => $this->statusEfektif() === UsulanPerubahan::STATUS_MENUNGGU,
            'pengusul' => $this->whenLoaded('pengusul', fn () => $this->pengusul?->name),
            'pemutus' => $this->whenLoaded('pemutus', fn () => $this->pemutus?->name),
            'alasan' => $this->alasan,
            'dibuat_pada' => $this->dibuat_pada?->toIso8601String(),
            'diputuskan_pada' => $this->diputuskan_pada?->toIso8601String(),
            'kedaluwarsa_pada' => $this->kedaluwarsa_pada?->toIso8601String(),
            // Berapa hari lagi sebelum ditutup. Dihitung server supaya layar
            // tidak perlu tahu zona waktu server, dan supaya hitungannya sama
            // dengan yang dipakai saat memutuskan.
            'sisa_hari' => $this->sisaHari(),
            // Isi usulan dikirim apa adanya. Layar persetujuan perlu
            // menampilkannya untuk pemilik yang ingin memeriksa angkanya, dan
            // menyembunyikannya hanya membuat pemilik menyetujui sesuatu yang
            // tidak bisa ia baca.
            'payload' => $this->payload,
        ];
    }

    private function sisaHari(): ?int
    {
        if ($this->statusEfektif() !== UsulanPerubahan::STATUS_MENUNGGU) {
            return null;
        }

        // Dibulatkan ke atas: usulan yang tinggal 4 jam lagi masih punya
        // "1 hari", bukan "0 hari" - nol terbaca sebagai sudah habis.
        return (int) ceil(now()->diffInHours($this->kedaluwarsa_pada, false) / 24);
    }
}
