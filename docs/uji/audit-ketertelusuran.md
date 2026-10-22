# Audit Ketertelusuran

Peta aturan bisnis → implementasi → tes → status. **Dirawat berjalan**, diperbarui setiap tiket
selesai, dan diaudit formal sekali di akhir. Lihat `SPEC.md` §10.

Status: ✅ selesai · ⚠ sebagian · ⏳ belum · ⏸ ditunda

## R-01..R-07

| Aturan | Implementasi | Tes | Status |
|---|---|---|---|
| **R-01** prorata bulan pertama | — | — | ⏳ T3 |
| **R-02** satu tagihan per kontrak per periode | — | — | ⏳ T4 |
| **R-03** denda 1%/hari, plafon 30% | — | — | ⏳ T6 |
| **R-04** umur tunggakan | — | — | ⏳ T7 |
| **R-05** deposit hanya untuk kerusakan | kolom `kontrak.deposit` sudah ada dan terisi | — | ⏸ ditunda (T8) |
| **R-06** tarif tidak berlaku surut — **lapis 1** (riwayat) | `tarif_sewa`, `komponen_biaya`, `parameter_denda` append-only + trigger `BEFORE UPDATE OR DELETE`; `App\Domain\Tarif\PencariTarif` memilih baris dengan `berlaku_dari` terbesar ≤ awal periode | `RiwayatTarifTest` (16 tes): `test_mengubah_tarif_menambah_baris_dan_baris_lama_tetap`, `test_tarif_efektif_adalah_baris_terakhir_…`, `test_update_langsung_ke_tarif_sewa_ditolak_database`, `test_delete_langsung_ke_tarif_sewa_ditolak_database`, `test_update_langsung_ke_parameter_denda_ditolak_database`, `test_update_langsung_ke_komponen_biaya_ditolak_database` | ✅ lapis 1 |
| **R-06** tarif tidak berlaku surut — **lapis 2** (snapshot di tagihan) | — | — | ⏳ T3 |
| **R-07** isolasi penghuni + log audit | isolasi belum; `PastikanPeran` sudah menutup data induk untuk peran penghuni | `test_penghuni_tidak_boleh_menyentuh_data_induk` | ⚠ sebagian (T9) |

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

## Cara menjalankan ulang bukti

```bash
docker compose up -d
docker compose exec api php artisan test          # 46 tes
node docs/uji/e2e-t1.mjs                          # perlu Chrome --remote-debugging-port=9222
node docs/uji/e2e-t2.mjs                          # 8 kamar + 6 penghuni + kontrak lewat UI
```

`e2e-t2.mjs` mengubah database demo (menambah kamar, penghuni, kontrak). Untuk mengulang dari
keadaan bersih: `docker compose exec api php artisan migrate:fresh --seed`.

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
