# Audit Ketertelusuran

Peta aturan bisnis → implementasi → tes → status. **Dirawat berjalan**, diperbarui setiap tiket
selesai, dan diaudit formal sekali di akhir. Lihat `SPEC.md` §10.

Status: ✅ selesai · ⚠ sebagian · ⏳ belum · ⏸ ditunda

## R-01..R-07

| Aturan | Implementasi | Tes | Status |
|---|---|---|---|
| **R-01** prorata bulan pertama | `App\Domain\Billing\Kalkulator::hitungSewaBulanPertama` + `hariTinggal` (murni, tanpa DB); dipakai `PenerbitTagihan` | `KalkulatorTest` (22 tes, tanpa DB): `test_prorata` 7 set data termasuk `masuk 15 Oktober (17/31)` → 822.581, `masuk 31 Oktober (1/31)` → 48.387, `masuk 28 Februari (1/28)` → 53.571, `masuk tanggal 1` → tarif penuh; `test_sebulan_penuh_selalu_persis_tarif_untuk_banyak_nilai` (7 tarif × 4 panjang bulan); `TagihanTest`: `test_masuk_tanggal_1_mendapat_tarif_penuh_bukan_kurang_serupiah`, `test_bulan_berikutnya_selalu_tarif_penuh_tanpa_prorata`, `test_tidak_ada_prorata_saat_penghuni_keluar_di_tengah_bulan` | ✅ |
| **R-02** satu tagihan per kontrak per periode | unique index `tagihan(kontrak_id, periode)` + tangkap SQLSTATE `23505` → 409, di jalur terbit tunggal maupun massal | `test_menekan_dua_kali_menghasilkan_409_bukan_tagihan_kedua`, `test_unique_index_menolak_duplikat_walau_aplikasi_dilewati`, `test_berapa_kali_pun_dicoba_jumlah_baris_tetap_satu`; balapan dua permintaan HTTP serentak: `docs/uji/uji-balapan-t4.mjs` (9/9) | ✅ |
| **R-03** denda 1%/hari, plafon 30%, mulai hari ke-8, plafon di hari ke-37 | `App\Domain\Billing\Kalkulator::hitungDenda` + `hariHinggaPlafon` (murni, tanpa DB); `PenghitungDenda` membaca snapshot parameter dari baris `tagihan`; `App\Support\WaktuSistem` sebagai satu-satunya sumber "hari ini" | `DendaTest` (21 tes, tanpa DB): `test_urutan_lengkap_dari_tiket` → `[0, 15.000, 345.000, 450.000, 450.000, 450.000]`, `test_plafon_tercapai_tepat_di_hari_terlambat_37`, `test_belum_jatuh_tempo_berarti_tanpa_denda`, `test_denda_tidak_pernah_melebihi_plafon_untuk_banyak_pokok` (6 pokok × 11 hari), `test_denda_tidak_pernah_menurun_walau_hari_bertambah` (61 hari), `test_hasil_sama_berapa_kali_pun_dipanggil` (100×); `DendaHttpTest` (20 tes): titik batas 7/8/30/37/365 lewat HTTP, `test_pembayaran_sebagian_tidak_menurunkan_dasar_denda`, `test_mengubah_kebijakan_denda_tidak_mengubah_tagihan_lama`, `test_header_simulasi_diabaikan_saat_mode_demo_mati`, `test_tanggal_simulasi_tidak_bocor_ke_permintaan_berikutnya` | ✅ |
| **R-04** umur tunggakan 0-30 / 31-60 / >60, plus kelompok belum jatuh tempo | `App\Domain\Billing\Kalkulator::kelompokUmur` (murni, menerima hari terlambat sebagai argumen); `UmurTunggakan` sebagai kunci/label/keterangan; `DaftarTunggakan` menyusun kelompok dengan memanggil `PenghitungDenda` yang sama dengan endpoint denda, dan menyaring `status NOT IN ('lunas','dibatalkan')` di SQL | `UmurTunggakanTest` (14 tes, tanpa DB): `test_kelompok_pada_titik_batas` dengan data set -365, -1, 0, 30, 31, 60, 61, 365, 10000; `test_hari_negatif_bukan_kelompok_nol_sampai_tiga_puluh`; `test_setiap_hari_dari_negatif_sampai_seratus_masuk_tepat_satu_kelompok`; `test_batas_kelompok_bersambung_tanpa_celah`. `TunggakanHttpTest` (14 tes): batas 30/31 dan 60/61 lewat HTTP, `test_tagihan_lunas_dan_dibatalkan_tidak_muncul_di_kelompok_mana_pun`, `test_status_sebagian_tetap_muncul_sebagai_tunggakan`, `test_angka_tunggakan_identik_dengan_endpoint_denda`, `test_konsistensi_bertahan_di_beberapa_tanggal_acuan` (6 tanggal) | ✅ |
| **R-05** deposit hanya untuk kerusakan | kolom `kontrak.deposit` sudah ada dan terisi | — | ⏸ ditunda (T8) |
| **R-06** tarif tidak berlaku surut — **lapis 1** (riwayat) | `tarif_sewa`, `komponen_biaya`, `kebijakan_kos` append-only + trigger `BEFORE UPDATE OR DELETE`; `App\Domain\Tarif\PencariTarif` memilih baris dengan `berlaku_dari` terbesar ≤ awal periode | `RiwayatTarifTest` (16 tes): `test_mengubah_tarif_menambah_baris_dan_baris_lama_tetap`, `test_tarif_efektif_adalah_baris_terakhir_…`, `test_update_langsung_ke_tarif_sewa_ditolak_database`, `test_delete_langsung_ke_tarif_sewa_ditolak_database`, `test_update_langsung_ke_kebijakan_kos_ditolak_database`, `test_update_langsung_ke_komponen_biaya_ditolak_database` | ✅ lapis 1 |
| **R-06** tarif tidak berlaku surut — **lapis 2** (snapshot di tagihan) | `tagihan.pokok` dibekukan + `tagihan.tarif_terpakai` (JSON: tarif, komponen, parameter denda) + kolom `tarif_denda_persen`/`plafon_persen`/`masa_tenggang` | `test_tagihan_menyimpan_snapshot_tarif_dan_komponen`, `test_menaikkan_tarif_tidak_mengubah_tagihan_yang_sudah_terbit`, `test_menaikkan_tarif_berlaku_untuk_tagihan_berikutnya`, `test_kebijakan_denda_dibekukan_di_tagihan` | ✅ lapis 2 |
| **R-07** isolasi penghuni + log audit | **Isolasi**: scope `Tagihan::terlihatOleh` — pemilik melihat semua, penghuni hanya lewat `whereHas('kontrak', penghuni_id)`, dan `show`/`denda` mengambil ulang barisnya lewat scope yang sama meski route model binding sudah menyediakannya. **Audit**: tabel `log_audit` + trigger `BEFORE UPDATE OR DELETE`, `PencatatAudit` menulis di dalam transaksi yang sama dengan perubahannya, diisi untuk penerbitan tagihan, pencatatan pembayaran, penambahan tarif, dan penambahan kebijakan denda. Endpoint baca `GET /audit-log` hanya punya `index` — tidak ada jalur tulis | `IsolasiDanAuditTest` (18 tes): `test_penghuni_hanya_melihat_tagihannya_sendiri`, `test_penghuni_mengakses_tagihan_orang_lain_mendapat_404_bukan_403`, `test_penghuni_mengakses_denda_tagihan_orang_lain_mendapat_404`, `test_penghuni_bisa_melihat_denda_tagihannya_sendiri`, `test_penghuni_memanggil_endpoint_pemilik_ditolak_403`, `test_penghuni_tidak_bisa_mengubah_apa_pun`, `test_penghuni_tanpa_data_penghuni_melihat_daftar_kosong`, `test_pemilik_melihat_semua_tagihan`, `test_pemilik_membaca_log_audit`, `test_log_audit_bisa_disaring_per_entitas`, `test_log_audit_urut_terbaru_dulu`, `test_batas_jumlah_baris_diumumkan`, `test_tidak_ada_endpoint_untuk_mengubah_atau_menghapus_log_audit`, `test_update_langsung_ke_log_audit_ditolak_basis_data`, `test_delete_langsung_ke_log_audit_ditolak_basis_data`; ditambah `test_setiap_pembayaran_tercatat_di_log_audit`, `test_perubahan_tarif_tercatat_di_log_audit`, `test_perubahan_kebijakan_denda_tercatat_di_log_audit`, `test_pembayaran_menolak_perubahan_dan_penghapusan`; `docs/uji/e2e-t9.mjs` (23/23) | ✅ |

## Aturan data induk (T2)

Kriteria penerimaan T2 yang bukan turunan langsung R-01..R-07.

| Kontrak | Implementasi | Tes | Status |
|---|---|---|---|
| Nomor kamar duplikat → 422 | `SimpanKamarRequest` `Rule::unique` | `test_nomor_kamar_duplikat_ditolak_422` | ✅ |
| Dua kontrak aktif pada satu kamar → 422 | partial unique index `kontrak_satu_aktif_per_kamar` + tangkap `23505` di `KontrakController` | `test_dua_kontrak_aktif_pada_satu_kamar_ditolak_422` | ✅ |
| Kontrak menyimpan `tgl_masuk`, tarif, deposit | `kontrak.tarif_awal` + baris `tarif_sewa` | `test_kontrak_menyimpan_tgl_masuk_tarif_dan_deposit` | ✅ |
| Tarif kontrak tidak boleh bertentangan dengan tarif kamar | `KontrakController@store` menolak 422 dengan pesan tarif yang berlaku | `test_kontrak_dengan_tarif_berbeda_dari_tarif_berlaku_ditolak_422` | ✅ |
| Penghuni baru otomatis dapat akun `users` peran `penghuni` | `PenghuniController@store` dalam satu transaksi | `test_penghuni_baru_otomatis_mendapat_akun_login`, `test_penghuni_bisa_login_dengan_akun_yang_dibuat_pemilik` | ✅ |
| Email penghuni duplikat → 422 | `Rule::unique('penghuni','email')` | `test_email_penghuni_duplikat_ditolak_422` | ✅ |
| Uang `numeric(14,0)`, tidak ada float | migrasi `decimal(…, 14, 0)` | `test_tarif_tersimpan_sebagai_numeric_bukan_float` | ✅ |
| Tarif harus bilangan bulat | `SimpanTarifRequest` `integer` | `test_tarif_harus_angka_bulat_bukan_desimal` | ✅ |
| Tidak ada endpoint ubah/hapus tarif | rute hanya `index` + `store` | `test_tidak_ada_endpoint_untuk_mengubah_atau_menghapus_tarif` | ✅ |
| Menghentikan komponen = baris baru bernilai 0 | `PencariTarif::komponenUntuk` + `KomponenBiayaResource.dihentikan` | `test_komponen_dihentikan_dengan_baris_bernilai_nol` | ✅ |
| Daftar kamar menampilkan tarif terakhir | relasi `Kamar::tarifTerakhir()` (`latestOfMany`) | `test_daftar_kamar_menampilkan_tarif_terakhir`, `test_kamar_tanpa_tarif_mengirim_null_bukan_galat` | ✅ |

## Aturan tagihan (T3)

| Kontrak | Implementasi | Tes | Status |
|---|---|---|---|
| Biaya tambahan tetap muncul sebagai baris rincian terpisah | `PenerbitTagihan` menambah satu baris per komponen | `test_komponen_biaya_muncul_sebagai_baris_tersendiri` | ✅ |
| `pokok` = jumlah seluruh rincian | dijumlah dari array baris yang sudah bulat | `test_pokok_sama_dengan_jumlah_seluruh_rincian` | ✅ |
| Total = pokok + denda (denda 0 saat terbit) | `TagihanResource` (denda diisi T6) | `test_total_sama_dengan_pokok_karena_denda_belum_ada` | ✅ |
| `jatuh_tempo` = tanggal masuk, dijepit ke akhir bulan | `Kalkulator::tanggalJatuhTempo` | `KalkulatorTest::test_jatuh_tempo_dijepit_ke_akhir_bulan` (7 set data), `test_jatuh_tempo_tidak_pernah_menggelinding_ke_bulan_berikutnya` (36 kombinasi), `TagihanTest::test_jatuh_tempo_dijepit_ke_akhir_bulan_pendek` | ✅ |
| `periode` tersimpan sebagai tanggal 1 | `PenerbitTagihan` menormalkan ke `startOfMonth()` | `test_periode_disimpan_sebagai_tanggal_1` | ✅ |
| Komponen bernilai 0 tidak menjadi baris rincian | dilewati di `PenerbitTagihan` | `test_komponen_bernilai_nol_tidak_muncul_sebagai_baris` | ✅ |
| Periode sebelum tanggal masuk ditolak | `pastikanPeriodeMasukAkal` | `test_periode_sebelum_tanggal_masuk_ditolak` | ✅ |
| Periode setelah kontrak selesai ditolak | sama | `test_periode_setelah_kontrak_selesai_ditolak` | ✅ |
| Kamar tanpa tarif ditolak dengan pesan konkret | `TagihanTidakDapatDiterbitkan::karenaTarifKosong` | `test_kontrak_tanpa_tarif_ditolak_dengan_pesan_jelas` | ✅ |
| Tanpa kebijakan denda ditolak dengan pesan konkret | `TagihanTidakDapatDiterbitkan::karenaParameterDendaKosong` | `test_tanpa_kebijakan_tagihan_ditolak_dan_menyebut_tipe_yang_kurang` | ✅ |

## Aturan denda (T6)

| Kontrak | Implementasi | Tes | Status |
|---|---|---|---|
| Denda 0 pada hari terlambat 0–7 | `Kalkulator::hitungDenda` mengembalikan 0 bila `hariTerlambat <= masaTenggang` | `test_denda_pada_titik_batas` (data set hari 0 dan 7) | ✅ |
| Denda 1% pokok pada hari ke-8 | `hariDenda = hariTerlambat - masaTenggang` | data set hari 8 → 15.000 | ✅ |
| Plafon tercapai tepat di hari ke-37 | `min(denda, plafon)`; 1% × 30 hari = 30% | `test_plafon_tercapai_tepat_di_hari_terlambat_37` | ✅ |
| Denda tidak pernah melebihi plafon | `min(...)` + hari denda dipotong di titik plafon | `test_denda_tidak_pernah_melebihi_plafon_untuk_banyak_pokok` | ✅ |
| Denda dihitung sekali jalan, bukan diakumulasi | satu perkalian dari `hariTerlambat` | `test_hasil_sama_berapa_kali_pun_dipanggil` | ✅ |
| Pembayaran sebagian tidak menurunkan dasar | dasar = `tagihan.pokok` yang dibekukan, bukan sisa | `test_pembayaran_sebagian_tidak_menurunkan_dasar_denda` | ✅ |
| Parameter diubah setelah terbit → tagihan lama tetap | `PenghitungDenda` membaca kolom snapshot di baris `tagihan` | `test_mengubah_kebijakan_denda_tidak_mengubah_tagihan_lama`, `test_parameter_berbeda_per_tagihan_dihormati` | ✅ |
| Tagihan `lunas`/`dibatalkan` → denda 0 | `PenghitungDenda` memeriksa status lebih dulu | `test_tagihan_lunas_tidak_lagi_berdenda`, `test_tagihan_dibatalkan_tidak_berdenda` | ✅ |
| `X-Simulasi-Tanggal` berlaku saat `APP_DEMO_MODE=true` | `SimulasiTanggal` middleware → `WaktuSistem` | `test_header_simulasi_dipakai_saat_mode_demo_aktif` | ✅ |
| `X-Simulasi-Tanggal` diabaikan saat `APP_DEMO_MODE=false` | `WaktuSistem::pakaiTanggalSimulasi` memeriksa config | `test_header_simulasi_diabaikan_saat_mode_demo_mati` | ✅ |
| Format tanggal tidak sah diabaikan, bukan error | `try/catch` di `WaktuSistem`; Carbon 3 melempar, bukan mengembalikan null | `test_format_tanggal_simulasi_tidak_sah_diabaikan_bukan_error` | ✅ |
| Tanggal simulasi tidak bocor antar-permintaan | `SimulasiTanggal` me-reset `WaktuSistem` di awal setiap permintaan | `test_tanggal_simulasi_tidak_bocor_ke_permintaan_berikutnya` | ✅ |
| Persentase dibaca sebagai teks, bukan float | `Kalkulator::persenKeRatusan` mengurai string | `test_persentase_dibaca_sebagai_teks_bukan_float` | ✅ |

## Aturan pembayaran (T5)

| Kontrak | Implementasi | Tes | Status |
|---|---|---|---|
| Pembayaran sebagian → `sebagian`, sisa benar | `PencatatPembayaran` menurunkan status dari `SUM(pembayaran.jumlah)` | `test_pembayaran_sebagian_mengubah_status_menjadi_sebagian` | ✅ |
| Pembayaran pas sisa → `lunas` | perbandingan `dibayar >= total`, bukan `==` | `test_pembayaran_pas_sisa_mengubah_status_menjadi_lunas`, `test_dua_pembayaran_sebagian_berturut_turut_menjadi_lunas` | ✅ |
| Status tidak pernah turun | status adalah fungsi dari jumlah, bukan riwayat transisi | `test_status_tidak_pernah_turun_walau_pembayaran_diulang_habis` | ✅ |
| Melebihi sisa → 422, bukan saldo negatif | `PembayaranDitolak::melebihiSisa`, pesan menyebut angka | `test_pembayaran_melebihi_sisa_ditolak_422`, `test_pembayaran_melebihi_sisa_setelah_sebagian_ditolak` | ✅ |
| Jumlah 0 / negatif / desimal → 422 | `jumlah` → `required, integer, min:1` | `test_jumlah_nol_ditolak_422`, `test_jumlah_negatif_ditolak_422`, `test_jumlah_desimal_ditolak_bukan_dibulatkan_diam_diam` | ✅ |
| Tagihan `dibatalkan` → 422 | diperiksa di dalam transaksi | `test_pembayaran_pada_tagihan_dibatalkan_ditolak_422` | ✅ |
| Tanggal pembayaran tidak boleh masa depan | `CatatPembayaranRequest` memakai `WaktuSistem`, bukan `now()` | `test_tanggal_pembayaran_masa_depan_ditolak_422`, `test_tanggal_pembayaran_hari_ini_diterima`, `test_tanggal_pembayaran_mundur_diterima` | ✅ |
| Sisa = `pokok + denda − pembayaran` | `HasilDenda::total()` sebagai satu-satunya tempat rumus itu | `test_sisa_tagihan_adalah_pokok_tambah_denda_kurang_pembayaran`, `test_denda_masuk_ke_sisa_tagihan`, `test_sisa_tidak_pernah_negatif` | ✅ |
| Dasar denda tetap pokok penuh setelah pembayaran | `PenghitungDenda` membaca `tagihan.pokok` yang dibekukan | `test_pembayaran_sebagian_tidak_menurunkan_dasar_denda` | ✅ |
| `total`/`dibayar`/`sisa` tidak saling bertentangan saat lunas | `total_final` dibekukan sekali saat status menjadi lunas | `test_total_dibekukan_saat_lunas_sehingga_tidak_bertentangan` | ✅ |
| Setiap pembayaran tercatat di `log_audit` | `PencatatAudit::AKSI_TAGIHAN_BAYAR` dengan nilai lama & baru | `test_setiap_pembayaran_tercatat_di_log_audit` | ✅ |
| Penolakan tidak menulis jejak audit | audit ditulis setelah insert berhasil, di transaksi yang sama | `test_pembayaran_ditolak_tidak_menulis_jejak_audit` | ✅ |
| `pembayaran` append-only | trigger `BEFORE UPDATE OR DELETE` | `test_pembayaran_menolak_perubahan_dan_penghapusan` | ✅ |
| Riwayat ikut di detail tagihan, urut tanggal | relasi `Tagihan::pembayaran()` dengan `orderBy` | `test_riwayat_pembayaran_ikut_di_detail_tagihan` | ✅ |
| Daftar membawa `dibayar`/`sisa` tanpa N+1 | `withSum('pembayaran as total_dibayar')` | `test_daftar_tagihan_membawa_dibayar_dan_sisa_tanpa_n_plus_satu` | ✅ |
| Dua pembayaran serentak tidak melebihi tagihan | `SELECT ... FOR UPDATE` pada baris `tagihan` sebelum memeriksa sisa | `docs/uji/uji-balapan-t5.mjs` (11/11): satu 201, satu 422, pesan menyebut sisa Rp 500.000 | ✅ |
| Penghuni tidak boleh mencatat pembayaran | `PastikanPeran` | `test_penghuni_tidak_boleh_mencatat_pembayaran`, `test_tanpa_login_ditolak`, `test_tagihan_tidak_ada_menghasilkan_404` | ✅ |

## Aturan isolasi & audit (T9)

| Kontrak | Implementasi | Tes | Status |
|---|---|---|---|
| Penghuni melihat **hanya** tagihannya sendiri | scope `Tagihan::terlihatOleh` — filter di query, bukan setelah data diambil | `test_penghuni_hanya_melihat_tagihannya_sendiri`, E2E *"penghuni A hanya melihat satu tagihan"* | ✅ |
| Tagihan penghuni lain → **404**, bukan 403 | `findOrFail` di atas query yang sudah discope | `test_penghuni_mengakses_tagihan_orang_lain_mendapat_404_bukan_403`, E2E *"tagihan penghuni B dijawab 404, bukan 403"* | ✅ |
| Pintu samping `/denda` dijaga sama ketatnya | `DendaController@show` mengambil ulang lewat scope yang sama | `test_penghuni_mengakses_denda_tagihan_orang_lain_mendapat_404` | ✅ |
| Penghuni boleh melihat denda miliknya | scope mengizinkan jalur baca untuk pemilik tagihan | `test_penghuni_bisa_melihat_denda_tagihannya_sendiri` | ✅ |
| 404 tidak bisa dibedakan dari id yang tidak ada | dua jalur memakai `findOrFail` yang sama | E2E *"id yang tidak ada menghasilkan jawaban yang sama persis"* | ✅ |
| Penghuni ditolak di seluruh endpoint pemilik | `PastikanPeran:pemilik` | `test_penghuni_memanggil_endpoint_pemilik_ditolak_403`, E2E (tagihan/audit/kamar = 403) | ✅ |
| Penghuni tidak bisa mengubah apa pun | seluruh jalur tulis di grup pemilik | `test_penghuni_tidak_bisa_mengubah_apa_pun`, E2E *"penghuni tidak bisa mencatat pembayaran"* | ✅ |
| Akun penghuni tanpa `penghuni_id` → daftar kosong | `milikPenghuni((int) ($user->penghuni_id ?? 0))` | `test_penghuni_tanpa_data_penghuni_melihat_daftar_kosong` | ✅ |
| Pemilik melihat semua tagihan dan log | scope meneruskan query apa adanya untuk pemilik | `test_pemilik_melihat_semua_tagihan`, `test_pemilik_melihat_tagihan_penghuni_mana_pun`, `test_pemilik_membaca_log_audit` | ✅ |
| Log menyebut aktor, aksi, entitas, id, waktu, nilai lama & baru | `AuditLogController@index` | `test_pemilik_membaca_log_audit` (termasuk `nilai_lama.status` dan `nilai_baru.status`), E2E *"log menyebut aktor yang melakukannya"* | ✅ |
| Log bisa disaring per entitas / id / aksi | `?entitas=&entitas_id=&aksi=&aktor_id=` | `test_log_audit_bisa_disaring_per_entitas` | ✅ |
| Urutan log tetap antar permintaan | `dibuat_pada DESC, id DESC` | `test_log_audit_urut_terbaru_dulu` | ✅ |
| Pemotongan jumlah baris diumumkan | `batas` maks 500, `meta.dipotong` | `test_batas_jumlah_baris_diumumkan` | ✅ |
| Tidak ada jalur HTTP untuk mengubah/menghapus log | hanya `index` terdaftar; `POST` → 405, `PUT`/`DELETE` → 404 | `test_tidak_ada_endpoint_untuk_mengubah_atau_menghapus_log_audit`, E2E *"tidak ada tombol untuk mengubah atau menghapus log"* | ✅ |
| UPDATE langsung ke `log_audit` ditolak **basis data** | trigger `BEFORE UPDATE` | `test_update_langsung_ke_log_audit_ditolak_basis_data` (memakai `DB::statement` mentah) | ✅ |
| DELETE langsung ke `log_audit` ditolak **basis data** | trigger `BEFORE DELETE` | `test_delete_langsung_ke_log_audit_ditolak_basis_data` | ✅ |
| Penolakan menyebut alasannya, bukan galat acak | pesan trigger memuat "append-only" | `test_penolakan_menyebut_append_only_bukan_galat_acak` | ✅ |

## Konvensi HTTP (seam 2)

Bukan aturan bisnis, tapi kontrak yang harus berlaku sejak tiket pertama.

| Kontrak | Implementasi | Tes | Status |
|---|---|---|---|
| Respons sukses `{ "data": ... }` | `AuthController`, `UserResource` | `test_login_dengan_kredensial_benar_mengembalikan_token` | ✅ |
| Error `{ "message", "errors" }` | `LoginRequest`, handler Laravel | `test_login_tanpa_email_ditolak_422` | ✅ |
| 401 tanpa token | middleware `auth:sanctum` | `test_me_tanpa_token_ditolak_401` | ✅ |
| 401 token tidak valid | middleware `auth:sanctum` | `test_me_dengan_token_tidak_valid_ditolak_401` | ✅ |
| 403 peran salah | `PastikanPeran` | `test_penghuni_mengakses_endpoint_pemilik_ditolak_403` | ✅ |
| 422 validasi gagal | `LoginRequest` | `test_login_tanpa_email_ditolak_422` | ✅ |
| Selalu JSON, walau tanpa `Accept` | `PaksaJson` | `test_tanpa_header_accept_tetap_401_bukan_500` | ✅ |
| 422 walau tanpa `Accept` (bukan 302) | `PaksaJson` | `test_validasi_gagal_tanpa_header_accept_tetap_422_bukan_302` | ✅ |
| Zona waktu `Asia/Jakarta` | `config/app.php`, `php.ini`, `TZ` Postgres | `test_zona_waktu_aplikasi_asia_jakarta` | ✅ |
| Bentrok unique index → 422, bukan 500 | `KontrakController`, `TarifController`, `KomponenBiayaController` menangkap SQLSTATE `23505` | `test_dua_kontrak_aktif_pada_satu_kamar_ditolak_422`, `test_tarif_ganda_pada_tanggal_sama_ditolak_422` | ✅ |

## Jejak tiket

| Tiket | Issue | Bukti |
|---|---|---|
| T1 Walking skeleton | #2 | 14 tes hijau, 9/9 langkah E2E browser, screenshot dashboard |
| T2 Data induk | #3 | 46 tes hijau (144 asersi), 13/13 langkah E2E browser, 3 screenshot (`T2-kamar.png`, `T2-penghuni.png`, `T2-kontrak.png`) |
| T3 Tagihan + prorata | #4 | 91 tes hijau (387 asersi) — termasuk 22 tes kalkulator murni tanpa DB; 18/18 langkah E2E browser; 2 screenshot (`T3-tagihan.png`, `T3-rincian.png`) |
| T6 Denda + simulasi tanggal | #7 | 136 tes hijau (992 asersi) - 46 tes murni tanpa DB; 23/23 langkah E2E browser; 2 screenshot (`T6-denda.png`, `T6-tanpa-simulasi.png`) |
| T7 Umur tunggakan | #8 | 164 tes hijau (1421 asersi) - 60 tes murni tanpa DB; 31/31 langkah E2E browser; 1 screenshot (`T7-tunggakan.png`) |
| T4 Terbit massal | #5 | 186 tes hijau (1517 asersi) - 60 tes murni tanpa DB; 15/15 langkah E2E browser; 9/9 langkah uji balapan HTTP; 1 screenshot (`T4-terbit-massal.png`) |
| T5 Catat pembayaran | #6 | 214 tes hijau (1638 asersi); 21/21 langkah E2E browser; 11/11 langkah uji balapan HTTP; 1 screenshot (`T5-pembayaran.png`) |
| T9 Portal penghuni + log audit | #9 | 232 tes hijau (1703 asersi); 23/23 langkah E2E browser; 2 screenshot (`T9-portal-penghuni.png`, `T9-log-audit.png`) |

## Cara menjalankan ulang bukti

```bash
docker compose up -d
docker compose exec api php artisan test          # 232 tes
docker compose exec api php artisan test --testsuite=Unit   # 60 tes murni, tanpa DB
node docs/uji/e2e-t1.mjs                          # perlu Chrome --remote-debugging-port=9222
node docs/uji/e2e-t2.mjs                          # 8 kamar + 6 penghuni + kontrak lewat UI
node docs/uji/e2e-t3.mjs                          # terbit tagihan + rincian + prorata lewat UI
node docs/uji/e2e-t6.mjs                          # denda 0 → 15.000 → 450.000 lewat simulasi tanggal
node docs/uji/e2e-t7.mjs                          # empat kelompok umur tunggakan + konsistensi denda
node docs/uji/e2e-t4.mjs                          # terbit massal + penolakan duplikat lewat UI
node docs/uji/e2e-t5.mjs                          # catat pembayaran sebagian lalu lunas lewat UI
node docs/uji/e2e-t9.mjs                          # isolasi portal penghuni + log audit pemilik
node docs/uji/uji-balapan-t4.mjs                  # dua permintaan HTTP serentak → satu 201, satu 409
node docs/uji/uji-balapan-t5.mjs                  # dua pembayaran serentak → satu 201, satu 422
```

`e2e-t2.mjs`, `e2e-t3.mjs`, `e2e-t4.mjs`, `e2e-t5.mjs`, `e2e-t6.mjs`, `e2e-t7.mjs`, `e2e-t9.mjs`,
`uji-balapan-t4.mjs`, dan `uji-balapan-t5.mjs` mengubah database demo. Untuk mengulang dari
keadaan bersih: `docker compose exec api php artisan migrate:fresh --seed`.

**Tutup tab Chrome yatim sebelum menjalankan E2E lagi.** Skrip membuka tab baru tiap kali
dijalankan dan menutupnya di akhir; bila skrip gagal di tengah, tabnya tertinggal. Setelah
beberapa kali, Chrome lambat dan login bisa gagal karena alasan yang tidak ada hubungannya
dengan aplikasi. Bersihkan dengan `Invoke-RestMethod http://127.0.0.1:9222/json/list` lalu
`/json/close/<id>` untuk setiap tab bertipe `page`.

**Kode dibakar ke image, bukan di-mount.** Setelah mengubah PHP atau Vue, jalankan
`docker compose build api web && docker compose up -d api web` sebelum menguji. Tanpa itu tes
menguji kode lama dan hasilnya hijau palsu — lihat catatan audit butir 6.

## Catatan audit

**Dua bug ditemukan lewat E2E, bukan lewat tes fitur.** Keduanya layak dicatat karena menunjukkan
nilai menguji lewat browser, bukan hanya lewat `$this->postJson()`:

1. **401 menjadi 500.** Klien tanpa header `Accept: application/json` diperlakukan sebagai
   permintaan web; middleware `auth` mencoba me-redirect ke route bernama `login` yang tidak ada
   di aplikasi API-only. Diperbaiki dengan middleware `PaksaJson`. Tes regresi ditambahkan.

2. **Tes menghapus database demo.** `RefreshDatabase` menjalankan `migrate:fresh`, dan
   `DB_DATABASE` dari environment Docker menang atas `<env>` di `phpunit.xml` karena Laravel
   membaca `$_SERVER` lebih dulu. Akibatnya tes menulis ke database `kos` — data demo hilang, dan
   gejalanya menyesatkan: login gagal padahal kode benar. Diperbaiki dengan `<server>` (bukan
   `<env>`) dan pagar di `tests/TestCase.php` yang menolak berjalan bila nama database tidak
   berakhiran `_test`.

**T2 menemukan tiga bug lewat E2E dan satu lewat pemeriksaan mata.**

3. **Default kolom tidak terbaca model.** `Kamar::create(['nomor' => 'A1'])` mengembalikan objek
   dengan `status_huni = null`, karena default `'kosong'` hanya ada di skema database dan objek di
   memori belum membacanya kembali. Respons API mengirim `null` untuk kolom yang tidak pernah
   `null`. Diperbaiki dengan `protected $attributes` pada `Kamar` dan `Kontrak` — model dan
   database kini sepakat tanpa perlu `refresh()` di setiap pemanggil.

4. **Nama kelas CSS bentrok.** Lencana status memakai `class="lencana-status kosong"`, dan
   `.tabel .kosong` (gaya untuk baris "belum ada data") menang sehingga lencana melebar jadi oval
   setinggi 60px. Ketahuan hanya dari tangkapan layar; tidak ada tes yang bisa menangkapnya.
   Diperbaiki dengan prefiks `status-` pada kelas lencana.

5. **Kolom "tarif terakhir" selalu kosong.** `KamarController@index` tidak memuat relasi tarif,
   sehingga `whenLoaded` selalu menghasilkan `null`. Diperbaiki dengan relasi `tarifTerakhir()`
   berbasis `latestOfMany`, bukan memuat seluruh riwayat lalu mengambil yang pertama.

**Satu kegagalan E2E ternyata salah di skrip uji, bukan di aplikasi.** Iterasi pendaftaran
penghuni memakai nama `Budi Santoso` yang sudah ada dari seeder; pemeriksaan "nama muncul di
tabel" langsung lulus sebelum formulir terkirim, lalu formulir tetap terbuka dan tombol "Tambah
penghuni" hilang pada iterasi berikutnya. Skrip diperbaiki: nama yang dipakai harus baru, dan
keberhasilan ditentukan oleh **formulir tertutup** lebih dulu, baru namanya muncul di tabel.

**Satu asersi tes ditulis asal-asalan lalu diperbaiki.** Tes `test_kontrak_baru_boleh_setelah_…`
semula berisi `assertSame('kosong', $x === 'terisi' ? 'kosong' : 'kosong')` — kedua cabang sama,
jadi tidak menguji apa pun. Diganti dengan pemeriksaan status kamar yang sebenarnya.

**T3: seam murni membayar dirinya sendiri.** Seluruh logika prorata dan penjepitan tanggal diuji
dalam **0,11 detik tanpa basis data** — 22 tes, termasuk 7 tarif × 4 panjang bulan untuk sifat
"sebulan penuh selalu persis tarif" dan 36 kombinasi tanggal 29–31 × 12 bulan untuk sifat "jatuh
tempo tidak pernah menggelinding". Kalau logika ini ditulis langsung di dalam service yang menyentuh
Eloquent, tes yang sama butuh kontrak, kamar, dan tarif untuk setiap kasus, dan tidak akan pernah
ditulis sebanyak itu.

6. **Lencana status tagihan tidak bergaya.** Kelas yang dihasilkan adalah
   `status-belum_bayar` (dari `status.replace('_',' ')` pada teks, tapi kelas CSS memakai nilai
   mentah), dan CSS belum punya aturan untuk itu — lencana tampil sebagai teks polos tanpa latar.
   Ketahuan dari tangkapan layar, bukan dari tes. Diperbaiki dengan menambahkan
   `.status-belum_bayar`, `.status-sebagian`, `.status-lunas`, `.status-dibatalkan`.

7. **Label "prorata 31/31" membingungkan.** Tagihan untuk penghuni yang masuk tanggal 1 semula
   berlabel "Sewa Oktober 2026 (prorata 31/31 hari)". Angkanya benar (tarif penuh), tetapi
   keterangannya membuat pembaca mengira ada perhitungan prorata yang tidak perlu. Sekarang label
   prorata hanya muncul bila `hari_tinggal < hari_dalam_bulan`.

**T6: tes menangkap bug di kode yang baru saja ditulis.** Tes `DendaHttpTest` gagal pada
`test_format_tanggal_simulasi_tidak_sah_diabaikan_bukan_error` karena Carbon 3 **melempar**
exception untuk masukan yang tidak bisa diurai, bukan mengembalikan `null` seperti Carbon 2.
Tanpa `try/catch`, salah ketik tanggal di tengah demo berubah menjadi 500 — kegagalan yang paling
tidak boleh terjadi saat presentasi. Diperbaiki di `WaktuSistem::pakaiTanggalSimulasi`.

8. **Header CORS tidak terbaca JavaScript.** `X-Simulasi-Dipakai` dikirim server, tetapi
   `config/cors.php` punya `exposed_headers => []`, sehingga `fetch()` di browser tidak bisa
   membacanya — nilainya `null` di E2E. Fitur bekerja, tetapi UI tidak bisa menjelaskan apakah
   simulasi berlaku. Diperbaiki dua arah: header didaftarkan di `exposed_headers`, dan status
   simulasi juga diumumkan di `meta` badan respons (`tanggal_acuan`, `simulasi`, `demo_mode`)
   supaya UI tidak bergantung pada header sama sekali.

9. **Panel rincian tidak ikut disegarkan saat tanggal acuan berubah.** Setelah simulasi
   dibersihkan, daftar tagihan menampilkan denda "—" sementara panel di atasnya masih menampilkan
   Rp 450.000 dan "21 Nov 2026" — dua angka berbeda untuk tagihan yang sama di satu layar.
   Ketahuan dari tangkapan layar pembanding, bukan dari tes. `muat()` sekarang memuat ulang panel
   rincian bila sedang terbuka, dan E2E memeriksa panel itu ikut berubah.

**Dua kegagalan E2E T6 ternyata salah di skrip uji, bukan di aplikasi.** Keduanya layak dicatat
karena keduanya mudah disalahartikan sebagai bug:

- **Ekspektasi pokok salah.** Skrip membuat kontrak masuk 15 Oktober lalu mengharapkan denda 1%
  dari Rp 1.500.000. Tagihan Oktober untuk kontrak itu **memang** diprorata jadi Rp 822.581 —
  R-01 bekerja benar — sehingga dendanya Rp 8.226, bukan Rp 15.000. Diperbaiki dengan memakai
  kontrak yang masuk Januari, supaya tagihan Oktober adalah tarif penuh dan angkanya cocok dengan
  contoh di tiket.
- **Regex di luar template string.** `/450\\.000/` pada kode JavaScript biasa berarti "backslash
  literal", bukan titik. Asersinya selalu gagal walau teksnya benar. Diperbaiki menjadi
  `/450\.000/`.

**Catatan operasional: tab Chrome yatim.** Setelah beberapa kali E2E gagal di tengah, lima tab
tertinggal dan login mulai gagal karena alasan yang tidak ada hubungannya dengan aplikasi. Skrip
E2E sekarang perlu dijalankan setelah membersihkan tab `page` lewat `/json/list` + `/json/close`.
Prosedurnya dicatat di bagian "Cara menjalankan ulang bukti".

**T7: satu bug tampilan yang hanya bisa dilihat mata.** Kartu kelompok memakai `<button>` supaya
bisa difokus dan diaktifkan lewat papan ketik. Konsekuensinya kartu mewarisi aturan global
`button { color: #fff; background: var(--aksen) }`. Dua kelas anak punya warna eksplisit, tetapi
`.kelompok-total` tidak — jadi nilai rupiah tiap kelompok tampil **putih di atas kartu putih**.
DOM-nya lengkap, teksnya benar, dan setiap tes berbasis teks lulus; yang salah hanya warnanya.
Ditemukan dari memeriksa tangkapan layar, lalu dipastikan lewat `getComputedStyle(...).color`
yang mengembalikan `rgb(255, 255, 255)`. Diperbaiki dengan me-reset seluruh tampilan tombol pada
`.kartu-kelompok`, dan E2E sekarang memeriksa bahwa tidak ada teks kartu yang berwarna putih.
Pelajaran yang sama dengan bug lencana T2 (catatan 4): **tangkapan layar menangkap kelas bug yang
tidak bisa dijangkau asersi teks.**

**Satu asersi E2E T7 salah, bukan aplikasinya.** Skrip mengharapkan semua kelompok kosong pada
tanggal acuan 2020-01-01. Yang terjadi: keempat tagihan memang **belum jatuh tempo**, jadi
semuanya masuk kelompok `belum_jatuh_tempo` (`[4,0,0,0]`) — dan itu perilaku yang benar, sekaligus
bukti bahwa kelompok "belum jatuh tempo" benar-benar terpisah dari 0-30. Asersinya diperbaiki.

**T4: batas yang tidak bisa ditembus tes PHPUnit.** Kriteria tiket menuntut "dua request bersamaan →
tetap hanya satu yang menang". Itu tidak bisa dibuat di dalam satu proses PHP: permintaan kedua
selalu berjalan setelah yang pertama selesai, jadi yang diuji adalah urutan, bukan balapan.
Percobaan pertama memakai dua koneksi basis data di dalam satu tes juga gagal secara berarti —
PostgreSQL memblokir penulis kedua dengan `55P03` (lock timeout), bukan menolaknya dengan `23505`,
sehingga kegagalan yang terlihat bukan berasal dari constraint yang sedang diuji. Asersi `23505`
pada percobaan itu **salah**, dan tesnya dibuang alih-alih dilonggarkan.

Solusinya memisahkan dua hal yang berbeda sifat: `PenerbitanBalapanTest` menguji **invarian**
(berapa kali pun dicoba, jumlah baris tetap satu; periode lain dan kontrak lain tetap boleh), dan
`docs/uji/uji-balapan-t4.mjs` menguji **balapan sungguhan** lewat dua permintaan HTTP paralel.
Bukti dari yang kedua kuat karena pesan yang kalah adalah *"Tagihan periode ini baru saja diterbitkan
oleh proses lain"* — kalimat itu hanya bisa muncul dari jalur `23505`, bukan dari pemeriksaan
`exists()` di aplikasi.

**T4: dua keadaan yang tidak bisa dibuat lewat API.** E2E semula merencanakan satu kontrak tanpa
tarif (untuk membuktikan "gagal" terpisah dari "terbit") dan satu kontrak bertanggal masuk masa
depan (untuk "belum mulai"). Keduanya ditolak endpoint kontrak lebih dulu — kamar tanpa tarif dan
tanggal masuk masa depan sama-sama 422. Itu keputusan T2 yang benar, jadi skenarionya yang
disesuaikan: E2E mendemokan "terbit" dan "dilewati", sementara "gagal" dan "belum mulai" tetap
terbukti di tes fitur. Yang penting bukan bahwa setiap nasib muncul di browser, melainkan bahwa
setiap nasib punya bukti di lapisan tempat ia bisa terjadi.

**T4: skrip E2E yang menggantung saat gagal.** Dua kali skrip berhenti di tengah dan prosesnya tidak
pernah selesai, sehingga perintah menabrak batas waktu 10 menit. Penyebabnya: tab Chrome hanya
ditutup di jalur sukses. Ditambahkan penutupan tab di `catch`, yang sekaligus mengurangi tab yatim —
masalah yang sudah tercatat di catatan operasional sebelumnya.

**T4: `sesi.panggil` membuang badan respons galat.** Penolakan 409 pada terbit massal menyertakan
rincian kontrak mana yang dilewati dan mengapa. Sebelumnya `sesi.js` hanya meneruskan `status` dan
`errors`, jadi layar hanya bisa menampilkan "permintaan gagal" padahal server sudah menjelaskan.
Badan respons sekarang ikut dibawa sebagai `galat.body`, dan panel menampilkannya sebagai hasil —
karena 409 di sini bukan kerusakan, melainkan jawaban.

**T4: pesan "tidak ada tagihan baru" berwarna merah.** Di tangkapan layar pertama, keadaan yang
sepenuhnya normal ("semua kontrak sudah tertagih") tampil dengan latar merah bahaya. Ditambahkan
kelas `.pesan-netral` di `style.css` untuk keadaan yang bukan keberhasilan dan bukan kesalahan.
Ditemukan dari memeriksa gambar, bukan dari asersi teks — kelas bug yang sama dengan catatan T7.

**T4: `log_audit` dibangun lebih awal dari tiketnya.** Kriteria penerimaan T4 menuntut "setiap
tagihan yang terbit tercatat di `log_audit`", padahal tabel itu lingkup T9 — dan T9 justru diblokir
T4. Yang dibangun sekarang adalah fondasi **tulis**: tabel, trigger append-only, `PencatatAudit`,
dan pemanggilannya pada penerbitan tagihan, penambahan tarif, dan penambahan kebijakan denda (waktu
itu masih berupa tabel `parameter_denda`, yang kemudian dilebur ke `kebijakan_kos` di T17). T9
tinggal menambah portal penghuni dan endpoint **baca** `GET /audit-log`. Dua keputusan bentuk yang
perlu dicatat: `aktor_id` sengaja **tanpa foreign key** (karena `ON DELETE SET NULL` adalah UPDATE
yang akan ditolak trigger, sementara `RESTRICT` membuat user yang pernah tercatat tidak bisa
dihapus selamanya), dan `aktor_nama` **disalin** alih-alih di-join (kalau di-join, mengganti nama
user akan menulis ulang sejarah log).

**T5: angka yang bertentangan di satu layar, ditemukan saat memikirkan denda yang berhenti.**
Denda berhenti berjalan begitu tagihan lunas — itu benar, dan itu keputusan T6. Tetapi
konsekuensinya baru terlihat saat pembayaran ditambahkan: tagihan Rp 1.500.000 yang dibayar
Rp 1.515.000 (pokok + denda 15.000) akan menampilkan **total Rp 1.500.000** dan
**dibayar Rp 1.515.000** pada saat yang sama, karena `total` dihitung dari denda yang kini nol
sementara `dibayar` adalah jumlah uang yang benar-benar masuk. Tidak ada tes yang gagal — kedua
angka dihitung dengan benar menurut rumusnya masing-masing. Yang salah adalah rumusnya tidak lagi
menjelaskan hal yang sama. Diperbaiki dengan kolom `total_final` yang dibekukan **sekali** saat
status berubah menjadi lunas. Ini contoh kedua (setelah penjepitan jatuh tempo) bahwa keputusan
yang benar di satu tiket bisa menciptakan ketidakcocokan di tiket berikutnya, dan yang menangkapnya
adalah memeriksa tampilan, bukan menjalankan tes.

**T5: penguncian baris dibuktikan dari pesan yang kalah, bukan dari kode.** `FOR UPDATE` mudah
ditulis dan mustahil dibuktikan lewat satu proses PHP. Buktinya datang dari uji balapan HTTP
paralel: dua pembayaran Rp 1.000.000 pada tagihan Rp 1.500.000, dan respons yang ditolak berbunyi
*"Pembayaran Rp 1.000.000 melebihi sisa tagihan Rp **500.000**"*. Angka 500.000 itu hanya bisa
muncul kalau permintaan kedua **menunggu** transaksi pertama commit lalu membaca ulang — kalau
kunci tidak bekerja, ia akan membaca 1.500.000 dan lolos. Percobaan empat pembayaran serentak
Rp 500.000 juga berakhir tepat di Rp 1.500.000, tidak lebih.

**T5: E2E menemukan bug yang tidak mungkin ditemukan tes fitur.** Setelah pembayaran tercatat,
tombol "Catat pembayaran" semula disembunyikan karena panel sudah punya hasil untuk ditampilkan.
Akibatnya pemilik yang ingin mencatat cicilan kedua harus **menutup dan membuka ulang panel** —
dan tidak ada tes HTTP yang bisa melihat itu, karena API-nya baik-baik saja. Ketahuan karena E2E
T5 mencoba membayar dua kali berturut-turut dan langkah kedua tidak menemukan tombolnya. Syarat
tampil tombol dipisahkan dari syarat tampil pesan hasil.

**T5: tiga kegagalan E2E yang semuanya salah di skrip, bukan di aplikasi.** Dicatat karena
polanya berulang: (a) `/1\\.000\\.000/` di JavaScript biasa berarti "backslash literal", bukan
titik — asersi selalu gagal walau teksnya benar, persis kesalahan yang sudah tercatat di T6;
(b) satu asersi ditulis `!lebih.galat && lebih.galat.length > 0`, dua syarat yang mustahil benar
bersamaan karena `galat` sekaligus dipakai sebagai nama objek hasil dan nama bidang pesan;
(c) asersi "tagihan lunas" masih mengharapkan pesan netral *"sudah lunas"*, padahal pesan itu
sekarang sengaja ditekan ketika ada hasil pembayaran yang baru — yang benar-benar penting adalah
**tidak ada lagi tombol pembayaran**, dan asersinya diubah ke situ. Pola yang sama muncul tiga
kali: asersi yang menguji **kalimat** alih-alih **keadaan**. Kalimat boleh berubah karena alasan
tampilan; keadaan tidak.

**T5: batasan yang dicatat, bukan disembunyikan.** `pembayaran` bersifat append-only (trigger
menolak UPDATE dan DELETE), tetapi **belum ada baris pembalik**. Artinya salah input jumlah tidak
bisa diperbaiki lewat aplikasi. Ini konsekuensi langsung dari keputusan yang benar — catatan uang
yang bisa diedit diam-diam tidak layak dipercaya — dan jalan keluarnya sudah jelas bentuknya
(entri balik bertanda dengan rujukan ke baris yang dikoreksi), tetapi membangunnya butuh alur
tersendiri. Dicatat di SPEC §5 dan §11, di README, dan di komentar migrasi, supaya tidak menjadi
temuan yang tidak terjawab saat sidang.

**T9: 404 dipilih karena 403 membocorkan lebih banyak daripada yang ditutupnya.** Naluri pertama
untuk "penghuni membuka tagihan orang lain" adalah 403 — "kamu login, tapi ini bukan milikmu". Itu
salah untuk kasus ini. 403 membedakan "ada" dari "tidak ada", sehingga dengan mencoba id berurutan
seseorang bisa memetakan berapa tagihan yang ada dan id mana yang hidup, tanpa pernah membaca satu
baris pun isinya. 404 menyamakan kedua jawaban, dan itulah yang diuji: tes membandingkan respons
untuk tagihan orang lain dengan respons untuk id yang tidak pernah ada, bukan hanya memeriksa satu
kode status. Perbedaan 403 vs 404 di endpoint pemilik justru sebaliknya dan tetap benar — di sana
pengguna memang berhak tahu bahwa endpoint itu ada, hanya perannya tidak cocok.

**T9: route model binding mengambil baris tanpa memandang pemiliknya.** Isolasi di daftar terasa
cukup sampai ada yang mengetik `/api/tagihan/7` langsung. `Tagihan $tagihan` di tanda tangan
controller sudah berisi baris siapa pun sebelum satu baris kode pun sempat memeriksa. Karena itu
`show` dan `denda` **mengambil ulang** barisnya lewat scope yang sama meskipun binding sudah
menyediakannya — satu query tambahan ditukar dengan satu tempat yang tidak bisa lupa. Ini juga
alasan aturannya ditulis sebagai scope di model, bukan `if ($user->isPenghuni())` yang disalin ke
tiap controller: penyalinan akan terlewat pada endpoint yang ditambahkan belakangan, dan tidak ada
yang menyadarinya sampai ada kebocoran.

**T9: `/penghuni/tagihan` ditelan `apiResource('penghuni')`.** Gejalanya
`SQLSTATE[22P02] invalid input syntax for type bigint: "tagihan"` — "tagihan" diperlakukan sebagai
id penghuni. Ini kejadian **kedua** dengan kelas yang sama setelah `/tagihan/terbitkan` di T4, dan
penyebabnya selalu satu: Laravel mencocokkan rute sesuai urutan deklarasi, bukan berdasarkan
kekhususan. Rute yang lebih spesifik harus didaftarkan lebih dulu. Polanya sekarang dicatat di
SPEC §7 supaya tidak menjadi kejadian ketiga.

**T9: satu tes lulus sendiri tetapi gagal saat dijalankan bersama.** `PUT /api/kamar/1` diasersikan
403, dan saat tes itu berjalan sendiri memang 403. Dalam satu run penuh hasilnya 404. Penyebabnya
bukan aplikasi: `SubstituteBindings` berjalan **sebelum** `PastikanPeran`, jadi id kamar yang
kebetulan tidak ada menghasilkan 404 sebelum 403 sempat diperiksa — dan id 1 hanya ada bila tes itu
kebetulan yang pertama membuat kamar. Diperbaiki dengan memakai id dari data yang baru dibuat, bukan
angka tetap. Aturan umumnya: tes yang bergantung pada urutan id akan berperilaku berbeda tergantung
tes lain yang berjalan lebih dulu, dan kegagalannya tidak menunjuk ke apa pun yang nyata.

**T9: log audit menampilkan "— → 1.500.000" untuk baris yang baru dibuat.** Ditemukan dari
tangkapan layar, bukan dari tes: semua angka benar, tetapi panahnya menyiratkan perubahan dari nilai
kosong padahal tidak ada yang berubah — barisnya memang baru. Sekarang baris baru hanya menampilkan
nilainya, tanpa panah dan tanpa coretan. Ini contoh ketiga di proyek ini (setelah penjepitan jatuh
tempo dan `total_final`) bahwa **yang menangkap ketidakcocokan adalah memeriksa tampilan**, bukan
menjalankan tes: tidak ada satu pun angka yang salah.

**T9: satu tes lama memang harus berubah, dan itu bukan regresi.** `DendaHttpTest` semula menegaskan
penghuni **tidak boleh** melihat denda sama sekali (403). T9 sengaja membukanya untuk tagihan
miliknya sendiri, karena denda adalah angka yang harus ia bayar. Tesnya diganti — bukan dihapus —
menjadi penegasan aturan yang sekarang berlaku: tagihan orang lain tetap 404. Perbedaan antara
"tes usang" dan "regresi" hanya bisa dibedakan kalau aturan yang berubah dicatat lebih dulu; tanpa
itu, memperbaiki tes akan terlihat sama dengan menutupi kegagalan.
