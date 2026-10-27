// Uji regresi: pagar keterbacaan harus MENOLAK lembar yang terlalu kecil.
//
// Diperlukan karena pagar ini pernah lolos tanpa menolak apa pun: pesan
// masalahnya berubah dari persen ke milimeter, sedangkan penyaringnya masih
// mencari teks lama. Pagar yang tidak pernah menolak lebih berbahaya daripada
// tidak ada pagar — ia memberi rasa aman yang palsu.
const { gambarPapan, AMBANG_MM } = require('./papan');
const { MODUL } = require('./tingkat-data');

const tabel = MODUL[0].tabel;

function coba(nama, definisi) {
  const h = gambarPapan(definisi);
  const tolak = h.masalah.some((m) => m.includes('huruf hanya'));
  console.log(
    `${nama.padEnd(28)} kanvas ${h.kanvas.w}x${h.kanvas.h}  ` +
      `huruf ${h.hurufMm.toFixed(2)} mm di ${h.kertas}  ` +
      `${tolak ? 'DITOLAK pagar' : 'lolos'}`
  );
  return tolak;
}

const dasar = {
  kode: 'U',
  berkas: 'u.svg',
  judul: 'Uji',
  sub: 'Uji',
  mode: 'fisikal',
  tabel,
  catatan: '',
};

let gagal = 0;

// Lembar sempit bertumpuk tinggi: huruf pasti terlalu kecil di A4.
if (!coba('A4 mode fisikal 1 kolom', { ...dasar, kertas: 'A4', jumlahKolom: 1 })) {
  console.log('  SALAH: seharusnya ditolak');
  gagal++;
}

// Lembar lebar: huruf besar, harus lolos.
//
// 2 kolom dipilih karena pada A4 lembar ini menghasilkan 3,3 mm. Angka 3 kolom
// ternyata 2,37 mm — di bawah ambang, jadi justru harus ditolak. Uji ini
// mengikuti hasil ukur, bukan dugaan penulisnya.
if (coba('A4 mode fisikal 2 kolom', { ...dasar, kertas: 'A4', jumlahKolom: 2 })) {
  console.log('  SALAH: seharusnya lolos');
  gagal++;
}

// Lembar yang sama di A3 pasti lolos: kertasnya lebih besar.
if (coba('A3 mode fisikal 3 kolom', { ...dasar, kertas: 'A3', jumlahKolom: 3 })) {
  console.log('  SALAH: di A3 seharusnya lolos');
  gagal++;
}

// Ambang harus sama di kedua kertas: A3 memberi huruf lebih besar daripada A4
// untuk kanvas yang sama, dan itu satu-satunya alasan lembar LRS boleh satu
// halaman.
const diA4 = gambarPapan({ ...dasar, kertas: 'A4', jumlahKolom: 3 });
const diA3 = gambarPapan({ ...dasar, kertas: 'A3', jumlahKolom: 3 });
if (!(diA3.hurufMm > diA4.hurufMm)) {
  console.log('  SALAH: A3 seharusnya memberi huruf lebih besar daripada A4');
  gagal++;
}

console.log('');
console.log(`ambang ${AMBANG_MM} mm · gagal: ${gagal}`);
process.exit(gagal === 0 ? 0 : 1);
