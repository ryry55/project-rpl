# Keputusan terbuka

> Pertanyaan yang menunggu jawaban pemilik. Dipakai oleh sesi yang **tidak punya
> sesi induk** — sesi opencode baru yang dibuka langsung di repo ini.
>
> Aturannya ada di `AGENTS.md` §"Kalau sesi butuh keputusan pemilik".
>
> **Jangan menunggu di dalam sesi.** Tulis pertanyaannya di sini, commit berkas
> ini sendirian, lalu berhenti. Tidak ada yang akan menjawab selama sesi masih
> terbuka.

## Bentuk entri

```
## K-01 · <judul singkat> · menunggu

**Fase:** R5 · **Tanggal:** 7 November 2026

**Pertanyaan.** Satu pertanyaan, dirumuskan sebagai pilihan.

**Pilihan.** A / B / C, masing-masing dengan akibatnya.

**Karena.** Mengapa ini tidak boleh diputuskan sendiri — sebutkan bagian
`SPEC.md` atau keputusan terkunci yang tersentuh.

**Kalau tidak dijawab.** Apa yang sesi lakukan: berhenti, atau kerjakan bagian
lain lebih dulu.
```

Sesudah dijawab, ubah `menunggu` menjadi `dijawab`, tambahkan tanggal dan
jawabannya di entri yang sama, lalu commit. Entri **tidak dihapus** — riwayat
keputusan ikut menjadi bukti, sama seperti `log_audit`.

---

## K-01 — Midtrans: kirim deskripsi tagihan atau hapus janji DFD — dijawab

**Fase:** audit dokumen **Tanggal:** 28 Oktober 2026

**Jawaban (28 Oktober 2026). Pilihan A.** `item_details` dikirim: `MidtransGateway::buatTransaksi()` menerima `keterangan` dan mengirimnya sebagai satu item (`price` = nominal yang diminta, `quantity` = 1, nama dipotong 50 karakter). `PembayaranGateway::mulai()` mengisinya "Sewa kamar <nomor> - <bulan tahun>". Dijaga `PembayaranGatewayTest::test_penghuni_mendapat_tautan_bayar`, yang memastikan `price x quantity` sama dengan `gross_amount`.

**Pertanyaan.** DFD aliran O-8 menjanjikan `deskripsi_tagihan` dikirim ke gerbang
pembayaran (`docs/dfd/README.md:96,209`, `docs/akademik/dfd-konteks.md:54`), tetapi
`MidtransGateway` hanya mengirim `transaction_details` + `customer_details`.
Kirim `item_details` ("Sewa kamar A1 — November 2026"), atau hapus field itu dari
DFD?

**Pilihan.**
- **A. Kirim `item_details`.** Halaman Midtrans menampilkan apa yang dibayar.
  Menyentuh payload uang: `price` harus sama dengan nominal yang diminta, kalau
  tidak Midtrans menolak transaksi. Perlu satu tes baru yang membandingkan
  keduanya.
- **B. Hapus `deskripsi_tagihan` dari O-8.** DFD berkata jujur; halaman gateway
  tetap generik. Tidak ada kode yang berubah.
- **C. Tunda.** Catat sebagai utang teknis dan tandai O-8 "sebagian" di
  `docs/dfd/README.md` §7.

**Karena.** Ini mengubah apa yang dilihat penghuni saat membayar dan menyentuh
nominal yang dikirim ke pihak ketiga — dua hal yang `AGENTS.md` larang diputuskan
sendiri.

**Kalau tidak dijawab.** O-8 ditandai "sebagian" di §7 (paling dekat ke C), tanpa
perubahan kode.

---

## K-02 — Izin menyunting `SPEC.md` — dijawab

**Fase:** audit dokumen **Tanggal:** 28 Oktober 2026

**Jawaban (28 Oktober 2026). Pilihan A.** `SPEC.md` disunting faktual: 85→86 endpoint, tujuh endpoint yang hilang ditambahkan ke §7, dan gerbang `/audit-log` diperbaiki menjadi `wewenang:baca_audit`. Tidak ada keputusan/aturan yang diubah.

**Pertanyaan.** `SPEC.md` memuat dua pernyataan yang sudah tidak benar: `:536`
"85 endpoint" (nyata 86, dan tabel §7 hanya memuat 79) dan `:597` `/audit-log`
bergerbang `peran:pemilik` (nyata `wewenang:baca_audit`, sehingga pengelola
ber-wewenang bisa membacanya). Boleh menyunting `SPEC.md`?

**Pilihan.**
- **A. Sunting faktual saja.** 85→86, tambahkan 7 endpoint yang hilang di §7,
  perbaiki gerbang `/audit-log`. Isi keputusan tidak berubah.
- **B. Jangan sentuh `SPEC.md`.** Koreksi hanya di `6_TOOLS_CONTRACTS.md` dan
  catatan penyimpangan di `docs/serah-terima.md`.
- **C. Adendum.** Biarkan badan `SPEC.md`, tambahkan bagian "Adendum audit" di
  akhirnya.

**Karena.** `SPEC.md` adalah keputusan terkunci; yang diminta izinnya adalah
koreksi fakta, bukan perubahan aturan.

**Kalau tidak dijawab.** Tidak ada yang disunting; B dipakai sebagai jalan
sementara.

---

## K-03 — `GET /api/penghuni/kontrak` masuk use case mana — dijawab

**Fase:** audit dokumen **Tanggal:** 28 Oktober 2026

**Jawaban (28 Oktober 2026). Pilihan A.** Masuk UC-13 (Portal Penghuni): `usecase-data.js` mencantumkan `penghuni.kontrak`, teks UC-13 dan catatan kaki (d) menyebut lima endpoint `/penghuni/*`, dan potret rute disegarkan sehingga klaim "86 endpoint terpakai habis" kembali benar.

**Pertanyaan.** Endpoint ini ada di kode (bukti O-1), tetapi tidak dipetakan ke
satu pun dari 14 use case. `docs/akademik/build.js` sekarang lolos hanya karena
potret rutenya basi; begitu potret disegarkan, build gagal. Masukkan ke UC-13
(portal penghuni) dan segarkan potretnya?

**Pilihan.**
- **A. Masuk UC-13, potret disegarkan.** `use-case-diagram.md` berubah: 86
  endpoint, semuanya terpakai. Satu kalimat di §2 dan matriks ikut berubah.
- **B. Biarkan di luar diagram.** `build.js` berhenti menuntut "terpakai habis"
  dan menulis "86 endpoint, 1 tidak dipetakan (portal penghuni)" — jujur, tetapi
  melemahkan klaim ketertelusuran yang justru nilai jual dokumen ini.
- **C. Gabungkan ke UC-03.** Dianggap bagian dari "menerbitkan tagihan" — lemah
  secara semantik, karena penghuni membaca kontrak, bukan menerbitkan tagihan.

**Karena.** Ini mengubah isi artefak akademik (diagram use case dan matriks aktor),
bukan sekadar angka.

**Kalau tidak dijawab.** Tidak ada regenerasi potret; temuan ini tetap terbuka.

---

## K-04 — Gerbang memeriksa rute hidup atau tetap potret — dijawab

**Fase:** audit dokumen **Tanggal:** 28 Oktober 2026

**Jawaban (28 Oktober 2026). Pilihan A.** `periksa.ps1` dan `periksa.sh` menyegarkan `docs/akademik/rute-api.json` dari `route:list` tepat sebelum "Kesesuaian dokumen"; CI memindahkan pemeriksaan itu dari job `lint` ke job `uji`, sesudah container siap, dengan langkah penyegaran yang sama. Ketiga pemeriksa tetap membaca satu berkas - sekarang berkas itu selalu baru.

**Pertanyaan.** Tiga pemeriksa (`cek-angka-dokumen.js`, `cek-cakupan-kontrak.js`,
`build.js`) membaca `docs/akademik/rute-api.json`, dan tidak ada langkah di
`periksa.ps1`, `periksa.sh`, maupun workflow yang membuat ulang potret itu. Karena
itu endpoint baru lolos tanpa ada yang memberi tahu. Mana yang dipakai?

**Pilihan.**
- **A. Bandingkan dengan rute hidup di gate.** Tambah satu langkah: `route:list`
  ke berkas sementara, lalu ketiga pemeriksa membaca hasil itu. Paling benar,
  tetapi gate dan CI jadi bergantung pada container hidup (sekarang sudah
  demikian untuk tes PHP) dan bertambah beberapa detik.
- **B. Potret tetap, tetapi dijaga umurnya.** `build.js` menolak jalan bila
  `api/routes/api.php` lebih baru dari `rute-api.json`. Murah, tetapi hanya
  menangkap kelalaian, bukan rute yang berubah di tempat lain.
- **C. Biarkan.** Catat sebagai batas yang diketahui di `7_TESTING_EVALS.md`.

**Karena.** Ini menyentuh gerbang yang dipakai setiap fase berikutnya, dan
`periksa.ps1`/`periksa.sh` harus tetap sinkron (CI memeriksa daftar skripnya).

**Kalau tidak dijawab.** Tidak ada perubahan; temuan F-01..F-04 tetap terbuka.

---

## K-05 — `GET /api/tagihan` belum berhalaman: tambah paginasi atau catat batasnya — dijawab

**Fase:** audit ulang · **Tanggal:** 29 Oktober 2026

**Jawaban (29 Oktober 2026). Pilihan C, dengan alasan pemilik: "harus bisa
diekspor".** `per_page` dihormati bila dikirim (`1..200`), dan `page` mengikuti
paginator bawaan. Tanpa `per_page`, responsnya **persis** seperti sebelumnya -
seluruh baris, `meta` yang sama, tanpa kunci halaman - sehingga layar Tagihan
dan ekspor tidak berubah. Di luar batas dijawab 422, bukan dipotong diam-diam.
Dijaga `TagihanTest::test_daftar_tagihan_berhalaman_hanya_bila_per_page_diminta`
(dua arah: tanpa parameter tidak ada kunci halaman, dengan parameter benar-benar
terpotong). Pilihan A tetap terbuka: menambah kontrol halaman di layar hanya
perlu memakai parameter yang sudah ada.

**Pertanyaan.** Uji beban 29 Oktober (30 kamar × 13 periode) menunjukkan
`GET /api/tagihan` menjawab **550 KB / 297 ms** untuk 390 tagihan, dan
`TagihanController::index()` memakai `->get()` tanpa `paginate`. Tambah
paginasi, atau biarkan dan catat sebagai batas?

**Pilihan.**
- **A. Tambah paginasi.** `per_page` bawaan 50 + kontrol halaman di layar
  Tagihan. Respons turun ke ~70 KB. Menyentuh layar yang dilihat pemilik dan
  mengubah bentuk respons API (`data` + `meta`), jadi dua hal yang dilarang
  diputuskan sendiri.
- **B. Biarkan.** Kos 5-30 kamar: 30 × 12 bulan = 360 baris, ~0,5 MB, masih
  wajar. Batasnya sudah dicatat di `7_TESTING_EVALS.md` §7.
- **C. Parameter opsional saja.** `?per_page=` dihormati bila dikirim, layar
  tidak berubah. Tidak menolong pengguna, tetapi memberi jalan keluar untuk
  ekspor dan integrasi kelak.

**Karena.** Menambah/mengurangi perilaku yang dilihat pengguna dan mengubah
bentuk respons endpoint — dua hal di tabel "wajib bertanya" `AGENTS.md`.

**Kalau tidak dijawab.** B dipakai: tidak ada kode yang berubah, batasnya tetap
tertulis di dokumen uji.

---

## K-06 — A3: tagihan terjadwal di container yang bisa tidur — ditutup

**Fase:** audit ulang · **Tanggal:** 29 Oktober 2026

**Jawaban (29 Oktober 2026). Ditutup, bukan dijawab.** Pemilik memutuskan
aplikasi **tidak di-deploy**: penilaian dan demo cukup dari laptop lewat
`docker compose`. Karena tidak ada Render dan tidak ada container yang tidur,
pertanyaan "pinger, plan berbayar, atau terbit manual" tidak punya objek lagi.
A3 ditutup di `11_AUDIT-PRODUKSI-2.md`, dan Fase 1–2, 3.1/3.2/3.4, serta 4.3
ditandai batal di `10_RENCANA-PERBAIKAN.md`. Bila pemilik berubah pikiran,
entri ini dibuka kembali **sebelum** deploy, bukan sesudah.

**Pertanyaan.** Penjadwal di dalam container hanya hidup selama container
hidup; di Render gratis ia tidur setelah tanpa lalu lintas, jadi tagihan bulanan
tidak terbit sendiri. Mana yang dipakai, dan apakah dasbor diberi penanda
"periode ini belum terbit"? Rincian pilihan ada di `11_AUDIT-PRODUKSI-2.md` §A3.

**Pilihan.**
- **A. Cron eksternal.** Layanan cron gratis memanggil endpoint terjadwal tiap
  hari. Tanpa biaya, tetapi menambah satu titik yang bisa mati diam-diam, dan
  butuh token yang disimpan di layanan pihak ketiga.
- **B. Render plan berbayar.** Container tidak tidur; penjadwal di dalamnya
  bekerja seperti di laptop. Paling sederhana secara kode, berbiaya bulanan.
- **C. Terbit manual.** Pemilik menekan "Terbitkan" tiap bulan; dasbor menandai
  periode yang belum terbit. Tanpa biaya dan tanpa titik mati, tetapi bergantung
  pada ingatan manusia — dan penanda itu layar baru yang harus disetujui.

**Karena.** Ini menyangkut uang (tagihan yang tidak terbit = denda yang tidak
jalan) dan perilaku yang dilihat pengguna (penanda dasbor).

**Kalau tidak dijawab.** Tidak ada perubahan; A3 tetap terbuka dan deploy
memakai B sementara bila plan berbayar dipilih saat mendaftar Render.

> **Ditutup 29 Oktober 2026.** Deploy dibatalkan pemilik, jadi tidak ada
> container yang bisa tidur. Jawaban lengkap ada di atas; pilihan A/B/C di
> bawah disimpan sebagai riwayat bila keputusan itu berubah.

---

## K-07 — Token mati di tengah pemakaian — dijawab

**Fase:** penutupan temuan · **Tanggal:** 30 Oktober 2026

**Jawaban (30 Oktober 2026). Perbaiki sekarang.** `sesiHabis()` dipanggil
`panggil()` dan `unduhBerkas()` pada 401 selama token masih ada: sesi
dibersihkan, peramban dimuat ulang ke `/masuk?pesan=berakhir&lanjut=…`. Dijaga
`e2e-t1.mjs`; penanganannya dimatikan → 4 asersi merah.

**Pertanyaan.** REV-UI-01, satu-satunya temuan Kritis yang terbuka: 401 di
tengah pemakaian hanya menghasilkan tulisan "Permintaan gagal (401)". Otomatis
keluar dan pindah ke layar masuk mengubah perilaku yang dilihat pengguna.

## K-08 — Sisa K-05: kendali halaman di layar Tagihan — dijawab

**Fase:** penutupan temuan · **Tanggal:** 30 Oktober 2026

**Jawaban (30 Oktober 2026). Buat kendali halaman, tetapi dipotong di layar.**
`per_page` tetap dipakai ekspor; layar memuat daftar yang sama lalu menampilkan
50 baris per halaman. Alasannya uang: pokok/denda/sisa di kepala halaman
dihitung dari `sisa` per baris yang hanya bisa dihitung PHP (denda bergantung
hari ini, pemaafan, pembatalan); meminta per halaman ke server akan mengubah
arti ketiga angka itu menjadi "halaman ini". Dijaga `e2e-t4.mjs` (52 tagihan:
50 + 2) dan uji Vitest `halaman.test.js`.

**Pertanyaan.** K-05 opsi A: tambah tombol Sebelumnya/Berikutnya di
`TagihanView.vue`.

## K-09 — Uji komponen Vue dengan Vitest — dijawab

**Fase:** penutupan temuan · **Tanggal:** 30 Oktober 2026

**Jawaban (30 Oktober 2026). Pasang.** Satu dependensi (`vitest`, tanpa jsdom
karena yang diuji fungsi murni), dijalankan `periksa.ps1`, `periksa.sh`, dan
`uji.yml`. Isinya pemformatan rupiah/tanggal dan pemotong halaman: satu asersi
salah hitung di sini jauh lebih murah dilacak daripada lewat tangkapan layar.

**Pertanyaan.** Belum ada uji komponen; Vitest berarti dependensi npm baru.

## K-10 — Ambang uji beban — dijawab

**Fase:** penutupan temuan · **Tanggal:** 30 Oktober 2026

**Jawaban (30 Oktober 2026). 50 bersamaan, 500 permintaan, p95 < 1.000 ms,
dijalankan manual.** `docs/beban/uji-beban.mjs`; tidak masuk gate karena ambang
waktu di runner bersama mengukur tetangga, bukan aplikasi. Terukur di laptop
30 Oktober: 77 permintaan/detik, p95 **840 ms**, 0 galat - lulus dengan sisa
tipis. Kontrol negatif: `AMBANG_P95=1` → `GAGAL p95 … melewati ambang 1 ms`.

**Pertanyaan.** Skrip beban tanpa ambang hanya mencetak angka; angkanya harus
dari pemilik.

## K-11 — Anotasi `docs/revisi.md` — dijawab

**Fase:** penutupan temuan · **Tanggal:** 30 Oktober 2026

**Jawaban (30 Oktober 2026). Jangan sentuh.** Berkas masukan audit, gitignored,
dan status 36 temuannya sudah punya rumah yang terlihat semua sesi:
`docs/status-revisi.md` (33 selesai, 0 selesai tanpa tes, 3 tidak berlaku,
0 terbuka).

## K-12 — Luring mendarat di `/masuk`, bukan di halaman tujuan — dijawab

**Fase:** penutupan utang aksesibilitas · **Tanggal:** 30 Oktober 2026

**Jawaban (30 Oktober 2026). Pilihan A: biarkan apa adanya, dan catat sebagai
batas yang diketahui.** Halaman tetap mendarat di `/masuk?lanjut=<tujuan>`
ketika dibuka tanpa jaringan; token tidak dihapus, jadi sesi pulih sendiri
begitu jaringan kembali. Alasan pemilik: demo dan penilaian dilakukan dari
laptop dengan jaringan, sehingga menukar satu perilaku yang terlihat pengguna
dengan manfaat yang tidak akan diuji bukanlah pertukaran yang sepadan.
Batasnya tinggal di `docs/utang-teknis.md` §6 butir 1, dan perilaku hari ini
dijaga `uji-pwa.mjs` (langkah 5) supaya tidak berubah tanpa keputusan.

**Pertanyaan.** Saat dibuka tanpa jaringan, aplikasi mendarat di
`/masuk?lanjut=<tujuan>`, bukan di halaman yang diminta: sesi dipulihkan lewat
`GET /me`, dan itu tidak bisa dijawab tanpa jaringan. Simpan identitas terakhir
ke cache supaya halaman tujuan bisa dibuka, atau biarkan?

**Pilihan.**
- **A. Biarkan.** Tidak ada kode yang berubah; batasnya dicatat. Halaman tetap
  meminta masuk lebih dulu, dan sesi pulih sendiri saat jaringan kembali.
- **B. Simpan identitas terakhir (`/me`) ke cache.** Halaman tujuan terbuka
  luring, tetapi identitas yang tersimpan di perangkat bisa basi (peran berubah,
  akun dinonaktifkan) dan aplikasi akan menampilkannya seolah berlaku.
- **C. Simpan hanya nama dan peran, tanpa hak akses.** Layar terbuka dengan
  keterangan "luring"; setiap aksi tetap ditolak sampai jaringan ada.

**Karena.** Menyentuh perilaku yang dilihat pengguna (ke mana mereka mendarat
dan apa yang mereka lihat saat jaringan mati) dan menyentuh data pribadi yang
disimpan di perangkat — dua hal yang `AGENTS.md` larang diputuskan sendiri.

**Kalau tidak dijawab.** A, yaitu keadaan hari ini; tidak ada yang berubah.

---

## K-13 — Bentuk T14: laporan kerusakan kamar — dijawab

**Fase:** T14 (#13) · **Tanggal:** 31 Oktober 2026

**Jawaban (31 Oktober 2026).**

1. **Bentuk: tabel `laporan_kerusakan` tersendiri.** Bukan kolom `catatan` di
   `kamar`. Kolom catatan hanya menyimpan satu kalimat terakhir; pertanyaan
   "sejak kapan rusak, siapa melapor, sejak kapan selesai" tidak muat di sana.
   Akibatnya angka skema berubah: 23 → **24 tabel**, 201 → **209 kolom**,
   26 → **29 kunci asing**, 32 → **33 tabel `public`** (trigger tetap 8).
2. **Kamar `perbaikan` tetap ditagih penuh.** Perbaikan biasanya singkat dan
   kontraknya masih berjalan. Tidak ada rumus baru dan tidak ada perubahan di
   `Kalkulator`.
3. **Pemilik dan pengelola boleh menutup laporan**, lewat wewenang
   `kelola_kamar` yang sudah ada. Tidak ada wewenang baru.
4. **Biaya perbaikan tidak pernah masuk tagihan penghuni**, dicatat terpisah.
   Tabelnya karena itu tidak punya kolom nominal, dan tidak ada jalur uang yang
   dibuka.

**Pertanyaan.** T14 bisa dibangun sebagai status + catatan di `kamar`, atau
sebagai tabel laporan tersendiri; kamar yang diperbaiki bisa tetap ditagih atau
tidak; dan penutupan laporan bisa dibatasi ke pemilik saja.

**Karena.** Menyentuh uang (tagihan kamar yang diperbaiki), perilaku yang dilihat
pengguna (status kamar baru, siapa boleh menutup), cakupan (tabel baru), dan
angka skema yang sudah dikunci di `SPEC.md` §4-§7.

**Kalau tidak dijawab.** T14 dikerjakan sebagai status + `catatan` (pilihan yang
tidak mengubah angka skema), dengan tagihan tetap penuh dan penutupan oleh
pemilik saja. Pilihan itu tidak dipakai.

