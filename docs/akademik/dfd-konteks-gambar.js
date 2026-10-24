'use strict';

/**
 * Penyaji ulang DFD Konteks untuk dokumen akademik
 * ------------------------------------------------
 * Berkas ini TIDAK menyimpan data apa pun. Ia membaca `docs/dfd/dfd-data.js`
 * — sumber tunggal entitas, aliran, dan tata letak — lalu meminta
 * `docs/dfd/dfd-gambar.js` menggambarnya dalam notasi Gane-Sarson.
 *
 * Kenapa tidak disalin. DFD konteks sudah ada, sudah diperiksa, dan sudah
 * dipakai README `docs/dfd/`. Menyalin 14 aliran ke berkas baru berarti ada
 * dua daftar aliran yang harus dijaga tetap sama; cepat atau lambat salah
 * satunya tertinggal, dan pembaca tidak punya cara tahu mana yang benar.
 * Dokumen akademik karena itu menumpang pada sumber yang sama persis dengan
 * dokumen teknis — bukan salinannya.
 *
 * Yang ditambahkan hanyalah pemeriksaan tambahan yang relevan untuk dokumen
 * ini: tiga entitas, tujuh aliran masuk, tujuh aliran keluar, setiap aliran
 * menunjuk entitas yang benar-benar ada, dan setiap aliran punya label serta
 * struktur data — sebab keduanya dikutip apa adanya ke dalam matriks aliran
 * di dokumen narasi. Aliran tanpa struktur akan menjadi baris kosong di
 * tabel, dan baris kosong terbaca seperti aliran yang tidak pernah dianalisis.
 *
 * Perhatikan yang TIDAK diperiksa: "setiap entitas harus punya aliran masuk".
 * Pemeriksaan itu wajar untuk DFD kebanyakan, tetapi salah untuk sistem ini.
 * PENGHUNI sengaja tidak punya aliran masuk sama sekali — portalnya baca-saja,
 * pendaftaran sewa dan pencatatan uang dilakukan pemilik atau pengelola
 * (`docs/dfd/dfd-data.js`, `peran` E3). Menuntut aliran masuk dari penghuni
 * berarti menuntut fitur yang memang tidak ada, dan pemeriksa yang menuntut
 * hal salah akan "diperbaiki" dengan mengarang aliran.
 */

const D = require('../dfd/dfd-data');
const { gambarKonteks } = require('../dfd/dfd-gambar');

const HARAP_ENTITAS = 3;
const HARAP_MASUK = 7;
const HARAP_KELUAR = 7;

function periksaKonteks() {
  const masalah = [];
  const batas = D.ALIRAN.filter((a) => a.jenis !== 'internal');
  const masuk = batas.filter((a) => a.jenis === 'masuk');
  const keluar = batas.filter((a) => a.jenis === 'keluar');

  if (D.ENTITAS.length !== HARAP_ENTITAS) {
    masalah.push(`entitas eksternal ${D.ENTITAS.length}, seharusnya ${HARAP_ENTITAS}`);
  }
  if (masuk.length !== HARAP_MASUK) {
    masalah.push(`aliran masuk ${masuk.length}, seharusnya ${HARAP_MASUK}`);
  }
  if (keluar.length !== HARAP_KELUAR) {
    masalah.push(`aliran keluar ${keluar.length}, seharusnya ${HARAP_KELUAR}`);
  }

  // Setiap entitas wajib muncul pada minimal satu aliran batas. Entitas yang
  // tidak muncul sama sekali adalah entitas yang digambar tetapi tidak
  // berhubungan dengan sistem — kotak hiasan. Arahnya tidak diperiksa; lihat
  // catatan di kepala berkas soal PENGHUNI yang memang baca-saja.
  const idEntitas = new Set(D.ENTITAS.map((e) => e.id));
  const idProses = new Set(D.PROSES.map((p) => p.kunci));
  for (const e of D.ENTITAS) {
    if (!batas.some((a) => a.dari === e.id || a.ke === e.id)) {
      masalah.push(`${e.nama} tidak muncul pada satu pun aliran batas sistem`);
    }
  }

  for (const a of batas) {
    // Satu ujung aliran batas adalah entitas eksternal, ujung lainnya proses
    // di dalam sistem. Arahnya yang menentukan ujung mana: aliran masuk
    // berasal dari entitas, aliran keluar menuju entitas.
    const ujungEntitas = a.jenis === 'masuk' ? a.dari : a.ke;
    const ujungProses = a.jenis === 'masuk' ? a.ke : a.dari;
    if (!idEntitas.has(ujungEntitas)) {
      masalah.push(`aliran ${a.kunci} tidak berujung pada entitas eksternal (${ujungEntitas})`);
    }
    if (!idProses.has(ujungProses)) {
      masalah.push(`aliran ${a.kunci} tidak berujung pada proses yang ada (${ujungProses})`);
    }
    if (!a.label || !String([].concat(a.label).join('')).trim()) {
      masalah.push(`aliran ${a.kunci} tidak punya label`);
    }
    if (!a.struktur || !String(a.struktur).trim()) {
      masalah.push(`aliran ${a.kunci} tidak punya struktur data`);
    }
  }

  // Setiap aliran batas harus punya rute gambar, kalau tidak panahnya tidak
  // muncul dan jumlah aliran di gambar berbeda dari jumlah di tabel.
  for (const a of batas) {
    if (!D.RUTE_KONTEKS[a.kunci]) {
      masalah.push(`aliran ${a.kunci} tidak punya rute gambar di RUTE_KONTEKS`);
    }
  }

  return { masalah, masuk, keluar };
}

/**
 * Hasil: SVG siap tulis + daftar masalah + satu baris ringkasan untuk log.
 * `masalah` dari `gambarKonteks` (garis menembus kotak, label hilang) ikut
 * diteruskan apa adanya supaya build gagal bila tata letaknya rusak.
 */
function gambarKonteksAkademik() {
  const { masalah: masalahData, masuk, keluar } = periksaKonteks();
  const { svg, masalah: masalahGambar } = gambarKonteks('gane');

  return {
    svg,
    masalah: [...masalahData, ...masalahGambar],
    ringkas:
      `${D.ENTITAS.length} entitas eksternal · ` +
      `${masuk.length} aliran masuk + ${keluar.length} aliran keluar ` +
      `= ${masuk.length + keluar.length} aliran batas (dibaca dari docs/dfd/dfd-data.js)`,
    masuk,
    keluar,
  };
}

module.exports = { gambarKonteksAkademik, periksaKonteks };
