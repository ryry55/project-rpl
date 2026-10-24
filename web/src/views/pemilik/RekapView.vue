<script setup>
/**
 * Rekap pendapatan per metode bayar (T21).
 *
 * Satu aturan menentukan seluruh halaman ini benar atau tidak:
 * **pendapatan adalah uang yang benar-benar diterima**, bukan jumlah tagihan
 * yang terbit. Karena itu seluruh angka di sini berasal dari `pembayaran`,
 * dan denda yang masih tertera di tagihan ditampilkan terpisah sebagai
 * piutang - tidak pernah dijumlahkan ke total.
 *
 * Kenapa pemisahan itu ditampilkan sejelas ini. Tampilan yang wajar untuk
 * pemilik kos adalah menjumlahkan kolom total tiap tagihan lalu menyebutnya
 * "pendapatan bulan ini". Angka itu terlalu besar: ia mencampur uang yang sudah
 * masuk dengan denda yang belum dibayar siapa pun. Pemilik yang mengira sudah
 * menerima Rp 4 juta padahal rekeningnya bertambah Rp 3,2 juta akan
 * menyalahkan aplikasinya - dan ia benar, karena aplikasinya yang salah.
 *
 * Halaman ini juga menjawab pertanyaan yang tidak bisa dijawab rekap biasa:
 * **setoran tunai siapa yang masih di tangan pengelola.** Itu hanya mungkin
 * karena setiap baris tunai menyebut pemegangnya (T20).
 *
 * **Sejak REV-SEC-06 halaman ini punya dua wajah.** `/rekap` hanya untuk
 * pemilik: rekap adalah gambaran utuh keuangan kos, dan pengelola yang bisa
 * membacanya mengetahui penghasilan pemiliknya tanpa pernah diminta. Tetapi
 * menutupnya begitu saja membuat pengelola kehilangan satu pertanyaan yang
 * memang miliknya - "berapa uang tunai yang masih saya pegang". Karena itu
 * pengelola yang membuka halaman ini melihat tampilan sempit dari
 * `/pembayaran/tunai-saya`, bukan rekap yang dipersempit: angkanya miliknya
 * sendiri, tanpa pendapatan kos, tanpa piutang, tanpa daftar penghuni lain.
 */
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { sesi } from '../../sesi.js'
import { rupiah, tanggal, pesanGalat } from '../../format.js'
import { simulasiTanggal } from '../../simulasi.js'
import KendaliSimulasi from '../../komponen/KendaliSimulasi.vue'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'
import PesanBaris from '../../komponen/ui/PesanBaris.vue'
import PanelKartu from '../../komponen/ui/PanelKartu.vue'
import BarisKosong from '../../komponen/ui/BarisKosong.vue'
import MedanForm from '../../komponen/ui/MedanForm.vue'

/**
 * Apakah pemanggil melihat rekap penuh.
 *
 * Diperiksa dari peran, bukan dari wewenang: tidak ada wewenang yang bisa
 * diberikan untuk membuka rekap - ia hak pemilik atas usahanya sendiri. Server
 * menegakkan hal yang sama lewat `peran:pemilik`; pemeriksaan di sini hanya
 * memilih tampilan mana yang dirender, supaya pengelola tidak melihat deretan
 * angka kosong lalu mengira aplikasinya rusak.
 */
const rekapPenuh = computed(() => sesi.user.value?.peran === 'pemilik')

const data = ref(null)
const pembayaran = ref([])
const tunaiSaya = ref(null)
const memuat = ref(true)
const galatMuat = ref('')

// Rentang tanggal. Dibiarkan kosong pada muatan pertama supaya server yang
// memilih bawaannya (awal bulan berjalan sampai hari ini); setelah respons
// pertama, isinya diambil dari apa yang benar-benar dipakai server - bukan dari
// tebakan layar. Kalau layar menebak sendiri, dua tanggal bisa berbeda dengan
// yang tertulis di rekap, dan tidak ada cara tahu mana yang dipakai.
const dari = ref('')
const sampai = ref('')

const simulasiDipakai = ref(false)

const perMetode = computed(() => data.value?.per_metode || [])
const perSumber = computed(() => data.value?.per_sumber || [])
const tunaiDiTangan = computed(() => data.value?.tunai_di_tangan || [])
const tanpaMetode = computed(() => data.value?.tanpa_metode || { jumlah: 0, transaksi: 0 })
const piutang = computed(() => data.value?.piutang || { tagihan_terbuka: 0, denda_belum_dibayar: 0 })

const total = computed(() => data.value?.total || 0)
const jumlahTransaksi = computed(() => data.value?.jumlah_transaksi || 0)

/**
 * Metode yang benar-benar menerima uang pada rentang ini.
 *
 * Kartu kosong tetap ditampilkan seluruhnya - "QRIS Rp 0" adalah keterangan
 * yang berguna. Tetapi daftar pembayaran dan blok tunai hanya berisi yang ada
 * isinya.
 */
const metodeBerisi = computed(() => perMetode.value.filter((m) => m.transaksi > 0))

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const kueri = new URLSearchParams()
    if (dari.value) {
      kueri.set('dari', dari.value)
    }
    if (sampai.value) {
      kueri.set('sampai', sampai.value)
    }
    const akhiran = kueri.toString() ? `?${kueri}` : ''

    // Pengelola memakai jalur sempit (REV-SEC-06). Dua jalur, bukan satu jalur
    // dengan parameter: yang berbeda bukan rentangnya, melainkan pertanyaannya -
    // "berapa pendapatan kos" versus "berapa uang tunai yang saya pegang" - dan
    // endpoint yang menjawab pertanyaan berbeda memang berbeda.
    if (!rekapPenuh.value) {
      const sempit = await sesi.panggil(`/pembayaran/tunai-saya${akhiran}`, {
        headers: simulasiTanggal.header(),
      })
      tunaiSaya.value = sempit.data
      dari.value = sempit.data.dari
      sampai.value = sempit.data.sampai
      simulasiDipakai.value = Boolean(sempit.meta?.simulasi)
      return
    }

    // Simulasi tanggal ikut dikirim: `piutang` dihitung memakai denda pada
    // "hari ini" milik server, dan selama mode demo aktif "hari ini" adalah
    // tanggal simulasi. Tanpa header ini, rekap dan layar Tunggakan akan
    // menyebut piutang yang berbeda untuk tagihan yang sama.
    const hasil = await sesi.panggil(`/rekap${akhiran}`, { headers: simulasiTanggal.header() })
    data.value = hasil.data
    dari.value = hasil.data.dari
    sampai.value = hasil.data.sampai
    simulasiDipakai.value = Boolean(hasil.data.simulasi)

    // Daftar pembayaran dimuat terpisah: rekap menjawab "berapa", daftar
    // menjawab "yang mana". Menggabungkannya berarti satu respons yang harus
    // dihitung ulang di layar, dan angka rekap bisa berbeda dari daftarnya.
    const daftarHasil = await sesi.panggil(
      `/pembayaran?dari=${hasil.data.dari}&sampai=${hasil.data.sampai}`,
      { headers: simulasiTanggal.header() },
    )
    pembayaran.value = daftarHasil.data || []
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

/** Kembali ke rentang bawaan: bulan berjalan sampai hari ini. */
async function bulanIni() {
  dari.value = ''
  sampai.value = ''
  await muat()
}

onMounted(muat)
</script>

<template>
  <div class="halaman halaman-penuh">
    <KepalaHalaman :judul="rekapPenuh ? 'Rekap pendapatan' : 'Uang tunai saya'">
      <template #keterangan>
        <template v-if="rekapPenuh">
          {{ jumlahTransaksi }} transaksi · {{ rupiah(total) }}
          <template v-if="tanpaMetode.jumlah > 0">
            · {{ rupiah(tanpaMetode.jumlah) }} tanpa metode
          </template>
        </template>
        <template v-else-if="tunaiSaya">
          {{ tunaiSaya.transaksi }} transaksi · {{ rupiah(tunaiSaya.jumlah) }}
        </template>
      </template>
    </KepalaHalaman>

    <!-- Rentang tanggal. Rekap tanpa periode yang jelas tidak bisa
         dipertanggungjawabkan saat ditanya "ini bulan apa", jadi periodenya
         ditulis di layar, bukan disembunyikan sebagai bawaan tersembunyi. -->
    <PanelKartu judul="Periode" kelas="panel-periode">
      <form class="baris-bidang" @submit.prevent="muat">
        <MedanForm id="rekap-dari" label="Dari tanggal">
          <input id="rekap-dari" v-model="dari" type="date" />
        </MedanForm>
        <MedanForm id="rekap-sampai" label="Sampai tanggal">
          <input id="rekap-sampai" v-model="sampai" type="date" />
        </MedanForm>
      </form>
      <div class="aksi periode-aksi">
        <button type="button" :disabled="memuat" @click="muat">
          {{ memuat ? 'Memuat…' : 'Tampilkan' }}
        </button>
        <button type="button" class="halus" @click="bulanIni">Bulan ini</button>
      </div>
      <p v-if="data" class="bantuan mungil">
        Dihitung dari tanggal pembayaran (uang masuk), bukan tanggal tagihan.
        Tagihan Oktober yang dibayar November menambah kas November.
      </p>
    </PanelKartu>

    <KendaliSimulasi
      :dipakai="simulasiDipakai"
      :tanggal-acuan="sampai"
      konteks="Piutang denda"
      @berubah="muat"
    />

    <PesanBaris v-if="galatMuat" jenis="galat">{{ galatMuat }}</PesanBaris>
    <p v-else-if="memuat && !data && !tunaiSaya" class="bantuan">Memuat…</p>

    <!-- Tampilan sempit untuk pengelola (REV-SEC-06). Bukan rekap yang
         dipersempit: yang ditampilkan hanya pertanyaan miliknya sendiri -
         berapa uang tunai yang tercatat ia terima pada rentang ini. Tidak ada
         pendapatan kos, piutang, metode lain, atau nama penghuni. -->
    <template v-else-if="!rekapPenuh && tunaiSaya">
      <div class="kisi-metode">
        <div class="kartu kartu-metode">
          <span class="metode-judul mungil">Tunai di tangan saya</span>
          <span class="metode-angka">{{ rupiah(tunaiSaya.jumlah) }}</span>
          <span class="metode-ket mungil lemah">
            {{ tunaiSaya.transaksi }} transaksi
          </span>
        </div>
        <div v-if="tunaiSaya.pembalik?.transaksi > 0" class="kartu kartu-metode">
          <span class="metode-judul mungil">Dibatalkan</span>
          <span class="metode-angka">{{ rupiah(tunaiSaya.pembalik.jumlah) }}</span>
          <span class="metode-ket mungil lemah">
            {{ tunaiSaya.pembalik.transaksi }} pembalikan
          </span>
        </div>
      </div>

      <PesanBaris jenis="netral" mungil>
        {{ tunaiSaya.catatan }}
      </PesanBaris>

      <p class="bantuan mungil">
        Rekap pendapatan kos hanya untuk pemilik. Yang dibutuhkan pekerjaan Anda
        adalah angka di atas, dan daftar pembayaran tempat Anda memeriksa catatan
        sendiri ada di halaman
        <RouterLink :to="{ name: 'pemilik-tagihan' }">Tagihan</RouterLink>.
      </p>
    </template>

    <template v-else-if="rekapPenuh && data">
      <!-- Kartu per metode. Yang kosong tetap tampil: "QRIS Rp 0" menjawab
           pertanyaan "apakah QRIS tidak dipakai atau layarnya rusak". -->
      <div class="kisi-metode">
        <div v-for="m in perMetode" :key="m.metode" class="kartu kartu-metode">
          <span class="metode-judul mungil">{{ m.label }}</span>
          <span class="metode-angka">{{ rupiah(m.jumlah) }}</span>
          <span class="metode-ket mungil lemah">
            {{ m.transaksi }} transaksi
          </span>
        </div>
      </div>

      <!-- Tanpa metode: baris sebelum T20. Ditampilkan, bukan dibuang, supaya
           lubangnya terlihat dan totalnya tetap bisa dipertanggungjawabkan. -->
      <PesanBaris v-if="tanpaMetode.jumlah > 0" jenis="netral" mungil>
        {{ rupiah(tanpaMetode.jumlah) }} dari {{ tanpaMetode.transaksi }} pembayaran
        tidak menyebut metodenya. Baris seperti ini berasal dari data lama sebelum metode
        dicatat, jadi tidak dimasukkan ke kelompok mana pun.
      </PesanBaris>

      <!-- Piutang. Sengaja dipisah dan diberi judul sendiri, bukan angka kecil di
           sudut: inilah angka yang paling sering disalahartikan sebagai
           pendapatan. -->
      <PanelKartu judul="Piutang — bukan pendapatan" kelas="panel-piutang">
        <template #keterangan>
          <p class="bantuan mungil">
            Denda yang tertera di tagihan baru menjadi pendapatan pada hari ia dibayar.
            Selama belum dibayar, ia piutang — dan tidak ikut dihitung di total di atas.
          </p>
        </template>
        <div class="kisi-piutang">
          <div>
            <span class="piutang-label mungil lemah">Denda belum dibayar</span>
            <strong class="piutang-angka">{{ rupiah(piutang.denda_belum_dibayar) }}</strong>
          </div>
          <div>
            <span class="piutang-label mungil lemah">Tagihan terbuka</span>
            <strong class="piutang-angka">{{ piutang.tagihan_terbuka }}</strong>
          </div>
          <div>
            <span class="piutang-label mungil lemah">Diterima pada periode ini</span>
            <strong class="piutang-angka">{{ rupiah(total) }}</strong>
          </div>
        </div>
      </PanelKartu>

      <!-- Sumber baris: dicatat orang atau dari webhook gateway. Bukan angka
           uang yang berbeda - keduanya sudah masuk `total` di atas - melainkan
           cara barisnya lahir, yang menentukan seberapa besar ia bisa dipercaya
           tanpa pemeriksaan. -->
      <p v-if="perSumber.length" class="bantuan mungil sumber-baris">
        Asal baris:
        <span v-for="(s, i) in perSumber" :key="s.sumber">
          {{ s.label }} {{ rupiah(s.jumlah) }}<template v-if="i < perSumber.length - 1"> · </template>
        </span>
      </p>

      <!-- Setoran tunai per pemegang. Hanya bermakna bila ada tunai; blok kosong
           akan menimbulkan pertanyaan yang tidak perlu. -->
      <section v-if="tunaiDiTangan.length" class="kartu panel panel-tunai">
        <div class="panel-kepala">
          <div>
            <h2>Uang tunai di tangan</h2>
            <p class="bantuan mungil">
              Tunai yang tercatat diterima tiap orang pada periode ini. Uang tunai tidak
              masuk rekening sampai disetor, jadi angka inilah yang perlu dicocokkan
              dengan setoran.
            </p>
          </div>
        </div>
        <div class="tabel-bungkus">
          <table class="tabel tabel-tunai" v-kartu-tabel>
            <thead>
              <tr>
                <th>Pemegang</th>
                <th class="kanan">Transaksi</th>
                <th class="kanan">Jumlah</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(t, i) in tunaiDiTangan" :key="t.diterima_oleh ?? 'tanpa' + i">
                <td>
                  <strong>{{ t.nama }}</strong>
                  <span v-if="!t.diterima_oleh" class="lemah mungil">
                    — baris lama tanpa pemegang
                  </span>
                </td>
                <td class="kanan">{{ t.transaksi }}</td>
                <td class="kanan"><strong>{{ rupiah(t.jumlah) }}</strong></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Daftar uang yang masuk. Rekap menjawab "berapa"; daftar ini menjawab
           "yang mana", dan tanpa itu angka besar di atas tidak bisa dicocokkan
           dengan rekening. -->
      <PanelKartu judul="Pembayaran pada periode ini">
        <template #keterangan>
          <p class="bantuan mungil">
            Diurutkan dari yang terbaru. Pemeriksaan pembayaran dilakukan dari halaman
            Tagihan, tempat tagihannya ikut terlihat.
          </p>
        </template>
        <div class="tabel-bungkus">
          <table class="tabel tabel-pembayaran" v-kartu-tabel>
            <thead>
              <tr>
                <th>Tanggal</th>
                <th>Penghuni</th>
                <th>Metode</th>
                <th class="kanan">Jumlah</th>
                <th>Pemeriksaan</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in pembayaran" :key="p.id">
                <td>{{ tanggal(p.tanggal_pembayaran) }}</td>
                <td>
                  <strong>{{ p.penghuni_nama || '—' }}</strong>
                  <template v-if="p.kamar_nomor">
                    <span class="lemah mungil"> · kamar {{ p.kamar_nomor }}</span>
                  </template>
                </td>
                <td class="mungil">
                  <span :class="{ lemah: !p.metode }">{{ p.metode_label }}</span>
                  <template v-if="p.referensi">
                    <br /><span class="lemah">{{ p.referensi }}</span>
                  </template>
                  <template v-if="p.diterima_oleh_nama">
                    <br /><span class="lemah">dipegang {{ p.diterima_oleh_nama }}</span>
                  </template>
                </td>
                <td class="kanan">{{ rupiah(p.jumlah) }}</td>
                <td class="mungil">
                  <span
                    class="lencana-verifikasi"
                    :class="
                      !p.verifikasi_terakhir
                        ? 'verifikasi-belum'
                        : p.verifikasi_terakhir.keputusan === 'terima'
                          ? 'verifikasi-terima'
                          : 'verifikasi-tolak'
                    "
                  >
                    {{
                      !p.verifikasi_terakhir
                        ? 'Belum diperiksa'
                        : p.verifikasi_terakhir.keputusan === 'terima'
                          ? 'Diterima'
                          : 'Ditolak'
                    }}
                  </span>
                </td>
              </tr>
              <BarisKosong v-if="!memuat && pembayaran.length === 0" :kolom="5">
                Tidak ada uang masuk pada periode ini.
              </BarisKosong>
            </tbody>
          </table>
        </div>
      </PanelKartu>

      <p v-if="metodeBerisi.length === 0 && total === 0" class="bantuan mungil kosong">
        Belum ada pendapatan pada periode ini. Ubah rentang tanggal di atas untuk melihat
        periode lain.
      </p>
    </template>
  </div>
</template>

<style scoped>
/* Empat kartu metode berjajar dan akan terpotong kalau dibatasi lebar baca. */
.kisi-metode {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}

.kartu-metode {
  display: grid;
  gap: 2px;
  padding: 14px 16px;
}

.metode-judul {
  font-weight: 600;
  color: var(--teks-lemah);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

/* Angka adalah hal pertama yang dicari mata di halaman ini. */
.metode-angka {
  font-size: 22px;
  font-weight: 680;
  line-height: 1.15;
  font-variant-numeric: tabular-nums;
}

.metode-ket {
  font-variant-numeric: tabular-nums;
}

.panel-periode {
  display: grid;
  gap: 12px;
}

.periode-aksi {
  margin-top: 0;
}

/* Piutang ditandai garis kiri, sama seperti gerbang di halaman Tagihan: dua
   tempat yang membicarakan uang yang belum diterima terlihat sebagai satu hal. */
.panel-piutang {
  border-left: 3px solid #8a5a00;
  background: #fdfaf4;
}

.panel-piutang :deep(h2) {
  color: #8a5a00;
}

.kisi-piutang {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
}

.kisi-piutang > div {
  display: grid;
  gap: 2px;
}

.piutang-angka {
  font-size: 18px;
  font-weight: 660;
  font-variant-numeric: tabular-nums;
}

.sumber-baris {
  margin: 0;
}

.kosong {
  text-align: center;
}

.tabel-tunai th,
.tabel-tunai td,
.tabel-pembayaran th,
.tabel-pembayaran td {
  padding-top: 7px;
  padding-bottom: 7px;
  vertical-align: top;
}

.lencana-verifikasi {
  display: inline-block;
  padding: 1px 7px;
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
  border-radius: 999px;
}

.verifikasi-belum {
  color: var(--teks-lemah);
  background: #eef0f3;
}

.verifikasi-terima {
  color: var(--sukses);
  background: var(--sukses-lemah);
}

.verifikasi-tolak {
  color: var(--bahaya);
  background: var(--bahaya-lemah);
}

@media (max-width: 1100px) {
  .kisi-metode {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 620px) {
  .kisi-metode {
    grid-template-columns: 1fr;
  }

  .kisi-piutang {
    grid-template-columns: 1fr;
    gap: 10px;
  }
}
</style>
