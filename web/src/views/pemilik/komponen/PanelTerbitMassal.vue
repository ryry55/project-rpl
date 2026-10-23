<script setup>
/**
 * Panel "Terbitkan semua kontrak aktif" (T4).
 *
 * Panel ini memegang sendiri keadaan formulir, proses, dan hasilnya. Sebelumnya
 * semuanya menumpang di TagihanView, sehingga satu view berisi lima kelompok
 * `ref` yang tidak saling berkaitan dan tidak ada cara melihat mana yang
 * membersihkan mana.
 *
 * Satu-satunya hal yang dikembalikan ke induk adalah sinyal `terbit`: daftar
 * tagihan di bawah panel ini harus ikut disegarkan, dan daftar itu milik induk.
 */
import { ref } from 'vue'
import { sesi } from '../../../sesi.js'
import { rupiah, tanggal, pesanGalat } from '../../../format.js'
import PanelKartu from '../../../komponen/ui/PanelKartu.vue'
import MedanForm from '../../../komponen/ui/MedanForm.vue'
import PesanBaris from '../../../komponen/ui/PesanBaris.vue'
import LencanaStatus from '../../../komponen/ui/LencanaStatus.vue'

const emit = defineEmits(['tutup', 'terbit'])

const periode = ref(periodeHariIni())
const proses = ref(false)
const hasil = ref(null)
const galat = ref('')

function periodeHariIni() {
  const sekarang = new Date()
  const bulan = String(sekarang.getMonth() + 1).padStart(2, '0')
  return `${sekarang.getFullYear()}-${bulan}-01`
}

/**
 * Tiga nasib kontrak perlu tiga label berbeda.
 *
 * Kalau semuanya ditampilkan "berhasil", batch yang separuh gagal karena tarif
 * belum diisi akan tampak sama dengan batch yang berhasil penuh.
 */
function labelHasil(status) {
  return { terbit: 'Terbit', dilewati: 'Dilewati', gagal: 'Gagal' }[status] || status
}

function kelasHasil(status) {
  // `lencana-status` global sudah punya warna untuk lunas (hijau) dan
  // dibatalkan (merah). Dipakai ulang supaya bahasa warnanya konsisten.
  return { terbit: 'lunas', dilewati: 'selesai', gagal: 'dibatalkan' }[status] || 'kosong'
}

async function terbitkan() {
  proses.value = true
  galat.value = ''
  hasil.value = null
  try {
    const jawaban = await sesi.panggil('/tagihan/terbitkan', {
      method: 'POST',
      body: JSON.stringify({ periode: periode.value }),
    })
    hasil.value = jawaban.data
    emit('terbit')
  } catch (e) {
    // 409 bukan kesalahan sistem: artinya periode ini sudah pernah diterbitkan.
    // Server tetap mengirim rincian yang berguna, jadi ditampilkan sebagai
    // hasil, bukan sebagai galat merah.
    if (e?.status === 409 && e?.body?.data) {
      hasil.value = e.body.data
    } else {
      galat.value = pesanGalat(e)
    }
  } finally {
    proses.value = false
  }
}
</script>

<template>
  <PanelKartu judul="Terbitkan semua kontrak aktif" kelas="panel-massal">
    <template #keterangan>
      <p class="bantuan mungil">
        Satu tagihan per kontrak untuk periode ini. Kontrak yang sudah punya tagihan
        dilewati, bukan diterbitkan dua kali.
      </p>
    </template>
    <template #aksi>
      <button class="halus mungil" @click="emit('tutup')">Tutup</button>
    </template>

    <form class="bidang" @submit.prevent="terbitkan">
      <div class="baris-bidang">
        <MedanForm
          id="periode-massal"
          label="Periode"
          bantuan="Tanggal berapa pun dalam bulan itu; disimpan sebagai tanggal 1."
        >
          <input id="periode-massal" v-model="periode" type="date" required />
        </MedanForm>
      </div>

      <PesanBaris v-if="galat" jenis="galat">{{ galat }}</PesanBaris>

      <div class="aksi">
        <button type="submit" :disabled="proses">
          {{ proses ? 'Menerbitkan…' : 'Terbitkan semua' }}
        </button>
      </div>
    </form>

    <!-- Ringkasan hasil. Tiga angka dipisah karena artinya berbeda bagi
         pemilik: terbit = berhasil, dilewati = tidak perlu tindakan,
         gagal = perlu diperbaiki. -->
    <div v-if="hasil" class="hasil-massal">
      <PesanBaris v-if="hasil.terbit > 0" jenis="sukses" mungil>
        {{ hasil.terbit }} tagihan terbit untuk periode {{ tanggal(hasil.periode) }}.
      </PesanBaris>
      <!-- Netral, bukan merah: tidak ada yang salah di sini. Seluruh kontrak
           memang sudah tertagih, dan itu keadaan yang baik. -->
      <PesanBaris v-else jenis="netral" mungil>
        Tidak ada tagihan baru yang terbit. Semua kontrak aktif sudah punya tagihan
        periode ini, atau belum mulai.
      </PesanBaris>

      <p class="mungil lemah">
        {{ hasil.terbit }} terbit · {{ hasil.dilewati }} dilewati ·
        {{ hasil.gagal }} gagal
      </p>

      <details v-if="hasil.rincian.length" class="rincian-massal">
        <summary class="mungil lemah">Rincian per kontrak</summary>
        <div class="tabel-bungkus">
          <table class="tabel">
            <thead>
              <tr>
                <th>Penghuni</th>
                <th>Kamar</th>
                <th>Hasil</th>
                <th>Keterangan</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in hasil.rincian" :key="r.kontrak_id">
                <td>{{ r.penghuni || '—' }}</td>
                <td>{{ r.kamar || '—' }}</td>
                <td>
                  <LencanaStatus :status="kelasHasil(r.status)" :label="labelHasil(r.status)" />
                </td>
                <td class="mungil lemah">
                  <template v-if="r.status === 'terbit'">
                    Jatuh tempo {{ tanggal(r.jatuh_tempo) }} · {{ rupiah(r.pokok) }}
                  </template>
                  <template v-else>{{ r.alasan }}</template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>
    </div>
  </PanelKartu>
</template>

<style scoped>
.panel-massal {
  border-color: #cfd9f5;
  background: #fbfcfe;
}

.hasil-massal {
  margin-top: 16px;
  padding-top: 14px;
  border-top: 1px solid var(--garis);
  display: grid;
  gap: 8px;
}

.hasil-massal p {
  margin: 0;
}

.rincian-massal summary {
  cursor: pointer;
}

.rincian-massal .tabel-bungkus {
  margin-top: 10px;
}
</style>
