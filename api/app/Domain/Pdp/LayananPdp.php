<?php

namespace App\Domain\Pdp;

use App\Models\LogAudit;
use App\Models\Penghuni;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pemenuhan hak subjek data: akses, koreksi, dan penghapusan (T28).
 *
 * UU 27/2022 memberi tiga hak yang bertabrakan langsung dengan dua janji teknis
 * proyek ini, dan tabrakannya harus diselesaikan di kode, bukan di slide:
 *
 *   1. **Hak akses** - mudah: subjek data boleh melihat seluruh data pribadinya.
 *      Yang perlu diputuskan adalah APA yang termasuk "miliknya". Jawabannya di
 *      sini: profil, kontak, kontrak, tagihan, dan pembayaran atas namanya.
 *      Bukan: data penghuni lain, kebijakan kos, atau tarif - itu data kos,
 *      bukan data pribadi orang.
 *
 *   2. **Hak koreksi** - mudah juga, dan sudah ada: `PUT /penghuni/{id}`.
 *      Yang ditambahkan di sini adalah jalan bagi penghuni untuk MELIHAT apa
 *      yang tercatat, karena hak mengoreksi tanpa hak melihat hanya berguna
 *      bagi orang yang sudah tahu isinya.
 *
 *   3. **Hak penghapusan** - inilah tabrakannya. R-06 dan R-07 menuntut
 *      `log_audit`, `pembayaran`, dan `tagihan` bersifat append-only. Menghapus
 *      barisnya berarti menghapus bukti bahwa uang pernah diterima dan siapa
 *      yang menerimanya - dan pihak yang paling diuntungkan oleh hilangnya
 *      bukti itu adalah orang yang menyelewengkannya.
 *
 *      Penyelesaiannya **anonimisasi, bukan penghapusan**: identitas dilepas,
 *      catatan tetap. Nama menjadi "pengguna dihapus", email dan nomor HP
 *      dikosongkan, akun login dimatikan, dan setiap baris `log_audit` yang
 *      menyebut nama itu dianonimkan lewat satu-satunya celah yang diizinkan
 *      trigger (lihat migrasi 090100). Yang tetap ada: angka, tanggal, dan
 *      tindakan. Yang hilang: siapa.
 *
 *      Ini bukan kompromi setengah hati. UU PDP mengatur pemrosesan data
 *      pribadi; catatan bahwa "seseorang membayar Rp 1.500.000 pada 3 Oktober"
 *      setelah identitasnya dilepas bukan lagi data pribadi, sedangkan bukti
 *      keuangannya tetap utuh. Yang TIDAK boleh terjadi adalah menyebut hak
 *      penghapusan sudah dipenuhi padahal namanya masih terbaca.
 */
class LayananPdp
{
    public const NAMA_ANONIM = 'pengguna dihapus';

    /**
     * Seluruh data pribadi satu penghuni, untuk ditampilkan kepadanya.
     *
     * @return array<string, mixed>
     */
    public function dataPribadi(User $user): array
    {
        $penghuni = $user->penghuni;

        return [
            'akun' => [
                'nama' => $user->name,
                'email' => $user->email,
                'peran' => $user->peran,
                'dibuat_pada' => $user->created_at?->toIso8601String(),
                'email_terverifikasi' => $user->email_verified_at !== null,
            ],
            'penghuni' => $penghuni ? [
                'nama' => $penghuni->nama,
                'email' => $penghuni->email,
                'no_hp' => $penghuni->no_hp,
            ] : null,
            'kontrak' => $penghuni
                ? $penghuni->kontrak()->with('kamar')->orderBy('tgl_masuk')->get()->map(fn ($k) => [
                    'id' => $k->id,
                    'kamar' => $k->kamar?->nomor,
                    'tgl_masuk' => $k->tgl_masuk?->toDateString(),
                    'tgl_keluar' => $k->tgl_keluar?->toDateString(),
                    'status' => $k->status,
                    'tarif_awal' => (int) $k->tarif_awal,
                ])->all()
                : [],
            'persetujuan' => $user->persetujuanPdp()
                ->orderBy('disetujui_pada')
                ->get()
                ->map(fn ($p) => [
                    'versi' => $p->versi,
                    'disetujui_pada' => $p->disetujui_pada?->toIso8601String(),
                ])->all(),
            // Catatan yang menyebut namanya, dalam bentuk yang bisa ia baca.
            // Bukan seluruh isi log audit - itu data kos, bukan data pribadinya.
            'jejak_terkait' => LogAudit::query()
                ->where('aktor_id', $user->id)
                ->orderByDesc('dibuat_pada')
                ->limit(200)
                ->get()
                ->map(fn ($l) => [
                    'aksi' => $l->aksi,
                    'entitas' => $l->entitas,
                    'entitas_id' => $l->entitas_id,
                    'dibuat_pada' => $l->dibuat_pada?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * Lepaskan identitas, pertahankan catatan.
     *
     * Urutannya penting dan sengaja begini:
     *
     *   1. Anonimisasi `log_audit` LEBIH DULU, di dalam transaksi yang sama
     *      dengan penanda `SET LOCAL`. Trigger menolak UPDATE tanpa penanda itu,
     *      jadi urutan terbalik akan gagal di tengah jalan dan meninggalkan
     *      keadaan setengah jadi.
     *   2. Baru kemudian identitas di tabel lain dikosongkan.
     *
     * Penghuni yang masih punya kontrak aktif TIDAK boleh dihapus. Bukan karena
     * teknis, melainkan karena artinya: kontrak aktif berarti ia masih menempati
     * kamar dan masih punya kewajiban bayar. Menghapus identitasnya membuat
     * tagihan berikutnya menunjuk orang yang tidak ada.
     */
    public function hapus(User $user): void
    {
        DB::transaction(function () use ($user) {
            // Penanda hanya berlaku untuk transaksi ini. `SET LOCAL` dipilih,
            // bukan `SET`, karena PHP-FPM memakai ulang koneksi antar-permintaan
            // dan penanda yang bocor akan membuka celah anonimisasi untuk
            // permintaan berikutnya.
            DB::statement("SET LOCAL kos.anonimisasi = 'on'");

            LogAudit::query()
                ->where('aktor_id', $user->id)
                ->update([
                    'aktor_id' => null,
                    'aktor_nama' => self::NAMA_ANONIM,
                ]);

            $penghuni = $user->penghuni;

            if ($penghuni) {
                // Email dan nomor HP dikosongkan, bukan dihapus barisnya:
                // kontrak dan tagihan menunjuk penghuni ini lewat foreign key,
                // dan menghapus barisnya akan memutus riwayat keuangan.
                //
                // Email harus null, bukan string kosong: kolomnya punya unique
                // index, dan dua penghuni yang dihapus dengan email '' akan
                // saling bertabrakan.
                $penghuni->forceFill([
                    'nama' => self::NAMA_ANONIM,
                    'email' => null,
                    'no_hp' => null,
                ])->save();
            }

            // Token dan sesi diputus lebih dulu supaya tidak ada permintaan
            // yang masih berjalan sebagai akun ini saat datanya dilepas.
            $user->tokens()->delete();
            $user->notifications()->delete();
            $user->tokenAkun()->delete();

            // Akun login dimatikan sepenuhnya: peran diturunkan menjadi
            // penghuni tanpa penghuni_id, email diganti alamat yang tidak bisa
            // dipakai masuk, dan sandi diacak. Barisnya TIDAK dihapus karena
            // `pembayaran.dicatat_oleh` dan `usulan_perubahan.diajukan_oleh`
            // memakai RESTRICT - dan menghapusnya akan menghapus riwayat uang.
            $user->forceFill([
                'name' => self::NAMA_ANONIM,
                'email' => 'dihapus+'.$user->id.'@invalid.local',
                'password' => bcrypt(bin2hex(random_bytes(32))),
                'peran' => User::PERAN_PENGHUNI,
                'penghuni_id' => null,
                'pemilik_id' => null,
                'email_verified_at' => null,
                'wajib_ganti_sandi' => false,
            ])->save();

            $user->kewenangan()->delete();
        });
    }

    /**
     * Apakah penghapusan boleh dilakukan sekarang.
     *
     * @return string|null alasan penolakan, atau null bila boleh
     */
    public function alasanTidakBolehHapus(User $user): ?string
    {
        if ($user->isPemilik()) {
            return 'Akun pemilik tidak bisa dihapus dari aplikasi. Hubungi pengelola sistem.';
        }

        if ($user->isPengelola()) {
            // Pengelola yang pernah mengubah tarif atau mencatat pembayaran
            // disebut namanya di jejak audit. Anonimisasi akan melepas namanya
            // dari sana juga, tetapi pengelolanya sendiri masih bisa dipakai
            // orang lain untuk mengurus kos - dan itu harus dicabut lebih dulu
            // oleh pemilik, bukan dihapus sendiri.
            return 'Akun pengelola dicabut oleh pemilik lebih dulu, bukan dihapus sendiri.';
        }

        $penghuni = $user->penghuni;

        if ($penghuni && $penghuni->kontrak()->where('status', 'aktif')->exists()) {
            return 'Anda masih punya kontrak aktif. Ajukan penghapusan setelah kontrak berakhir.';
        }

        return null;
    }
}
