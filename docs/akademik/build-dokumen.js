#!/usr/bin/env node
'use strict';

/**
 * Generator dokumen akademik — Sistem Informasi Operasional Kos-Kosan
 * -------------------------------------------------------------------
 * Jalankan:  node docs/akademik/build-dokumen.js
 *
 * Menghasilkan tiga berkas, seluruhnya dari data — tidak ada tabel yang
 * diketik tangan:
 *
 *     Proses_Bisnis_Kos_Kosan.md   laporan 7 bab (template dosen)
 *     use-case-diagram.md          diagram + daftar UC + matriks akses
 *     dfd-konteks.md               diagram + matriks 14 aliran batas
 *
 * Sumbernya:
 *     usecase-data.js       14 use case, 3 aktor, matriks, catatan kaki
 *     dokumen-data.js       entitas data utama, aturan bisnis
 *     rute-api.json         potret route:list (80 endpoint)
 *     tabel-domain.json     potret pg_tables (23 tabel domain)
 *     ../dfd/dfd-data.js    entitas eksternal, aliran batas, aturan R-01..R-07
 *
 * Kenapa seluruh dokumen dibangkitkan, bukan hanya tabelnya. Dokumen akademik
 * yang ditulis tangan akan menyimpang dari sistem begitu satu endpoint
 * ditambah, dan penyimpangan itu tidak terlihat sampai sidang. Dengan
 * membangkitkan seluruhnya, satu-satunya cara mengubah dokumen adalah
 * mengubah sistem atau data di atas — dan keduanya bisa diperiksa.
 *
 * Build GAGAL bila: jumlah UC bukan 14, ada endpoint yang tidak terpakai atau
 * terpakai dua kali, keanggotaan aktor tidak cocok dengan middleware, ada
 * entitas yang menunjuk tabel hantu, atau ada aturan bisnis yang tidak ada di
 * DFD. Dokumen yang gagal tidak ditulis sama sekali.
 */

const fs = require('fs');
const path = require('path');

const U = require('./usecase-data');
const G = require('./usecase-gambar');
const { ENTITAS_DATA, ATURAN_BISNIS } = require('./dokumen-data');
const DFD = require('../dfd/dfd-data');

const OUT = __dirname;
const RUTE = JSON.parse(fs.readFileSync(path.join(OUT, 'rute-api.json'), 'utf8'));
const TABEL = JSON.parse(fs.readFileSync(path.join(OUT, 'tabel-domain.json'), 'utf8'));

/**
 * Versi Laravel dibaca dari `composer.lock`, bukan diketik.
 *
 * Ini pernah menjadi cacat: `docs/utang-teknis.md` §5 mencatat dua dokumen
 * yang masih menyebut Laravel 11. Mengetik ulang versi di dokumen ketiga akan
 * mengulang kesalahan yang sama, jadi angkanya diambil dari tempat yang
 * benar-benar menentukan versi.
 */
const LARAVEL = (() => {
  const lock = JSON.parse(fs.readFileSync(path.join(OUT, '..', '..', 'api', 'composer.lock'), 'utf8'));
  const paket = lock.packages.find((p) => p.name === 'laravel/framework');
  return paket ? paket.version.replace(/^v/, '') : '(tidak terbaca)';
})();

/**
 * Jumlah migrasi dihitung dari direktori, bukan diketik.
 *
 * Angka ini pernah tertinggal di 36 sementara basis data sudah 39, dan yang
 * membacanya adalah dosen yang membandingkan laporan dengan `migrations`.
 * Menghitung dari berkas membuatnya mustahil salah tanpa menghapus berkas.
 */
const MIGRASI = fs
  .readdirSync(path.join(OUT, '..', '..', 'api', 'database', 'migrations'))
  .filter((f) => f.endsWith('.php')).length;

/* ------------------------------------------------------------- bantuan */

const daftarDan = (arr) =>
  arr.length <= 1
    ? arr.join('')
    : arr.length === 2
      ? `${arr[0]} dan ${arr[1]}`
      : `${arr.slice(0, -1).join(', ')}, dan ${arr[arr.length - 1]}`;

/**
 * Amankan isi sel tabel Markdown.
 *
 * Pipa `|` di dalam teks akan memotong sel menjadi dua kolom, dan tabelnya
 * rusak — termasuk pipa yang berada di dalam backtick, karena GFM memecah
 * baris lebih dulu baru memproses penanda. Daftar nilai enum seperti
 * `belum_bayar | sebagian | lunas` karena itu harus di-escape.
 */
const sel = (s) => String(s).replace(/\|/g, '\\|');

/**
 * Lipat teks pada lebar tertentu tanpa memutus penanda Markdown.
 *
 * Baris hasil lipatan sengaja tidak diberi indentasi: di Markdown, baris yang
 * menjorok empat spasi berubah menjadi blok kode, dan paragraf yang dilipat
 * dengan indentasi akan tampil sebagai kode di dokumen jadi. Indentasi hanya
 * diberikan bila diminta eksplisit untuk lanjutan butir bernomor.
 */
function lipat(teks, indentasi = '', lebar = 78) {
  const kata = String(teks).split(' ');
  const baris = [];
  let kini = '';
  for (const k of kata) {
    if (kini && (kini + ' ' + k).length > lebar - indentasi.length) {
      baris.push(kini);
      kini = k;
    } else {
      kini = kini ? kini + ' ' + k : k;
    }
  }
  if (kini) baris.push(kini);
  return baris.map((b, i) => (i === 0 ? b : indentasi + b)).join('\n');
}

const namaAktor = Object.fromEntries(U.AKTOR.map((a) => [a.kunci, a.nama]));

const ucDari = (kunci) => U.MATRIKS.find((m) => m.aktor === kunci).uc;

/** Endpoint sebuah use case, diurutkan seperti di berkas data. */
const endpointUC = (kode) => U.USE_CASE.find((u) => u.kode === kode).endpoint;

const jumlahEndpoint = U.USE_CASE.reduce((n, u) => n + u.endpoint.length, 0);

/**
 * Normalisasi nama entitas pada matriks aliran: `PEMILIK KOS` → `Pemilik Kos`,
 * `Proses 1.0` tetap. Dipakai bab 5 supaya bentuknya `Entitas -> Sistem`.
 */
const judul = (s) =>
  s
    .toLowerCase()
    .split(' ')
    .map((k) => (k ? k[0].toUpperCase() + k.slice(1) : k))
    .join(' ');

const namaEntitas = Object.fromEntries(DFD.ENTITAS.map((e) => [e.id, judul(e.nama)]));
const idProses = Object.fromEntries(DFD.PROSES.map((p) => [p.kunci, p.id]));

const aliranBatas = DFD.ALIRAN.filter((a) => a.jenis !== 'internal');
const aliranMasuk = aliranBatas.filter((a) => a.jenis === 'masuk');
const aliranKeluar = aliranBatas.filter((a) => a.jenis === 'keluar');

/* ------------------------------------------------------------- periksa */

function periksaSemua() {
  const masalah = [...G.periksaData(RUTE)];

  // Entitas data harus menunjuk tabel yang benar-benar ada.
  const tabelAda = new Set(TABEL);
  for (const e of ENTITAS_DATA) {
    for (const t of e.tabel) {
      if (!tabelAda.has(t)) masalah.push(`entitas "${e.nama}" menunjuk tabel hantu: ${t}`);
    }
  }

  // Setiap tabel domain harus dijelaskan minimal satu entitas. Tabel yang
  // tidak disebut berarti kolom di laporan tidak lengkap, dan pembaca yang
  // membandingkan dengan basis data akan menemukannya sendiri.
  const disebut = new Set(ENTITAS_DATA.flatMap((e) => e.tabel));
  for (const t of TABEL) {
    if (!disebut.has(t)) masalah.push(`tabel ${t} tidak dijelaskan entitas mana pun`);
  }

  // Aturan bisnis harus sama persis dengan yang dijaga DFD.
  const kodeDfd = new Set(DFD.ATURAN.map((a) => a.kode));
  const kodeDok = new Set(ATURAN_BISNIS.map((a) => a.kode));
  for (const k of kodeDfd) {
    if (!kodeDok.has(k)) masalah.push(`aturan ${k} ada di DFD tetapi tidak di dokumen`);
  }
  for (const k of kodeDok) {
    if (!kodeDfd.has(k)) masalah.push(`aturan ${k} ada di dokumen tetapi tidak di DFD`);
  }

  if (jumlahEndpoint !== RUTE.length) {
    masalah.push(`endpoint terpakai ${jumlahEndpoint}, potret route:list ${RUTE.length}`);
  }

  // Angka ini PAGAR, bukan salinan templat. Semula 7 dan 7 karena DFD memang
  // punya tujuh aliran tiap arah. Setelah gerbang pembayaran (E4) dimodelkan,
  // angkanya menjadi 8 dan 8 — bukan karena templat berubah, tetapi karena
  // diagramnya kini memuat satu aktor yang sebelumnya menulis ke basis data
  // tanpa pernah digambar. Pagar tetap dipertahankan supaya perubahan
  // berikutnya harus diputuskan, bukan terjadi.
  if (aliranMasuk.length !== 8 || aliranKeluar.length !== 8) {
    masalah.push(
      `aliran batas ${aliranMasuk.length} masuk / ${aliranKeluar.length} keluar, ` +
        'kesepakatan dokumen menyebut 8 dan 8'
    );
  }

  return masalah;
}

/* ------------------------------------------------------- bab 1 sampai 3 */

function bab1() {
  // Delapan nama wewenang sengaja TIDAK didaftarkan di sini. Daftar itu sudah
  // muncul utuh di UC-12 dan di `SPEC.md`; mengulangnya di ringkasan memakan
  // seperempat bab 1 untuk informasi yang pembaca temui lagi dua bab kemudian.
  const isi = [
    `**Sistem Informasi Operasional Kos-Kosan** mengelola operasional kos — kamar, penghuni, kontrak sewa, tarif dan kebijakan, tagihan bulanan, pembayaran, sampai laporan pendapatan dan tunggakan — sebagai API Laravel ${LARAVEL} + PostgreSQL dengan antarmuka Vue 3.`,
    `Pembedanya dari pencatatan manual bukan kelengkapan fitur, melainkan **siapa yang berhak melakukan apa**: **pemilik** memutuskan, **pengelola** berwenang terbatas mengusulkan, **penghuni** hanya membaca. Pengelola ber-wewenang \`ubah_tarif\` pun tidak menetapkan tarif. Uang dicatat sebagai peristiwa yang tidak bisa diubah. Cakupan: **${jumlahEndpoint} endpoint** \`/api\`, **${U.USE_CASE.length} use case**, **${U.AKTOR.length} aktor**, **${TABEL.length} tabel domain** dari **${MIGRASI} migrasi**.`,
  ];
  return `## 1. Ringkasan Eksekutif Sistem

${isi.map((l) => lipat(l)).join('\n\n')}`;
}

function bab2() {
  const baris = U.AKTOR.map((a, i) => `${i + 1}. **${judul(a.nama)}:** ${lipat(a.peranSingkat, '   ')}`);
  return `## 2. Aktor dan Entitas Eksternal Sistem

${lipat(
    `**${U.AKTOR.length} aktor**, dan ketiganya juga entitas eksternal pada DFD Konteks. Semua masuk lewat \`POST /api/login\`; pembedanya gerbang per endpoint — \`peran:\` memeriksa jenis akun, \`wewenang:\` memeriksa izin yang didelegasikan.`
  )}

${baris.join('\n')}`;
}

function bab3() {
  const langkah = [
    `**Registrasi dan autentikasi.** Ketiga peran masuk lewat \`POST /api/login\`; akun dibuat pemilik atau pengelola lalu diaktifkan lewat surel, dan sebelum aktivasi login ditolak 403.`,
    `**Penyiapan data induk.** Pemilik atau pengelola ber-wewenang \`kelola_kamar\` dan \`kelola_penghuni\` mendaftarkan kamar, fasilitas, aturan kos, dan identitas penghuni.`,
    `**Penetapan angka uang.** Pemilik menetapkan tarif, komponen biaya, dan tiga dimensi kebijakan. Bila pengelola ber-wewenang \`ubah_tarif\` yang menekan tombol, sistem menyimpan **usulan** dan membalas 202.`,
    `**Pembuatan kontrak sewa.** Kontrak menunjuk satu kamar kosong dan satu penghuni; indeks parsial menolak kontrak aktif kedua pada kamar yang sama.`,
    `**Penerbitan tagihan bulanan.** Sistem menghitung sewa prorata (R-01), membekukan tarif dan kebijakan (R-06), lalu menolak bila periode itu sudah punya tagihan hidup (R-02) atau kebijakan belum lengkap (R-05).`,
    `**Pembayaran dan verifikasi.** Uang yang diterima dicatat beserta tanggal, metode, dan penerimanya; status tagihan menjadi \`sebagian\` atau \`lunas\`. Akun yang mencatat tidak boleh memverifikasi catatannya sendiri.`,
    `**Pemantauan dan pelaporan.** Pemilik membaca rekap pendapatan; pengelola membaca tunggakan menurut umur piutang (R-04) dan, bila punya \`baca_audit\`, jejak audit. Penghuni melihat tagihannya sendiri (R-07).`,
    `**Penutupan periode.** Tagihan salah terbit dibatalkan beralasan sehingga periodenya bebas diterbitkan ulang; denda yang dilepas pemilik dicatat sebagai pemaafan.`,
  ];
  return `## 3. Alur Transaksi Utama

Satu siklus bulanan; setiap langkah menunjuk endpoint nyata pada \`api/routes/api.php\`.

${langkah.map((l, i) => `${i + 1}. ${lipat(l, '   ')}`).join('\n')}`;
}

/* ------------------------------------------------------------ bab 4 */

function bab4() {
  const daftar = U.USE_CASE.map(
    (u, i) => `${i + 1}. **${u.kode} ${u.nama}:** ${u.deskripsi}`
  );
  const matriks = U.AKTOR.map((a, i) => {
    const kode = ucDari(a.kunci);
    return `${i + 1}. **${judul(a.nama)}:** Mengakses ${daftarDan(kode)}.`;
  });

  const catatan = U.CATATAN_MATRIKS.map((c) => `(${c.kode}) ${c.uc}`);
  const empat = catatan.slice(0, -1).join(', ') + ', dan ' + catatan[catatan.length - 1];
  return `## 4. Pemodelan Berorientasi Objek (UML Use Case Diagram)

${lipat(
    `Batas sistem mencakup seluruh fungsionalitas aplikasi operasional kos: **${U.AKTOR.length} aktor**, **${U.USE_CASE.length} use case** — jumlah final, diturunkan dari ${jumlahEndpoint} endpoint nyata yang terpetakan habis tanpa sisa dan tanpa satu pun dipakai dua use case.`
  )}

### 4.1 Deskripsi ${U.USE_CASE.length} Use Cases Fungsional

${daftar.map((d) => lipat(d, '   ')).join('\n')}

### 4.2 Matriks Hak Akses Aktor terhadap Use Case

${matriks.join('\n')}

Empat sel lebih sempit daripada perannya — ${empat} — dan tidak dirinci di sini;
alasannya ada di \`use-case-diagram.md\` §4.`;
}

/* ------------------------------------------------------------ bab 5 */

function bab5() {
  // Struktur data per aliran sengaja TIDAK dicetak di sini. Template dosen
  // meminta "rincian" dalam bentuk frasa ("data registrasi akun, kredensial
  // login, ..."), bukan daftar kolom; daftar kolom lengkapnya sudah ada di
  // `dfd-konteks.md` §3 dan di kamus data `docs/dfd/README.md`. Mencetaknya
  // dua kali menambah ~140 kata untuk pembaca yang sama.
  const masuk = aliranMasuk.map(
    (a, i) => `${i + 1}. **${namaEntitas[a.dari]} -> Sistem:** ${[].concat(a.label).join(' ')}`
  );
  const keluar = aliranKeluar.map(
    (a, i) => `${i + 1}. **Sistem -> ${namaEntitas[a.ke]}:** ${[].concat(a.label).join(' ')}`
  );

  return `## 5. Pemodelan Terstruktur (Data Flow Diagram / DFD Konteks)

${lipat(
    `Proses sentral **0.0 Sistem Informasi Operasional Kos-Kosan** menghubungkan **${DFD.ENTITAS.length} entitas eksternal** melalui **${aliranBatas.length} aliran data utama (${aliranMasuk.length} masuk dan ${aliranKeluar.length} keluar)**. Gambarnya di \`dfd-konteks.md\`, dibaca dari \`docs/dfd/dfd-data.js\` — sumber tunggal yang sama dengan DFD teknis.`
  )}

### 5.1 Matriks ${aliranBatas.length} Aliran Data Utama (Input & Output)

**A. Aliran Data Masuk (Input ke Proses Sentral 0.0):**

${masuk.map((m) => lipat(m, '   ')).join('\n')}

**B. Aliran Data Keluar (Output dari Proses Sentral 0.0):**

${keluar.map((k) => lipat(k, '   ')).join('\n')}

Penghuni sengaja tanpa aliran masuk data (portalnya hampir seluruhnya
baca-saja; bayar daring tidak membawa data, uangnya tercatat dari webhook), dan
pemilik dipisahkan
dari pengelola karena pengelola mengusulkan (I-6) sementara pemilik memutuskan
(I-3) — alasan lengkapnya di \`dfd-konteks.md\`.`;
}

/* ------------------------------------------------------------ bab 6 */

function bab6() {
  // `e.lingkup` boleh kosong: template dosen hanya meminta daftar atribut.
  // `e.tabel` tidak dicetak, tetapi tetap diperiksa `periksaSemua()` terhadap
  // `tabel-domain.json`, sehingga entitas yang menunjuk tabel hantu tetap
  // menggagalkan build.
  const baris = ENTITAS_DATA.map((e) => {
    const isi = e.lingkup ? `${sel(e.atribut)}. ${sel(e.lingkup)}` : sel(e.atribut);
    return `| ${e.nama} | ${isi} |`;
  });

  return `## 6. Entitas Data Utama

${lipat(
    `${TABEL.length} tabel domain dikelompokkan menjadi ${ENTITAS_DATA.length} entitas logis: pembaca laporan mengenal "kontrak", bukan tabel penghubung.`
  )}

| Entitas | Atribut Data Utama & Ruang Lingkup |
|---|---|
${baris.join('\n')}`;
}

/* ------------------------------------------------------------ bab 7 */

function bab7() {
  const daftar = ATURAN_BISNIS.map(
    (a, i) => `${i + 1}. ${lipat(`**${a.judul} (${a.kode}).** ${a.isi}`, '   ')}`
  );
  return `## 7. Aturan Bisnis Pokok Sistem

${lipat(
    'Tujuh aturan berikut menahan sistem ini tetap benar ketika dipakai. Masing-masing punya proses penjaga di DFD dan pengujian yang menyebut nomornya.'
  )}

${daftar.join('\n')}`;
}

/* ------------------------------------------------- dokumen narasi utuh */

function dokumenNarasi() {
  const kepala = `# Proses Bisnis & Spesifikasi Pemodelan Sistem Informasi Operasional Kos-Kosan

> **Dibangkitkan, jangan disunting tangan** — \`node docs/akademik/build-dokumen.js\`.
> Angka dibaca dari \`rute-api.json\` (${RUTE.length} endpoint), \`tabel-domain.json\` (${TABEL.length} tabel),
> \`usecase-data.js\` (${U.USE_CASE.length} use case), \`api/composer.lock\` (Laravel ${LARAVEL}),
> dan \`docs/dfd/dfd-data.js\` (${aliranBatas.length} aliran batas).
`;

  const bab = [bab1(), bab2(), bab3(), bab4(), bab5(), bab6(), bab7()];

  const kaki = `---

*Regenerasi: \`usecase-data.js\`, \`dokumen-data.js\`, \`build.js\`, \`build-dokumen.js\`.
Diagram: \`01-use-case.svg\`, \`02-dfd-konteks.svg\` (dan PNG 2x-nya).*`;

  return [kepala, ...bab, kaki].join('\n\n');
}

/* ---------------------------------------------------- use-case-diagram */

function dokumenUseCase() {
  const tabelUC = U.USE_CASE.map((u) => {
    const kode = u.endpoint.map((e) => `\`${e}\``).join('<br>');
    return `| **${u.kode}** | ${sel(u.nama)} | ${kode} |`;
  });

  // Satu baris per aktor. `tanda` sengaja bukan `sel` — nama itu sudah dipakai
  // fungsi pengaman pipa di atas, dan menutupinya di sini membuat pemanggilan
  // sel(...) di dalam blok yang sama menunjuk array, bukan fungsi.
  const matriks = U.AKTOR.map((a) => {
    const dimiliki = new Set(ucDari(a.kunci));
    const tanda = U.USE_CASE.map((u) => {
      if (!dimiliki.has(u.kode)) return '—';
      const kaki = U.CATATAN_MATRIKS.filter((c) => c.uc === u.kode).map((c) => c.kode);
      return kaki.length ? `✓ (${kaki.join(',')})` : '✓';
    });
    return `| **${judul(a.nama)}** | ${tanda.join(' | ')} |`;
  });

  const kepalaTabel = `| Aktor | ${U.USE_CASE.map((u) => u.kode.replace('UC-', '')).join(' | ')} |`;
  const pemisahTabel = `|---|${U.USE_CASE.map(() => '---|').join('')}`;

  const catatan = U.CATATAN_MATRIKS.map((c) => `- **(${c.kode}) ${c.uc}** — ${c.teks}`);

  return `# Diagram Use Case — Sistem Informasi Operasional Kos-Kosan

> **Dibangkitkan, jangan disunting tangan.** Jalankan
> \`node docs/akademik/build.js && node docs/akademik/build-dokumen.js\`.
> Sumber: \`usecase-data.js\` (semantik) dan \`usecase-gambar.js\` (gambar).

## 1. Diagram

![Diagram Use Case](01-use-case.png)

Berkas: \`01-use-case.svg\` (vektor) dan \`01-use-case.png\` (2x, siap tempel).

## 2. Aktor

${U.AKTOR.map((a, i) => `${i + 1}. **${judul(a.nama)}** (\`${a.kunci}\`) — ${lipat(a.peran, '   ')}`).join('\n\n')}

## 3. Daftar ${U.USE_CASE.length} Use Case dan Endpoint Pendukungnya

${lipat(
    `Setiap use case menunjuk minimal satu endpoint nyata. Pemetaannya **tertutup**: ${jumlahEndpoint} endpoint pada \`api/routes/api.php\` terpakai habis, dan tidak ada satu pun yang dipakai dua use case. \`build.js\` memeriksa ketiga hal itu dan gagal bila tidak terpenuhi.`
  )}

| Kode | Use Case | Endpoint (nama rute) |
|---|---|---|
${tabelUC.join('\n')}

## 4. Matriks Hak Akses Aktor

${kepalaTabel}
${pemisahTabel}
${matriks.join('\n')}

${lipat(
    `Angka pada matriks **diturunkan dari middleware**, bukan diketik bebas: \`build.js\` membaca gerbang setiap endpoint (\`peran:\` dan \`wewenang:\`) lalu membandingkannya dengan tabel di atas. Aktor yang tercantum padahal tidak bisa memanggil satu pun endpoint bergerbang milik use case itu menggagalkan build, dan begitu pula sebaliknya.`
  )}

### Catatan kaki

${catatan.map((c) => lipat(c, '  ')).join('\n\n')}

## 5. Mengapa Ada Use Case Berupa Gabungan

${U.USE_CASE.filter((u) => u.gabungan)
    .map((u) => `- **${u.kode}.** ${lipat(u.gabungan, '  ').replace(/^\s+/, '')}`)
    .join('\n\n')}

${lipat(
    'Memecahnya menjadi use case tersendiri akan menghasilkan kotak yang hanya berbeda pada nama endpoint, dan diagram yang memetakan endpoint satu per satu berhenti menjelaskan pekerjaan penggunanya. Ini keputusan pemilik, bukan kelalaian: jumlah 14 sudah final.'
  )}

## 6. Cara Membangun Ulang

\`\`\`powershell
node docs/akademik/build.js          # 01-use-case.svg + 02-dfd-konteks.svg
node docs/akademik/build-dokumen.js  # tiga berkas .md
powershell -ExecutionPolicy Bypass -File docs/akademik/export-png.ps1   # PNG 2x
\`\`\`

Ekspor PNG memakai Chrome/Edge headless, pola yang sama dengan
\`docs/dfd/export-png.ps1\`. Ukuran jendela harus sama dengan \`TATA_LETAK.kanvas\`
di \`usecase-data.js\`, kalau tidak gambar terpotong.`;
}

/* ------------------------------------------------------- dfd-konteks */

function dokumenDfd() {
  const tabelMasuk = aliranMasuk.map(
    (a) =>
      `| **${a.kode}** | ${namaEntitas[a.dari]} | Proses ${idProses[a.ke]} | ${[].concat(a.label).join(' ')} | \`${a.struktur}\` |`
  );
  const tabelKeluar = aliranKeluar.map(
    (a) =>
      `| **${a.kode}** | Proses ${idProses[a.dari]} | ${namaEntitas[a.ke]} | ${[].concat(a.label).join(' ')} | \`${a.struktur}\` |`
  );

  return `# DFD Konteks (Level 0) — Sistem Informasi Operasional Kos-Kosan

> **Dibangkitkan, jangan disunting tangan.** Jalankan
> \`node docs/akademik/build.js && node docs/akademik/build-dokumen.js\`.
>
> Gambar ini **tidak menggambar ulang** DFD proyek: ia memanggil
> \`docs/dfd/dfd-gambar.js\` dengan data dari \`docs/dfd/dfd-data.js\`. DFD
> teknis (\`docs/dfd/\`) tetap satu-satunya sumber; berkas ini hanya menyajikan
> ulang level 0 dalam format yang diminta template dosen.

## 1. Diagram

![DFD Konteks Level 0](02-dfd-konteks.png)

Berkas: \`02-dfd-konteks.svg\` (vektor) dan \`02-dfd-konteks.png\` (2x).

Notasi **Gane-Sarson**: entitas eksternal sebagai kotak, proses sebagai persegi
sudut tumpul dengan pita nomor, aliran data sebagai panah berlabel. Versi
Yourdon/DeMarco untuk gambar yang sama ada di \`docs/dfd/01-dfd-konteks-yourdon.svg\`.

## 2. Proses Sentral

Proses tunggal **0.0 Sistem Informasi Operasional Kos-Kosan** mewakili seluruh
sistem sebagai satu simpul. Pada level ini belum ada penyimpanan data — D1–D6
muncul di level 1, dan jumlah aliran batas di kedua level sengaja sama
(${aliranBatas.length}) supaya tidak ada aliran yang hilang saat dekomposisi.

## 3. Matriks ${aliranBatas.length} Aliran Data Utama

### A. Aliran Data Masuk (Input ke Proses Sentral 0.0)

| Kode | Dari | Ke | Nama aliran | Struktur data |
|---|---|---|---|---|
${tabelMasuk.join('\n')}

### B. Aliran Data Keluar (Output dari Proses Sentral 0.0)

| Kode | Dari | Ke | Nama aliran | Struktur data |
|---|---|---|---|---|
${tabelKeluar.join('\n')}

Jumlahnya **${aliranMasuk.length} masuk dan ${aliranKeluar.length} keluar**.
Angka itu diperiksa \`build.js\` setiap kali gambar dibangun; bila data DFD
berubah dan jumlahnya tidak lagi ${aliranBatas.length}, build gagal dan
dokumen ini tidak ditulis.

## 4. Mengapa Bentuknya Begini

**Tiga entitas manusia, bukan dua.** Pemilik dan pengelola dipisahkan karena
perbedaannya mengubah aliran data secara nyata: pengelola mengusulkan (I-6) dan
pemilik memutuskan (I-3). Menggabungkan keduanya hanya menyederhanakan gambar,
sementara kenyataan sistemnya tetap punya dua peran dengan wewenang berbeda.

**Entitas keempat: gerbang pembayaran.** Diagram ini semula hanya punya tiga
entitas dan gerbang pembayaran tidak digambar sama sekali. Itu cacat, bukan
penyederhanaan: \`POST /webhook/midtrans\` menulis baris \`pembayaran\` dari luar
batas sistem tanpa ada manusia menekan tombol, sehingga aliran I-8 muncul dari
ruang kosong. Sebaliknya \`POST /tagihan/{id}/bayar-daring\` mengirim permintaan
keluar ke Midtrans (O-8). Bedanya dengan penghuni: penghuni hanya **menerima**
data, sedangkan gerbang pembayaran justru satu-satunya pihak luar yang
**memasok** data uang — karena itu tanda tangannya diverifikasi dan
\`gross_amount\`-nya dicocokkan dengan nilai yang dibekukan di
\`transaksi_gateway\` sebelum baris pembayaran dibuat.

**Penghuni tanpa aliran masuk data.** Portal penghuni hampir seluruhnya
baca-saja. Pendaftaran sewa dan pencatatan uang dilakukan pemilik atau
pengelola, jadi tidak ada data yang mengalir *dari* penghuni ke sistem. Satu
tindakan ia mulai sendiri — membuka tautan bayar daring — tidak membawa data:
uangnya baru tercatat setelah gerbang pembayaran menyatakan settlement lewat
webhook, dan webhook itu bukan penghuni. Aliran masuk dari penghuni akan
menggambarkan fitur yang tidak ada.

**Dua aliran keluar ke pemilik dari proses 5.0.** Rekap pendapatan (O-6) dan
daftar tunggakan serta log audit (O-7) dipisah supaya jumlah aliran di level 0
dan level 1 seimbang, dan supaya log audit punya jalan keluar — tanpa O-7,
penyimpanan D6 menjadi *black hole* yang ditulis tiga proses tetapi tidak
pernah dibaca.

## 5. Rincian Lanjutan

| Dokumen | Isi |
|---|---|
| \`docs/dfd/README.md\` | Kamus data, tabel aliran lengkap, alasan tiap proses |
| \`docs/dfd/02-dfd-level-1.svg\` | Dekomposisi menjadi proses 1.0–5.0 dan D1–D6 |
| \`docs/dfd/03-dfd-level-2.md\` | Dekomposisi proses 3.0 (penagihan) dan 4.0 (pembayaran) |`;
}

/* --------------------------------------------------------------- tulis */

const masalah = periksaSemua();

if (masalah.length) {
  console.error(`${masalah.length} masalah — tidak ada berkas yang ditulis:`);
  for (const m of masalah) console.error('  -', m);
  process.exitCode = 1;
} else {
  const berkas = [
    ['Proses_Bisnis_Kos_Kosan.md', dokumenNarasi()],
    ['use-case-diagram.md', dokumenUseCase()],
    ['dfd-konteks.md', dokumenDfd()],
  ];
  for (const [nama, isi] of berkas) {
    fs.writeFileSync(path.join(OUT, nama), isi + '\n', 'utf8');
    const baris = isi.split('\n').length;
    console.log(`ditulis: ${nama} (${baris} baris, ${isi.length} byte)`);
  }
  console.log(
    `  ${U.USE_CASE.length} use case · ${U.AKTOR.length} aktor · ${jumlahEndpoint} endpoint · ` +
      `${DFD.ENTITAS.length} entitas eksternal · ${aliranBatas.length} aliran batas · ` +
      `${ENTITAS_DATA.length} entitas data (${TABEL.length} tabel) · ${ATURAN_BISNIS.length} aturan`
  );
}
