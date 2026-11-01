# Status 36 temuan audit — mana yang sudah dikerjakan

> **Bukan pengganti `docs/revisi.md`.** Berkas itu adalah **masukan** audit dari
> pemeriksa luar dan sengaja **tidak** di-commit (`.gitignore:56`), jadi ia tidak
> punya kolom status dan tidak boleh disunting di sini. Dokumen ini menjawab satu
> pertanyaan yang pasti muncul saat sidang: *"dari daftar revisi itu, mana yang
> sudah?"*
>
> **Cara dibaca:** status di bawah hasil **pembacaan statis** kode dan tes pada
> 1 November 2026, ditambah gate yang hijau hari itu (830 tes PHP / 3883 asersi,
> 19 tes Vitest, 30 skrip / 879 langkah, 526-568 detik). Ia membuktikan perbaikan
> **ada di kode**; ia bukan bukti runtime baru. Kolom Bukti menyebut tempat
> memeriksanya sendiri.

## Ringkasan

| Status | Jumlah | Arti |
|---|---|---|
| **Selesai** | 34 | Perbaikan ada di kode dan ada tes/skrip yang menjaganya |
| **Selesai tanpa tes** | 0 | Perbaikan ada, tidak ada penjaga otomatis |
| **Tidak berlaku** | 2 | Temuan menyebut hal yang memang tidak ada di desain |
| **Masih terbuka** | 0 | Perbaikan belum ada di kode |

## Tabel

| ID | Tingkat | Temuan | Status | Bukti |
|---|---|---|---|---|
| REV-DFD-01 | Kritis | Lima aliran hilang di Level 1 (P3/P4) | Selesai | `docs/dfd/dfd-data.js:254,388,427-453` |
| REV-DFD-02 | Kritis | P1 hanya menulis D1/D2, tak membaca | Selesai | `docs/dfd/dfd-data.js:409,415` |
| REV-DFD-03 | Kritis | D6 hanya ditulis, tak pernah dibaca | Selesai | `docs/dfd/dfd-data.js:370,382,497` |
| REV-DFD-04 | Kritis | Level 0 dua keluaran, Level 1 satu | Selesai | `docs/dfd/dfd-data.js:328,334` |
| REV-DFD-05 | Sedang | Denda/umur digantung bukti pembayaran | Selesai | `docs/dfd/03-dfd-level-2.md:196-201,266-430`; `DendaHttpTest::test_denda_dan_umur_dihitung_tanpa_satu_pun_pembayaran` |
| REV-DFD-06 | Sedang | Label #7 sebut denda padahal Rp 0 | Selesai | `docs/dfd/dfd-data.js:309` |
| REV-DFD-07 | Sedang | Pengelola tak ada di diagram konteks | Selesai | `docs/dfd/dfd-data.js:48-55` |
| REV-DFD-08 | Sedang | Penghuni digambar memasok data | Selesai | `docs/dfd/dfd-data.js:57-67,236` |
| REV-DFD-09 | Minor | Kamus usang; umur negatif masuk 0–30 | Selesai | `docs/dfd/dfd-data.js:179,599,602` |
| REV-SEC-01 | Kritis | Email akun aktif bisa diganti | Selesai | `PenghuniController.php:112`; `AktivasiAkunTest::test_mengganti_email_penghuni_aktif_ditolak` |
| REV-SEC-02 | Kritis | Pengelola menetapkan tarif lewat kontrak | Selesai | `KontrakController.php:78` (`tarif_perlu_pemilik`); `PengelolaTest::test_pengelola_tidak_bisa_menetapkan_tarif_pertama_lewat_formulir_kontrak`, dikunci kontrol negatif `test_pemilik_boleh_...` |
| REV-SEC-03 | Kritis | Tautan email ke port 8081 (404) | Selesai | `PemberitahuanKos.php:54`; `AktivasiAkunTest::test_tautan_email_memakai_alamat_spa_bukan_alamat_api` |
| REV-SEC-04 | Kritis | Akun menunggak bisa dihapus lewat PDP | Selesai | `LayananPdp.php:218-228`; `PdpTest::test_penghuni_dengan_tagihan_belum_lunas_tidak_bisa_menghapus` |
| REV-SEC-05 | Sedang | `periksa-token` bocorkan PII | Selesai | `AuthController.php:216-249`; `test_periksa_token_dibatasi_per_ip`, `..._tanpa_membocorkan_email_lengkap` |
| REV-SEC-06 | Sedang | Pengelola baca rekap omset pemilik | Selesai | `routes/api.php:233` (`peran:pemilik`); `RekapPendapatanTest::test_pengelola_tidak_boleh_membaca_rekap` |
| REV-SEC-07 | Sedang | Komponen biaya tanpa persetujuan | Selesai | `KomponenBiayaController.php:90-107`; `KomponenUsulanTest` |
| REV-FIN-01 | Kritis | Tolak verifikasi tak menarik uang | Selesai | `PembalikPembayaran.php`; `PembayaranController.php:193-224`; `PembalikPembayaranTest`; `e2e-t20.mjs` |
| REV-FIN-02 | Kritis | Batal tagihan vs unique (kontrak,periode) | Selesai | migrasi `2026_11_06_090000`; `PembatalanTagihanTest::test_periode_yang_sudah_dibatalkan_bisa_diterbitkan_ulang` |
| REV-FIN-03 | Sedang | Pengelola periksa catatannya sendiri | Selesai | `PembayaranController.php:243`; `VerifikasiPembayaranTest::test_pengelola_tidak_boleh_memeriksa_catatannya_sendiri` |
| REV-FIN-04 | Sedang | `today` memblokir booking masa depan | Selesai | `SimpanKontrakRequest.php:32`; `DataIndukTest::test_tgl_masuk_di_masa_depan_diterima_sebagai_dipesan` |
| REV-FIN-05 | Sedang | Akhir kontrak tanpa rekonsiliasi deposit | Tidak berlaku | Deposit dihapus seluruhnya; `MigrasiHapusDepositTest` |
| REV-FIN-06 | Sedang | Kontrak di kamar berstatus perbaikan | Selesai | T14 (31 Okt 2026): `Kamar::statusSetelahPerbaikan()`, `KontrakController.php:44-46` menolak 422; `KerusakanTest::test_kamar_yang_sedang_diperbaiki_tidak_bisa_disewakan`, `..._mengakhiri_kontrak_tidak_menghapus_status_perbaikan` |
| REV-FIN-07 | Sedang | Denda dari pokok penuh, tanpa pemaafan | Selesai | `PenghitungDenda.php:101-130`; `PemaafanDendaTest` |
| REV-DAT-01 | Kritis | Cascade vs trigger append-only | Selesai | migrasi `2026_11_06_090300`; `SkemaRiwayatKamarTest::test_tiga_foreign_key_riwayat_memakai_restrict` |
| REV-DAT-02 | Kritis | Terbit massal tak kirim notifikasi | Selesai | `PenerbitanMassalTest::test_setiap_penghuni_menerima_pemberitahuan_tagihannya_sendiri` |
| REV-DAT-03 | Sedang | Email sinkron, tanpa pekerja antrean | Selesai | `docker/prod/supervisord.conf:90`; `Jobs/KirimPemberitahuan.php`; `NotifikasiTest::test_email_notifikasi_masuk_antrean` |
| REV-DAT-04 | Sedang | Tak ada indeks (kontrak_id,status) | Selesai | migrasi `2026_11_06_090400`; `SkemaRiwayatKamarTest:175` |
| REV-DAT-05 | Sedang | Data induk tak masuk log audit | Selesai | `KamarController.php:55`, `PenghuniController.php:54`, `AturanController.php:59`; `JejakDataIndukTest` |
| REV-UI-01 | Kritis | 401 tak ditangani di `panggil()` | Selesai | `web/src/sesi.js` (`sesiHabis()`: token dibersihkan + dimuat ulang ke `/masuk?pesan=berakhir&lanjut=…`); `e2e-t1.mjs` langkah *"permintaan dengan token mati mengeluarkan sesi ke /masuk"* |
| REV-UI-02 | Kritis | Form komponen biaya tak dibuat | Selesai | `KamarView.vue:505-516`; `e2e-t2.mjs:395` |
| REV-UI-03 | Kritis | Tak ada WhatsApp / salin rincian | Selesai | `bagikan.js:160`; `PanelDetailTagihan.vue:579-595`; `e2e-r3.mjs:549` |
| REV-UI-04 | Sedang | "Belum ada tagihan" saat API gagal | Selesai | `DashboardPenghuni.vue:496-504`; `uji-galat-muat.mjs` (30 langkah) |
| REV-UI-05 | Sedang | Tak ada rekening/QRIS di portal | Selesai | `DashboardPenghuni.vue:518-554`; `RekeningTest`; `e2e-r3.mjs:474-480` |
| REV-ACA-01 | Kritis | Tak ada use case UML 3 aktor / 14 UC | Selesai | `docs/akademik/usecase-data.js`; `01-use-case.svg` + `.png` |
| REV-ACA-02 | Kritis | Tak ada PDF laporan Pertemuan 4 | Selesai | `akademik/Proses_Bisnis_Kos_Kosan.pdf`, `Kos_Kosan_Diagrams_Pertemuan_4.pdf` |
| REV-ACA-03 | Minor | Berkas kunci Word `~$` tertinggal | Tidak berlaku | `.gitignore` memuat `~$*`; `git ls-files` kosong |

## Yang perlu dibaca sebelum sidang

1. **REV-UI-01 ditutup 30 Oktober, sesudah keputusan pemilik.** `panggil()` dan
   `unduhBerkas()` kini memanggil `sesiHabis()` pada 401 selama masih ada token:
   sesi dibersihkan lalu peramban dimuat ulang ke `/masuk` dengan pesan
   "Sesi Anda berakhir" dan `lanjut` berisi halaman yang terputus. Muat ulang,
   bukan `router.push`, karena `sesi.js` tidak boleh mengimpor router dan seluruh
   keadaan di memori memang sudah tidak sah. Dijaga `e2e-t1.mjs`, yang mencabut
   token di server **selagi sesi terbuka** lalu memindahkan halaman lewat menu.
   Diverifikasi bisa gagal: penanganan 401 dimatikan (`if (false && ...)`) →
   4 asersi merah, pengguna tertinggal di `/pemilik/tunggakan`.
2. **REV-FIN-01 tertutup lewat aksi terpisah, bukan otomatis.** Verifikasi
   `tolak` sengaja tidak mengubah angka (`PembayaranController.php:226-232`);
   uang baru berkurang lewat tombol **Tarik kembali**. Penolakan sendirian
   masih menyisakan angka di rekap sampai tombol itu ditekan — itu pilihan
   desain yang tercatat, bukan kelalaian.
3. **REV-SEC-06 selesai untuk `/rekap`, bukan `/tunggakan`.** `/tunggakan`
   sengaja tetap terbuka bagi pengelola, alasannya tertulis di
   `api/routes/api.php` sekitar baris 337-349.
4. **Tidak ada lagi temuan yang "selesai tanpa tes".** Dua yang sebelumnya
   begitu sudah ditutup 29 Oktober. REV-SEC-02 sekarang dijaga dua tes
   berpasangan di `PengelolaTest`: satu menuntut pengelola ditolak 422 dengan
   `kode: tarif_perlu_pemilik`, satu menuntut pemilik tetap 201. Pasangan itu
   penting - tanpa yang kedua, "pengelola ditolak" bisa lulus karena semua
   orang ditolak, atau karena sebab lain. Diverifikasi dengan mematikan
   penjaganya (`if (false && ...)`): tes pengelola merah, tes pemilik tetap
   hijau. REV-DFD-05 dijaga
   `DendaHttpTest::test_denda_dan_umur_dihitung_tanpa_satu_pun_pembayaran`,
   yang membuktikan denda dan umur tunggakan terhitung pada tagihan yang
   **nol baris pembayaran**. Diverifikasi dengan menyuntikkan kembali cacat
   DFD lamanya (`->whereHas('pembayaran')` di `DaftarTunggakan`): tes merah.
5. **Status DFD kini dijaga gate, bukan hanya mata.** `periksaSemantik()`
   sudah lama ada dan `build.js` sudah menolak menulis gambar cacat, tetapi
   tidak ada pemanggil di gerbang. Sejak 29 Oktober `docs/uji/cek-dfd.js`
   dijalankan `periksa.ps1`, `periksa.sh`, dan `uji.yml`; ia memeriksa
   semantik **dan** membandingkan keempat SVG yang di-commit dengan hasil
   penggambaran data sekarang. Diverifikasi bisa gagal: rute dihapus →
   `tidak punya rute di DFD level 1`; pembacaan D1 dibuang → `black hole`;
   satu byte ditambahkan ke SVG → `BEDA dari hasil penggambaran data sekarang`.
