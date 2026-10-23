<?php

namespace App\Domain\Persetujuan;

use App\Domain\Audit\PencatatAudit;
use App\Domain\Notifikasi\Pemberitahu;
use App\Domain\Notifikasi\Penerima;
use App\Domain\Notifikasi\Peristiwa;
use App\Models\User;
use App\Models\UsulanPerubahan;
use Illuminate\Support\Facades\DB;

/**
 * Mencatat usulan perubahan dari pengelola (T26).
 *
 * Yang TIDAK dilakukan kelas ini: menulis `tarif_sewa` atau `kebijakan_kos`.
 * Itu satu-satunya beda antara "mengusulkan" dan "menetapkan", dan menaruh
 * keduanya di satu tempat akan membuat perbedaan wewenang yang sebesar itu
 * hanya terjaga oleh satu `if`.
 *
 * Yang dilakukan: menulis baris usulan, mencatat jejak `*.usul`, dan menyiapkan
 * notifikasi untuk pemilik. Ketiganya di dalam satu transaksi - usulan tanpa
 * jejak tidak bisa dipertanggungjawabkan, dan usulan yang tidak memberi tahu
 * siapa pun adalah usulan yang tidak akan pernah diputuskan.
 */
class PengajuUsulan
{
    public function __construct(
        private readonly PencatatAudit $audit,
        private readonly Pemberitahu $pemberitahu,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  hasil `validated()` FormRequest
     */
    public function ajukan(string $tipe, array $payload, User $pengusul): HasilUsulan
    {
        if (! JenisUsulan::dikenal($tipe)) {
            throw new \UnexpectedValueException("Tipe usulan \"{$tipe}\" tidak dikenal.");
        }

        $ringkasan = JenisUsulan::ringkasan($tipe, $payload);

        [$usulan, $surat] = DB::transaction(function () use ($tipe, $payload, $pengusul, $ringkasan) {
            $sekarang = now();

            $usulan = UsulanPerubahan::create([
                'tipe' => $tipe,
                'payload' => $payload,
                'ringkasan' => $ringkasan,
                'status' => UsulanPerubahan::STATUS_MENUNGGU,
                'diajukan_oleh' => $pengusul->getKey(),
                'dibuat_pada' => $sekarang,
                // Tujuh hari. Cukup lama untuk pemilik yang sedang di luar kota,
                // cukup pendek untuk tidak menggantung.
                'kedaluwarsa_pada' => $sekarang->copy()->addDays(UsulanPerubahan::HARI_KEDALUWARSA),
            ]);

            // Jejak ditulis di sini, bukan saat disetujui. Usulan yang tidak
            // pernah diputuskan tetap harus meninggalkan jejak bahwa ia pernah
            // diajukan - kalau tidak, pertanyaan "siapa yang minta tarif ini
            // naik" tidak bisa dijawab untuk usulan yang ditolak.
            $this->audit->catat(
                aksi: JenisUsulan::aksiUsul($tipe),
                entitas: 'usulan_perubahan',
                entitasId: $usulan->id,
                nilaiLama: null,
                nilaiBaru: [
                    'tipe' => $tipe,
                    'ringkasan' => $ringkasan,
                    'diajukan_oleh' => $pengusul->name,
                    'kedaluwarsa_pada' => $usulan->kedaluwarsa_pada->toDateTimeString(),
                ],
            );

            // Pemilik saja: pengelolalah yang mengusulkan, jadi ia tidak perlu
            // diberi tahu tentang perbuatannya sendiri.
            $surat = $this->pemberitahu->dalamTransaksi(
                peristiwa: Peristiwa::USULAN_DIAJUKAN,
                penerima: Penerima::pemilik($pengusul),
                judul: 'Usulan perubahan menunggu keputusan',
                pesan: sprintf('%s mengusulkan: %s Usulan ditutup otomatis dalam 7 hari.', $pengusul->name, $ringkasan),
                tautan: '/pemilik/persetujuan',
            );

            return [$usulan, $surat];
        });

        return new HasilUsulan($usulan, $surat);
    }
}
