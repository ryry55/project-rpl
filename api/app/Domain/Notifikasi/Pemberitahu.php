<?php

namespace App\Domain\Notifikasi;

use App\Mail\PemberitahuanKos;
use App\Models\User;
use App\Notifications\NotifikasiKos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Pengirim notifikasi dua kanal (T25).
 *
 * Pemakaiannya sengaja dua langkah, dan urutannya yang penting:
 *
 * ```php
 * $surat = $pemberitahu->dalamTransaksi(Peristiwa::TARIF_DIUBAH, $penerima, $judul, $pesan);
 * // ... DB::transaction(...) selesai di sini ...
 * $pemberitahu->setelahTransaksi($surat);
 * ```
 *
 * Kenapa tidak satu panggilan yang mengurus keduanya sendiri. Kalau kelas ini
 * yang memutuskan kapan email dikirim, ia harus tahu apakah sedang berada di
 * dalam transaksi - dan jawaban atas pertanyaan itu berubah-ubah sepanjang
 * permintaan. Dua langkah membuat urutannya terbaca di tempat pemanggil, dan
 * kesalahan urutan menjadi kesalahan yang terlihat saat membaca kode, bukan
 * kesalahan yang hanya muncul saat SMTP lambat.
 *
 * Kenapa tidak `DB::afterCommit()`. Itu memang mekanisme yang tepat di
 * produksi, tetapi di tes seluruh permintaan berjalan di dalam satu transaksi
 * `RefreshDatabase` yang tidak pernah di-commit, sehingga callback-nya tidak
 * pernah dijalankan. Jaminan "email setelah commit" lalu hanya berlaku di
 * produksi dan tidak bisa dibuktikan tes - jaminan yang tidak diuji adalah
 * jaminan yang akan rusak tanpa ketahuan. Dua langkah bisa diuji: satu tes
 * memastikan email tidak terkirim saat transaksi gagal, satu tes lagi
 * memastikan perubahan tetap tersimpan saat email gagal.
 *
 * Kegagalan email **tidak pernah** dilempar ulang. Email bisa gagal karena
 * SMTP mati, kuota penuh, atau alamat salah - dan tidak satu pun dari itu
 * membuat perubahan tarif menjadi tidak sah. Kegagalannya dicatat ke log
 * supaya bisa diperiksa, dan pengguna melihat perubahannya tersimpan.
 */
class Pemberitahu
{
    public function __construct(private readonly PengaturanKanal $pengaturan) {}

    /**
     * Tulis notifikasi in-app. Dipanggil DI DALAM transaksi perubahan.
     *
     * Barisnya ikut batal bila transaksinya batal - dan itu memang benar:
     * peristiwa yang tidak pernah terjadi tidak boleh meninggalkan pemberitahuan
     * bahwa ia terjadi.
     *
     * @param  Collection<int, User>  $penerima
     */
    public function dalamTransaksi(
        string $peristiwa,
        Collection $penerima,
        string $judul,
        string $pesan,
        ?string $tautan = null,
    ): Surat {
        $surat = new Surat($peristiwa, $judul, $pesan, $tautan);

        foreach ($penerima as $user) {
            if (! $this->pengaturan->aktif($user, $peristiwa, Peristiwa::KANAL_IN_APP)) {
                continue;
            }

            $user->notify(new NotifikasiKos($peristiwa, $judul, $pesan, $tautan));

            $surat->tambahPenerimaEmail($user);
        }

        return $surat;
    }

    /**
     * Kirim email ke penerima yang menghendakinya. Dipanggil SETELAH transaksi.
     *
     * Penerima email disaring lagi di sini, bukan diwarisi dari langkah pertama:
     * kanal in-app dan kanal email adalah dua keputusan terpisah, dan pengguna
     * boleh mematikan salah satunya saja.
     */
    public function setelahTransaksi(Surat $surat): void
    {
        foreach ($surat->penerimaEmail as $user) {
            if (! $this->pengaturan->aktif($user, $surat->peristiwa, Peristiwa::KANAL_EMAIL)) {
                continue;
            }

            try {
                Mail::to($user->email)->send(new PemberitahuanKos(
                    peristiwa: $surat->peristiwa,
                    judul: $surat->judul,
                    pesan: $surat->pesan,
                    tautan: $surat->tautan,
                ));
            } catch (Throwable $e) {
                // Tidak dilempar ulang. Lihat catatan kelas.
                Log::warning('Email notifikasi gagal dikirim.', [
                    'peristiwa' => $surat->peristiwa,
                    'penerima' => $user->email,
                    'sebab' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Kirim untuk peristiwa yang penulisannya sudah selesai lebih dulu.
     *
     * Dipakai oleh peristiwa yang lahir di **batas HTTP**, bukan di dalam
     * transaksi domain: tagihan yang baru terbit, pembayaran yang baru dicatat,
     * pemeriksaan pembayaran. Di jalur itu tidak ada transaksi yang masih
     * terbuka saat controller memegang kendali - `PenerbitTagihan` dan
     * `PencatatPembayaran` sudah menutup transaksinya sendiri - sehingga
     * memisahkan dua langkah hanya akan menjadi dua panggilan yang selalu
     * berdampingan, dan yang kedua mudah terlupa.
     *
     * Nama metode ini sengaja menyebut "sekarang", bukan "dalam transaksi",
     * supaya pemanggil berikutnya tidak memakainya untuk peristiwa yang
     * tulisannya belum commit. Untuk yang itu, pakai dua langkah di atas.
     *
     * @param  Collection<int, User>  $penerima
     */
    public function kirimSekarang(
        string $peristiwa,
        Collection $penerima,
        string $judul,
        string $pesan,
        ?string $tautan = null,
    ): void {
        // Penerima kosong bukan keadaan luar biasa: penghuni yang datanya
        // dicatat pemilik tetapi belum punya akun tidak punya kotak masuk, dan
        // pemilik yang menjadi pelakunya tidak perlu diberi tahu perbuatannya
        // sendiri. Berhenti di sini menghindari satu query preferensi yang
        // jawabannya tidak dipakai.
        if ($penerima->isEmpty()) {
            return;
        }

        $this->setelahTransaksi(
            $this->dalamTransaksi($peristiwa, $penerima, $judul, $pesan, $tautan)
        );
    }

    /**
     * Tarif berubah. Dipanggil DI DALAM transaksi penulisan tarif.
     *
     * Pelakunya dikecualikan: pemilik yang baru saja menaikkan tarif tidak perlu
     * membaca "tarif diubah" satu detik kemudian. Yang perlu tahu adalah
     * pengelola lain - merekalah yang akan menghadapi penghuni yang bertanya.
     */
    public function tarifDiubah(User $pelaku, string $ringkasan): Surat
    {
        return $this->dalamTransaksi(
            peristiwa: Peristiwa::TARIF_DIUBAH,
            penerima: Penerima::pengurus($pelaku),
            judul: 'Tarif sewa diubah',
            pesan: sprintf('%s mengubah tarif: %s', $pelaku->name, $ringkasan),
            tautan: '/pemilik/kamar',
        );
    }

    /**
     * Kebijakan kos berubah. Dipanggil DI DALAM transaksi penulisan kebijakan.
     */
    public function kebijakanDiubah(User $pelaku, string $ringkasan): Surat
    {
        return $this->dalamTransaksi(
            peristiwa: Peristiwa::KEBIJAKAN_DIUBAH,
            penerima: Penerima::pengurus($pelaku),
            judul: 'Kebijakan kos diubah',
            pesan: sprintf('%s mengubah kebijakan: %s', $pelaku->name, $ringkasan),
            tautan: '/pemilik/kebijakan',
        );
    }

    /**
     * Satu tagihan terbit untuk satu penghuni.
     *
     * Dikirim sebagai DUA pemberitahuan, bukan satu dengan dua jenis penerima.
     * Alasannya tautan: penghuni membuka `/penghuni`, pengurus membuka
     * `/pemilik/tagihan`, dan satu `Surat` hanya membawa satu tautan. Satu
     * pemberitahuan bersama berarti separuh penerimanya mengeklik tautan yang
     * dijaga peran lain - dan mendarat di halaman yang bukan miliknya. Notifikasi
     * yang membawa pembacanya ke tempat yang salah lebih buruk daripada
     * notifikasi tanpa tautan.
     *
     * Pengurus lain juga diberi tahu kecuali pelakunya: kalau pemilik yang
     * menerbitkan, pengelolalah yang akan ditanya penghuni.
     */
    public function tagihanTerbit(User $pelaku, int $penghuniId, string $ringkasan, string $jatuhTempo): void
    {
        $pesan = sprintf('%s. Jatuh tempo %s.', $ringkasan, $jatuhTempo);

        $this->kirimSekarang(
            peristiwa: Peristiwa::TAGIHAN_TERBIT,
            penerima: Penerima::pengurus($pelaku),
            judul: 'Tagihan baru terbit',
            pesan: $pesan,
            tautan: '/pemilik/tagihan',
        );

        $this->kirimSekarang(
            peristiwa: Peristiwa::TAGIHAN_TERBIT,
            penerima: Penerima::akunPenghuni($penghuniId),
            judul: 'Tagihan baru terbit',
            pesan: $pesan,
            // Portal penghuni adalah satu halaman (`/penghuni`), bukan
            // `/penghuni/tagihan`. Tautan yang menunjuk alamat tidak ada akan
            // mendarat di halaman 404.
            tautan: '/penghuni',
        );
    }

    /**
     * Tagihan sebulan diterbitkan sekaligus.
     *
     * Satu pemberitahuan untuk seluruh batch, bukan satu per tagihan. Batch 30
     * kamar yang menghasilkan 30 baris akan menenggelamkan kotak masuk pemilik,
     * dan yang ingin ia tahu hanya "bulan ini sudah tertagih semua".
     */
    public function tagihanMassal(User $pelaku, string $periode, int $terbit, int $gagal): void
    {
        $this->kirimSekarang(
            peristiwa: Peristiwa::TAGIHAN_MASSAL,
            penerima: Penerima::pengurus($pelaku),
            judul: 'Tagihan sebulan diterbitkan',
            pesan: $gagal === 0
                ? sprintf('%d tagihan periode %s terbit. Tidak ada yang gagal.', $terbit, $periode)
                : sprintf('%d tagihan periode %s terbit, %d gagal dan perlu diperiksa.', $terbit, $periode, $gagal),
            tautan: '/pemilik/tagihan',
        );
    }

    /**
     * Uang masuk dicatat. In-app saja - lihat pembagian frekuensi di Peristiwa.
     *
     * Dua pemberitahuan, dengan alasan yang sama seperti `tagihanTerbit`:
     * tautannya berbeda untuk pengurus dan penghuni. Penghuni yang menerima
     * tautan `/pemilik/rekap` akan ditolak penjaga rute dan mendarat di
     * berandanya sendiri tanpa penjelasan - ia mengira catatannya hilang.
     *
     * Penghuni tetap diberi tahu karena ini konfirmasi bahwa setorannya
     * tercatat. Itu satu-satunya cara ia tahu tanpa menanyakan bukti transfernya.
     */
    public function pembayaranDicatat(User $pelaku, int $penghuniId, string $ringkasan): void
    {
        $pesan = sprintf('%s mencatat %s.', $pelaku->name, $ringkasan);

        $this->kirimSekarang(
            peristiwa: Peristiwa::PEMBAYARAN_DICATAT,
            penerima: Penerima::pengurus($pelaku),
            judul: 'Pembayaran dicatat',
            pesan: $pesan,
            tautan: '/pemilik/rekap',
        );

        $this->kirimSekarang(
            peristiwa: Peristiwa::PEMBAYARAN_DICATAT,
            penerima: Penerima::akunPenghuni($penghuniId),
            judul: 'Pembayaran Anda tercatat',
            pesan: $pesan,
            tautan: '/penghuni',
        );
    }

    /**
     * Satu pembayaran diperiksa. In-app saja.
     *
     * Yang paling perlu tahu adalah pencatatnya: pemeriksaan ada justru untuk
     * memastikan catatannya benar. Pemeriksa sendiri tidak perlu diberi tahu
     * perbuatannya.
     */
    public function pembayaranDiverifikasi(User $pelaku, ?User $pencatat, string $ringkasan, bool $diterima): void
    {
        $penerima = Penerima::pengurus($pelaku);

        if ($pencatat !== null && $pencatat->getKey() !== $pelaku->getKey()) {
            $penerima = $penerima->push($pencatat)->unique('id')->values();
        }

        $this->kirimSekarang(
            peristiwa: Peristiwa::PEMBAYARAN_DIVERIFIKASI,
            penerima: $penerima,
            judul: $diterima ? 'Pembayaran diterima' : 'Pembayaran ditolak saat pemeriksaan',
            pesan: sprintf('%s memeriksa %s: %s.', $pelaku->name, $ringkasan, $diterima ? 'diterima' : 'ditolak'),
            tautan: '/pemilik/rekap',
        );
    }
}
