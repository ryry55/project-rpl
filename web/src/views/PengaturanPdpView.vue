<script setup>
/**
 * Data pribadi saya (T28).
 *
 * Tiga hak UU 27/2022 dalam satu layar, dan urutannya mengikuti akibatnya:
 *
 *   1. **Hak akses** - apa saja yang tercatat tentang saya. Ditaruh paling atas
 *      karena dua hak lainnya tidak berguna tanpa yang ini: orang tidak bisa
 *      meminta koreksi atau penghapusan atas sesuatu yang tidak bisa ia lihat.
 *
 *   2. **Hak koreksi** - disebut sebagai arahan, bukan formulir. Mengubah nama
 *      dan nomor HP tetap lewat data induk penghuni, karena di sanalah pemilik
 *      kos juga melihatnya; formulir kedua di sini akan menjadi salinan yang
 *      cepat atau lambat berbeda dari aslinya.
 *
 *   3. **Hak penghapusan** - dengan dua peringatan yang ditulis apa adanya:
 *      yang dihapus adalah IDENTITAS, bukan catatan keuangannya; dan
 *      penghapusan tidak bisa dibatalkan.
 *
 * Satu keputusan bentuk yang disengaja: tombol hapus **mati** sampai pengguna
 * mengetik `HAPUS`. Bukan karena server menolak yang lain - server memang
 * menolak - melainkan supaya percobaan yang salah berhenti di peramban, bukan
 * di jaringan. Tindakan yang tidak bisa dibatalkan tidak pantas ditempuh lewat
 * satu klik yang keliru.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { sesi } from '../sesi.js'
import { pesanGalat } from '../format.js'
import KepalaHalaman from '../komponen/ui/KepalaHalaman.vue'
import PanelKartu from '../komponen/ui/PanelKartu.vue'
import PesanBaris from '../komponen/ui/PesanBaris.vue'
import BarisKosong from '../komponen/ui/BarisKosong.vue'

const router = useRouter()

const memuat = ref(true)
const galatMuat = ref('')
const data = ref(null)
const meta = ref({})

const konfirmasi = ref('')
const menghapus = ref(false)
const galatHapus = ref('')
const alasanTertahan = ref('')

/**
 * Penghapusan ditahan selama masih ada kontrak aktif.
 *
 * Server tetap memeriksa ulang - ini hanya supaya tombolnya tidak menawarkan
 * sesuatu yang pasti dijawab 409. Yang ditampilkan bukan tombol mati tanpa
 * penjelasan, melainkan alasannya.
 */
const adaKontrakAktif = computed(
  () => (data.value?.kontrak || []).some((k) => k.status === 'aktif'),
)

const bolehHapus = computed(() => konfirmasi.value === 'HAPUS' && !menghapus.value)

async function muat() {
  memuat.value = true
  galatMuat.value = ''

  try {
    const hasil = await sesi.panggil('/pdp/data-saya')
    data.value = hasil.data
    meta.value = hasil.meta || {}
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

async function hapus() {
  if (!bolehHapus.value) {
    return
  }

  menghapus.value = true
  galatHapus.value = ''
  alasanTertahan.value = ''

  try {
    const hasil = await sesi.panggil('/pdp/data-saya', {
      method: 'DELETE',
      body: JSON.stringify({ konfirmasi: konfirmasi.value }),
    })

    // Akunnya masih ada tetapi identitasnya sudah lepas, dan seluruh sesinya
    // diputus server. Keluar dari sini supaya layar tidak menampilkan nama
    // yang sudah tidak ada di basis data.
    sesi.keluarLokal()
    router.replace({ name: 'masuk', query: { pesan: hasil.data?.pesan ? 'dihapus' : '' } })
  } catch (e) {
    // 409 = belum boleh, dan alasannya sudah ditulis server dalam bahasa
    // manusia. Ditampilkan sebagai kalimat, bukan sebagai kode galat.
    if (e.status === 409) {
      alasanTertahan.value = e.message
    } else {
      galatHapus.value = pesanGalat(e)
    }
  } finally {
    menghapus.value = false
  }
}

onMounted(muat)
</script>

<template>
  <div class="halaman">
    <KepalaHalaman judul="Data pribadi saya">
      <template #keterangan>
        Apa yang tercatat tentang Anda, dan cara meminta koreksi atau penghapusannya.
      </template>
    </KepalaHalaman>

    <PesanBaris v-if="galatMuat" jenis="galat">{{ galatMuat }}</PesanBaris>

    <p v-if="memuat" class="lemah mungil">Memuatâ€¦</p>

    <template v-else-if="data">
      <PanelKartu judul="Akun dan kontak">
        <dl class="daftar-data">
          <div>
            <dt>Nama akun</dt>
            <dd>{{ data.akun?.nama || '—' }}</dd>
          </div>
          <div>
            <dt>Email</dt>
            <dd>{{ data.akun?.email || '—' }}</dd>
          </div>
          <div>
            <dt>Peran</dt>
            <dd>{{ data.akun?.peran || '—' }}</dd>
          </div>
          <div>
            <dt>Status akun</dt>
            <dd>{{ data.akun?.email_terverifikasi ? 'aktif' : 'belum aktif' }}</dd>
          </div>
          <div v-if="data.penghuni">
            <dt>Nama pada data induk</dt>
            <dd>{{ data.penghuni.nama || '—' }}</dd>
          </div>
          <div v-if="data.penghuni">
            <dt>Nomor HP</dt>
            <dd>{{ data.penghuni.no_hp || '—' }}</dd>
          </div>
        </dl>

        <p class="bantuan mungil">
          Nama dan nomor HP diperbaiki lewat data induk penghuni. Hubungi pemilik kos, atau
          perbaiki sendiri bila akun Anda punya wewenang mengelola penghuni.
        </p>
      </PanelKartu>

      <PanelKartu judul="Kamar yang pernah Anda tempati">
        <div class="tabel-bungkus">
          <table class="tabel" v-kartu-tabel>
            <thead>
              <tr>
                <th>Kamar</th>
                <th>Masuk</th>
                <th>Keluar</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="k in data.kontrak || []" :key="k.id">
                <td><strong>{{ k.kamar || '—' }}</strong></td>
                <td>{{ k.tgl_masuk || '—' }}</td>
                <td>{{ k.tgl_keluar || '—' }}</td>
                <td>
                  <span class="lencana-status" :class="`status-${k.status}`">{{ k.status }}</span>
                </td>
              </tr>
              <BarisKosong v-if="!data.kontrak || data.kontrak.length === 0" :kolom="4">
                Belum pernah menempati kamar di kos ini.
              </BarisKosong>
            </tbody>
          </table>
        </div>
      </PanelKartu>

      <PanelKartu judul="Persetujuan yang pernah Anda berikan">
        <div class="tabel-bungkus">
          <table class="tabel" v-kartu-tabel>
            <thead>
              <tr>
                <th>Versi teks</th>
                <th>Disetujui pada</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in data.persetujuan || []" :key="p.versi">
                <td><strong>{{ p.versi }}</strong></td>
                <td>{{ p.disetujui_pada || '—' }}</td>
              </tr>
              <BarisKosong v-if="!data.persetujuan || data.persetujuan.length === 0" :kolom="2">
                Belum ada persetujuan tercatat.
              </BarisKosong>
            </tbody>
          </table>
        </div>

        <p class="bantuan mungil">
          Persetujuan bersifat bukti, bukan setelan: ia tidak bisa diubah atau dihapus, dan versi
          baru menuntut persetujuan baru. Versi yang berlaku sekarang:
          <strong>{{ meta.versi_pdp || '—' }}</strong>.
        </p>
      </PanelKartu>

      <PanelKartu judul="Jejak tindakan Anda">
        <div class="tabel-bungkus">
          <table class="tabel" v-kartu-tabel>
            <thead>
              <tr>
                <th>Tindakan</th>
                <th>Objek</th>
                <th>Waktu</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(j, i) in data.jejak_terkait || []" :key="i">
                <td><strong>{{ j.aksi }}</strong></td>
                <td class="lemah mungil">
                  {{ j.entitas }}<template v-if="j.entitas_id"> #{{ j.entitas_id }}</template>
                </td>
                <td class="lemah mungil">{{ j.dibuat_pada || '—' }}</td>
              </tr>
              <BarisKosong v-if="!data.jejak_terkait || data.jejak_terkait.length === 0" :kolom="3">
                Belum ada tindakan tercatat atas nama Anda.
              </BarisKosong>
            </tbody>
          </table>
        </div>

        <p class="bantuan mungil">
          Ini hanya tindakan yang Anda lakukan sendiri. Tindakan pemilik kos atas data kos tidak
          ditampilkan di sini - itu data kos, bukan data pribadi Anda.
        </p>
      </PanelKartu>

      <PanelKartu judul="Meminta penghapusan identitas">
        <p class="mungil">
          Penghapusan akan <strong>melepas identitas</strong> Anda: nama, email, dan nomor HP
          diganti menjadi penanda tanpa nama. Tagihan dan pembayaran atas nama Anda
          <strong>tetap tersimpan</strong> tanpa nama, karena catatan keuangan tidak boleh dihapus -
          bukti bahwa uang pernah diterima melindungi Anda maupun pemilik kos.
        </p>

        <PesanBaris v-if="adaKontrakAktif" jenis="netral" class="pesan-tahan">
          Anda masih menempati kamar. Penghapusan baru bisa dilakukan setelah kontrak aktif Anda
          berakhir, karena tagihan berikutnya harus menunjuk orang yang ada.
        </PesanBaris>

        <PesanBaris v-if="alasanTertahan" jenis="galat" class="pesan-tahan">
          {{ alasanTertahan }}
        </PesanBaris>

        <div class="bidang konfirmasi">
          <label for="konfirmasi-hapus">
            Ketik <strong>HAPUS</strong> (huruf besar) untuk mengaktifkan tombol
          </label>
          <input
            id="konfirmasi-hapus"
            v-model="konfirmasi"
            type="text"
            autocomplete="off"
            placeholder="HAPUS"
          />
        </div>

        <p v-if="galatHapus" class="pesan-galat" role="alert">{{ galatHapus }}</p>

        <button type="button" class="bahaya" :disabled="!bolehHapus || adaKontrakAktif" @click="hapus">
          {{ menghapus ? 'Menghapusâ€¦' : 'Lepas identitas saya' }}
        </button>

        <p class="bantuan mungil">
          Tindakan ini tidak bisa dibatalkan. Anda akan keluar dari aplikasi dan tidak bisa masuk
          lagi dengan akun ini.
        </p>
      </PanelKartu>
    </template>
  </div>
</template>

<style scoped>
/* Status di sini datang mentah dari API ("aktif", "selesai"). Huruf besar di
   awal kata cukup, dan `capitalize` aman karena semuanya satu kata. */
.lencana-status {
  text-transform: capitalize;
}

.daftar-data {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 14px 20px;
  margin: 0 0 14px;
}

.daftar-data dt {
  font-size: 12px;
  font-weight: 500;
  color: var(--teks-lemah);
  margin-bottom: 2px;
}

.daftar-data dd {
  margin: 0;
  font-size: 14px;
}

.pesan-tahan {
  margin-bottom: 14px;
}

.konfirmasi {
  max-width: 240px;
  margin-bottom: 14px;
}

.bahaya {
  color: #fff;
  background: var(--bahaya);
  border-color: var(--bahaya);
}

.bahaya:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.bantuan {
  margin: 12px 0 0;
}
</style>
