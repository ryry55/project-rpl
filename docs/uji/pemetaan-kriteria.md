# Pemetaan kriteria penerimaan ke tes

Issue **#1** memuat 43 kotak centang kriteria penerimaan. Berkas ini mencatat tes mana yang
membuktikan tiap kriteria, supaya centang di issue bisa diperiksa ulang kapan saja tanpa
menjalankan ulang seluruh suite dan tanpa menebak.

Status suite saat pemetaan dibuat: **232 tes lulus, 1703 asersi**
(`docker compose exec api php artisan test`). Nama tes di bawah memakai format
`Kelas::nama_metode`; berkasnya ada di `api/tests/Feature/` atau `api/tests/Unit/`.

Aturan pemetaan: satu kriteria dicentang hanya kalau ada tes yang **gagal** bila perilakunya
rusak. Kriteria yang hanya "ada kodenya" tanpa tes tidak dicentang.

## Autentikasi dan peran

| # | Kriteria | Tes pembukti |
|---|---|---|
| 1 | Login benar → 200 + token | `AuthTest::test_login_dengan_kredensial_benar_mengembalikan_token` |
| 2 | Password salah → 401 | `AuthTest::test_login_dengan_password_salah_ditolak_401` |
| 3 | `GET /me` mengembalikan peran | `AuthTest::test_me_mengembalikan_peran_pengguna_yang_login` |
| 4 | Token tidak valid → 401 | `AuthTest::test_me_dengan_token_tidak_valid_ditolak_401` |
| 5 | Penghuni memanggil endpoint pemilik → 403 | `AuthTest::test_penghuni_mengakses_endpoint_pemilik_ditolak_403`, `IsolasiDanAuditTest::test_penghuni_memanggil_endpoint_pemilik_ditolak_403` |

## Data induk dan tarif

| # | Kriteria | Tes pembukti |
|---|---|---|
| 6 | Nomor kamar duplikat → 422 | `DataIndukTest::test_nomor_kamar_duplikat_ditolak_422` |
| 7 | Kontrak menyimpan `tgl_masuk`, tarif, deposit | `DataIndukTest::test_kontrak_menyimpan_tgl_masuk_tarif_dan_deposit` |
| 8 | Dua kontrak aktif pada satu kamar → 422 | `DataIndukTest::test_dua_kontrak_aktif_pada_satu_kamar_ditolak_422` |
| 9 | Mengubah tarif membuat baris **baru**, baris lama utuh | `RiwayatTarifTest::test_mengubah_tarif_menambah_baris_dan_baris_lama_tetap` |
| 10 | Tarif efektif periode P = baris terakhir `berlaku_dari <= P` | `RiwayatTarifTest::test_tarif_efektif_adalah_baris_terakhir_yang_berlaku_dari_kurang_dari_sama_periode` |

## Penerbitan tagihan dan prorata

| # | Kriteria | Tes pembukti |
|---|---|---|
| 11 | Masuk tanggal 1 → pokok persis tarif penuh | `KalkulatorTest::test_sebulan_penuh_selalu_persis_tarif_untuk_banyak_nilai`, `TagihanTest::test_masuk_tanggal_1_mendapat_tarif_penuh_bukan_kurang_serupiah` |
| 12 | Masuk 15 Oktober, tarif 1.500.000 → Rp 822.581 | `KalkulatorTest::test_prorata` (baris `masuk 15 Oktober (17/31)`), `TagihanTest::test_masuk_15_oktober_memakai_prorata_17_dari_31` |
| 13 | Masuk 31 Oktober → Rp 48.387 | `KalkulatorTest::test_prorata` (baris `masuk 31 Oktober (1/31)`), `TagihanTest::test_masuk_31_oktober_memakai_prorata_1_dari_31` |
| 14 | Biaya tambahan jadi baris rincian terpisah | `TagihanTest::test_komponen_biaya_muncul_sebagai_baris_tersendiri` |
| 15 | `pokok` = jumlah rincian; `total` = `pokok` + denda | `TagihanTest::test_pokok_sama_dengan_jumlah_seluruh_rincian`, `TagihanTest::test_total_selalu_sama_dengan_pokok_tambah_denda` |
| 16 | `jatuh_tempo` mengikuti tanggal masuk | `TagihanTest::test_jatuh_tempo_mengikuti_tanggal_masuk` |
| 17 | `tarif_terpakai` menyimpan snapshot | `TagihanTest::test_tagihan_menyimpan_snapshot_tarif_dan_komponen` |
| 18 | Ubah tarif setelah terbit → tagihan lama tidak berubah | `TagihanTest::test_menaikkan_tarif_tidak_mengubah_tagihan_yang_sudah_terbit` |

## Terbit massal dan penolakan duplikat

| # | Kriteria | Tes pembukti |
|---|---|---|
| 19 | `POST /tagihan/terbitkan` membuat tagihan semua kontrak aktif | `PenerbitanMassalTest::test_terbit_untuk_semua_kontrak_aktif` |
| 20 | Periode sama ditekan lagi → 409, bukan dua baris | `PenerbitanMassalTest::test_menekan_dua_kali_menghasilkan_409_bukan_tagihan_kedua` |
| 21 | Dua request bersamaan → satu menang | `PenerbitanBalapanTest::test_berapa_kali_pun_dicoba_jumlah_baris_tetap_satu`, `PenerbitanMassalTest::test_unique_index_menolak_duplikat_walau_aplikasi_dilewati` |
| 22 | Respons menyebut berapa terbit dan berapa dilewati | `PenerbitanMassalTest` — asersi `data.terbit`, `data.dilewati`, `data.dilewati_duplikat` |

## Pembayaran

| # | Kriteria | Tes pembukti |
|---|---|---|
| 23 | Pembayaran sebagian → `sebagian`, sisa benar | `PembayaranTest::test_pembayaran_sebagian_mengubah_status_menjadi_sebagian` |
| 24 | Pembayaran pas → `lunas` | `PembayaranTest::test_pembayaran_pas_sisa_mengubah_status_menjadi_lunas` |
| 25 | Melebihi sisa → 422, bukan saldo | `PembayaranTest::test_pembayaran_melebihi_sisa_ditolak_422` |
| 26 | Jumlah 0 atau negatif → 422 | `PembayaranTest::test_jumlah_nol_ditolak_422`, `PembayaranTest::test_jumlah_negatif_ditolak_422` |
| 27 | Setiap pembayaran tercatat di `log_audit` | `PembayaranTest::test_setiap_pembayaran_tercatat_di_log_audit` |

## Denda

| # | Kriteria | Tes pembukti |
|---|---|---|
| 28 | Hari terlambat 7 → denda 0 | `DendaTest::test_hari_denda_nol_dalam_tenggang`, `DendaHttpTest::test_denda_masih_nol_dalam_masa_tenggang` |
| 29 | Hari terlambat 8 → denda 1% pokok | `DendaTest::test_denda_pada_titik_batas`, `DendaHttpTest::test_denda_hari_ke_8_lewat_http` |
| 30 | Hari terlambat 37 → denda 30% (plafon) | `DendaTest::test_plafon_tercapai_tepat_di_hari_terlambat_37`, `DendaHttpTest::test_denda_plafon_tepat_di_hari_ke_37` |
| 31 | Hari terlambat 100 → tetap 30% | `DendaHttpTest::test_denda_tetap_plafon_walau_setahun_kemudian`, `DendaTest::test_denda_tidak_pernah_melebihi_plafon_untuk_banyak_pokok` |
| 32 | Pembayaran sebagian tidak menurunkan plafon | `DendaHttpTest::test_pembayaran_sebagian_tidak_menurunkan_dasar_denda`, `PembayaranTest::test_pembayaran_sebagian_tidak_menurunkan_dasar_denda` |
| 33 | Ubah parameter denda setelah terbit → tagihan lama tidak berubah | `DendaHttpTest::test_mengubah_parameter_denda_tidak_mengubah_tagihan_lama`, `TagihanTest::test_parameter_denda_dibekukan_di_tagihan` |
| 34 | `X-Simulasi-Tanggal` menggeser hari ini, hanya saat mode demo | `DendaHttpTest::test_header_simulasi_dipakai_saat_mode_demo_aktif`, `DendaHttpTest::test_header_simulasi_diabaikan_saat_mode_demo_mati` |

## Umur tunggakan

| # | Kriteria | Tes pembukti |
|---|---|---|
| 35 | Empat kelompok: belum jatuh tempo, 0–30, 31–60, > 60 | `TunggakanHttpTest::test_empat_kelompok_selalu_dikembalikan_walau_kosong`, `UmurTunggakanTest::test_kelompok_pada_titik_batas` |
| 36 | Batas 30/31 dan 60/61 benar | `TunggakanHttpTest::test_batas_hari_30_dan_31_lewat_http`, `TunggakanHttpTest::test_batas_hari_60_dan_61_lewat_http` |
| 37 | Tagihan lunas dan dibatalkan tidak muncul | `TunggakanHttpTest::test_tagihan_lunas_dan_dibatalkan_tidak_muncul_di_kelompok_mana_pun` |
| 38 | Tiap kelompok menampilkan jumlah tagihan dan total rupiah | `TunggakanHttpTest::test_total_rupiah_per_kelompok_dan_ringkasan_benar` |

## Isolasi dan audit

| # | Kriteria | Tes pembukti |
|---|---|---|
| 39 | Penghuni melihat hanya tagihannya sendiri | `IsolasiDanAuditTest::test_penghuni_hanya_melihat_tagihannya_sendiri` |
| 40 | Akses tagihan milik orang lain → 404 | `IsolasiDanAuditTest::test_penghuni_mengakses_tagihan_orang_lain_mendapat_404_bukan_403` |
| 41 | Penghuni tidak bisa mengubah apa pun → 403 | `IsolasiDanAuditTest::test_penghuni_tidak_bisa_mengubah_apa_pun` |
| 42 | Perubahan status tagihan tercatat di `log_audit` | `PembayaranTest::test_setiap_pembayaran_tercatat_di_log_audit` — asersi `nilai_lama.status = belum_bayar` dan `nilai_baru.status = sebagian` |
| 43 | UPDATE/DELETE langsung ke `log_audit` ditolak basis data | `IsolasiDanAuditTest::test_update_langsung_ke_log_audit_ditolak_basis_data`, `IsolasiDanAuditTest::test_delete_langsung_ke_log_audit_ditolak_basis_data` |

## Kriteria yang sengaja tidak dicentang

Tidak ada. Keempat puluh tiga kriteria punya tes yang gagal bila perilakunya rusak.

Yang **tidak** masuk daftar ini adalah R-05 (deposit) dan proses 5.0 (laporan): keduanya
memang di luar cakupan rilis demo (SPEC §11), dan tidak pernah muncul sebagai kotak centang
di issue #1. Statusnya tetap ⏸ di `docs/uji/audit-ketertelusuran.md`.
