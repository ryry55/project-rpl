<?php

namespace App\Domain\Audit;

use App\Models\LogAudit;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Pencatat jejak audit (R-07).
 *
 * Dipanggil **di dalam transaksi yang sama** dengan perubahan yang dicatat.
 * Kalau pencatatan dilakukan setelah commit, ada jendela waktu di mana data
 * sudah berubah tetapi jejaknya belum ada — dan kegagalan tepat di jendela itu
 * menghasilkan perubahan tanpa jejak, yaitu persis keadaan yang ingin dicegah
 * log audit.
 *
 * Aktor diambil dari sesi saat pemanggilan, bukan dioper sebagai argumen. Itu
 * disengaja: kalau tiap pemanggil harus mengoper aktor, satu pemanggil yang
 * lupa akan menghasilkan baris beraktor kosong, dan jejaknya menjadi tidak
 * berguna. Pemanggil yang tidak punya sesi (perintah artisan, seeder) tetap
 * menghasilkan baris dengan `aktor_id` null — jejak tanpa aktor masih lebih
 * baik daripada tidak ada jejak.
 */
class PencatatAudit
{
    public const AKSI_TAGIHAN_TERBIT = 'tagihan.terbit';

    public const AKSI_TAGIHAN_BAYAR = 'tagihan.bayar';

    /**
     * Satu tagihan dibatalkan (REV-FIN-02).
     *
     * Entitasnya `tagihan` dengan `entitas_id` baris yang dibatalkan. Alasannya
     * ikut di `nilai_baru` supaya pertanyaan "kenapa tagihan ini batal" bisa
     * dijawab dari log saja, tanpa membuka tabel.
     */
    public const AKSI_TAGIHAN_BATAL = 'tagihan.batal';

    public const AKSI_TARIF_TAMBAH = 'tarif.tambah';

    public const AKSI_KEBIJAKAN_TAMBAH = 'kebijakan.tambah';

    /**
     * Usulan perubahan oleh pengelola (T26).
     *
     * Entitasnya `usulan_perubahan`, bukan `tarif_sewa`: saat usulan diajukan,
     * belum ada baris tarif yang ditulis. Menyebutnya `tarif_sewa` dengan
     * `entitas_id` kosong akan menghasilkan jejak yang tampak menunjuk baris
     * tarif yang tidak pernah ada.
     */
    public const AKSI_TARIF_USUL = 'tarif.usul';

    public const AKSI_KEBIJAKAN_USUL = 'kebijakan.usul';

    /**
     * Persetujuan usulan (T26).
     *
     * Entitasnya `tarif_sewa`/`kebijakan_kos` dengan id baris yang baru
     * terbentuk: yang dicatat adalah penetapannya, dan pengusulnya sudah
     * tercatat di baris `*.usul`. Dua baris, dua aktor, dua kalimat yang
     * berbeda - kalau digabung menjadi satu, salah satu aktor hilang dari
     * riwayat.
     */
    public const AKSI_TARIF_SETUJUI = 'tarif.setujui';

    public const AKSI_KEBIJAKAN_SETUJUI = 'kebijakan.setujui';

    /**
     * Penolakan usulan (T26).
     *
     * Entitasnya `usulan_perubahan`, karena tidak ada baris tarif yang lahir.
     * Tanpa baris ini, pertanyaan "kenapa tarif ini tidak jadi naik" hanya bisa
     * dijawab dari kolom `alasan` pada tabel antrean - yang tidak dibaca siapa
     * pun yang memeriksa log audit.
     */
    public const AKSI_TARIF_TOLAK = 'tarif.tolak';

    public const AKSI_KEBIJAKAN_TOLAK = 'kebijakan.tolak';

    /**
     * Komponen biaya kamar (REV-SEC-07).
     *
     * Empat aksi, bukan tiga: selain usul/setujui/tolak, ada `komponen.tambah`
     * untuk jalur pemilik yang menetapkan langsung. Tanpa konstanta tersendiri,
     * jalur itu akan memakai `kebijakan.tambah` - dan log yang menyebut aksi
     * yang salah lebih buruk daripada log yang tidak ada, karena ia tampak
     * benar.
     */
    public const AKSI_KOMPONEN_TAMBAH = 'komponen.tambah';

    public const AKSI_KOMPONEN_USUL = 'komponen.usul';

    public const AKSI_KOMPONEN_SETUJUI = 'komponen.setujui';

    public const AKSI_KOMPONEN_TOLAK = 'komponen.tolak';

    /**
     * Denda sebuah tagihan dimaafkan, atau pemaafannya ditarik kembali
     * (REV-FIN-07).
     *
     * Dua aksi terpisah, bukan satu aksi dengan nilai nol. Membaca "denda
     * dimaafkan Rp 0" di jejak audit tidak bisa dibedakan dari kesalahan tulis;
     * "pemaafan denda dibatalkan" bisa.
     */
    public const AKSI_DENDA_MAAFKAN = 'denda.maafkan';

    public const AKSI_DENDA_MAAF_DIBATALKAN = 'denda.maaf-batal';

    /**
     * Satu pemeriksaan atas satu pembayaran (T20).
     *
     * Entitasnya `pembayaran`, bukan `tagihan`: yang diperiksa adalah baris uang
     * tertentu, dan dua pembayaran pada tagihan yang sama bisa punya hasil
     * pemeriksaan berbeda. Menyebutnya `tagihan.verifikasi` akan membuat jejak
     * tidak bisa menunjuk baris mana yang ditolak.
     */
    public const AKSI_PEMBAYARAN_VERIFIKASI = 'pembayaran.verifikasi';

    /**
     * Satu pembayaran ditarik kembali (REV-FIN-01).
     *
     * Entitasnya `pembayaran` dengan id baris pembalik yang baru terbentuk.
     * Yang dicatat adalah penarikan uangnya, dan id asalnya ikut di `nilai_baru`
     * supaya riwayat bisa dirunut tanpa membuka tabel.
     */
    public const AKSI_PEMBAYARAN_BALIK = 'pembayaran.balik';

    /**
     * Rekening atau QRIS tujuan pembayaran ditambah, diubah, atau dihapus
     * (REV-UI-05).
     *
     * Entitasnya `rekening`. Nomor rekening yang berubah adalah hal yang paling
     * sering ditanyakan penghuni lama - "dulu saya transfer ke mana" - dan
     * jawabannya hanya bisa diberikan kalau perubahan lama tercatat. Nilai lama
     * ikut dikirim lengkap, bukan hanya id, karena barisnya bisa saja sudah
     * dihapus saat pertanyaan itu datang.
     */
    public const AKSI_REKENING_TAMBAH = 'rekening.tambah';

    public const AKSI_REKENING_UBAH = 'rekening.ubah';

    public const AKSI_REKENING_HAPUS = 'rekening.hapus';

    /**
     * Data induk: kamar, penghuni, kontrak, fasilitas, aturan kos
     * (REV-DAT-05).
     *
     * Sebelum ini hanya uang dan tarif yang berjejak. Akibatnya pertanyaan
     * "siapa yang mengubah nomor kamar ini" dan "kapan penghuni ini pindah
     * kamar" tidak bisa dijawab sama sekali - padahal keduanya mengubah tagihan
     * bulan berikutnya.
     *
     * **Kenapa `tambah`/`ubah`/`hapus` seragam, bukan satu aksi per entitas.**
     * Entitasnya sudah dibedakan kolom `entitas`, jadi `kamar.ubah` dan
     * `penghuni.ubah` akan menyimpan hal yang sama dua kali. Yang perlu dibaca
     * manusia adalah "apa yang berubah", dan itu ada di `nilai_lama`/
     * `nilai_baru`.
     *
     * **Yang TIDAK dicatat sebagai `ubah`: perpindahan status huni.** Status
     * `kamar.status_huni` berubah otomatis saat kontrak dibuat/diakhiri, dan
     * mencatatnya akan menghasilkan dua baris untuk satu kejadian. Jejaknya ada
     * di `kontrak.tambah`/`kontrak.akhir`.
     */
    public const AKSI_KAMAR_TAMBAH = 'kamar.tambah';

    public const AKSI_KAMAR_UBAH = 'kamar.ubah';

    // Laporan kerusakan (T14). Jejaknya menempel pada `kamar` karena yang
    // berubah dan yang dibaca layar adalah status kamarnya; id laporannya
    // ikut disertakan di `nilai_baru`.
    public const AKSI_KERUSAKAN_LAPOR = 'kerusakan.lapor';

    public const AKSI_KERUSAKAN_SELESAI = 'kerusakan.selesai';

    public const AKSI_PENGHUNI_TAMBAH = 'penghuni.tambah';

    public const AKSI_PENGHUNI_UBAH = 'penghuni.ubah';

    public const AKSI_KONTRAK_TAMBAH = 'kontrak.tambah';

    public const AKSI_KONTRAK_AKHIR = 'kontrak.akhir';

    public const AKSI_FASILITAS_TAMBAH = 'fasilitas.tambah';

    public const AKSI_FASILITAS_UBAH = 'fasilitas.ubah';

    public const AKSI_FASILITAS_HAPUS = 'fasilitas.hapus';

    public const AKSI_ATURAN_TAMBAH = 'aturan.tambah';

    public const AKSI_ATURAN_UBAH = 'aturan.ubah';

    public const AKSI_ATURAN_HAPUS = 'aturan.hapus';

    /**
     * Email notifikasi gagal dikirim (B3).
     *
     * Entitasnya `email` dengan `entitas_id` kosong: yang gagal adalah
     * pengiriman, bukan baris data tertentu. Tanpa baris ini, email yang tidak
     * sampai hanya meninggalkan jejak di berkas log container - yang hilang
     * saat Render me-restart - sehingga pertanyaan "berapa email hilang bulan
     * ini, dan ke siapa" tidak bisa dijawab dari aplikasi sama sekali.
     *
     * `nilai_baru` memuat penerima, peristiwa, dan sebabnya; alamat email
     * bukan rahasia di sini, dan tanpa alamatnya baris ini tidak bisa
     * ditindaklanjuti.
     */
    public const AKSI_EMAIL_GAGAL = 'email.gagal';

    public function catat(
        string $aksi,
        string $entitas,
        ?int $entitasId = null,
        ?array $nilaiLama = null,
        ?array $nilaiBaru = null,
    ): LogAudit {
        $aktor = $this->aktorSekarang();

        return LogAudit::create([
            'aktor_id' => $aktor?->getAuthIdentifier(),
            // Salinan beku: mengganti nama pengguna tidak boleh menulis ulang
            // sejarah log yang sudah ada.
            'aktor_nama' => $aktor?->getAttribute('name'),
            'aksi' => $aksi,
            'entitas' => $entitas,
            'entitas_id' => $entitasId,
            'nilai_lama' => $nilaiLama,
            'nilai_baru' => $nilaiBaru,
        ]);
    }

    private function aktorSekarang(): ?Authenticatable
    {
        // `auth()->user()` mengembalikan null di luar permintaan HTTP. Itu bukan
        // kesalahan: seeder dan perintah artisan tetap boleh menulis jejak.
        return auth()->user();
    }
}
