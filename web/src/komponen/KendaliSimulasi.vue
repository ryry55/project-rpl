<script setup>
/**
 * Kendali simulasi tanggal untuk demo.
 *
 * Komponen, bukan potongan templat yang disalin ke tiap halaman: kendali ini
 * dipakai di Tagihan dan Tunggakan, dan dua salinan yang menyimpang akan
 * membuat salah satu halaman tidak bisa lagi dipakai untuk mendemokan yang lain.
 *
 * Komponen ini hanya mengurus masukan; ia tidak tahu apakah server benar-benar
 * memakai tanggalnya. Itu dilaporkan lewat prop `dipakai` yang diisi halaman
 * dari `meta.simulasi` di respons — bukan ditebak dari ada/tidaknya nilai.
 */
import { simulasiTanggal } from '../simulasi.js'
import { tanggal as formatTanggal } from '../format.js'

defineProps({
  /** Apakah server benar-benar memakai tanggal simulasi pada respons terakhir. */
  dipakai: { type: Boolean, default: false },
  /** Tanggal acuan yang dilaporkan server, "YYYY-MM-DD". */
  tanggalAcuan: { type: String, default: '' },
  /** Apa yang sedang dihitung, untuk kalimat keterangan. */
  konteks: { type: String, default: 'Angka di halaman ini' },
})

const emit = defineEmits(['berubah'])

const { tanggal, aktif } = simulasiTanggal

function terapkan() {
  simulasiTanggal.pakai(tanggal.value)
  emit('berubah')
}

function pakaiHariIni() {
  simulasiTanggal.bersihkan()
  emit('berubah')
}
</script>

<template>
  <section class="kartu panel simulasi-panel">
    <div class="simulasi-baris">
      <div>
        <h2 class="mungil">Simulasi tanggal</h2>
        <p class="bantuan">
          Menggeser "hari ini" supaya umur tunggakan bisa didemokan tanpa menunggu.
          Hanya berlaku bila backend berjalan dengan <code>APP_DEMO_MODE=true</code>.
        </p>
      </div>
      <div class="simulasi-kendali">
        <input v-model="tanggal" type="date" aria-label="Tanggal simulasi" />
        <button class="halus mungil" @click="terapkan">Terapkan</button>
        <button v-if="aktif" class="halus mungil" @click="pakaiHariIni">Hari ini</button>
      </div>
    </div>

    <p v-if="aktif && !dipakai" class="pesan-galat mungil">
      Tanggal simulasi tidak dipakai server — mode demo tidak aktif.
    </p>
    <p v-else-if="tanggalAcuan" class="bantuan mungil">
      {{ konteks }} dihitung pada {{ formatTanggal(tanggalAcuan) }}.
    </p>
  </section>
</template>

<style scoped>
/* Panel simulasi dibuat menonjol tapi tenang: ia alat demo, bukan bagian alur
   kerja sehari-hari. Garis putus-putus menandainya sebagai kendali, bukan data. */
.simulasi-panel {
  background: #f8f9fb;
  border-style: dashed;
}

.simulasi-baris {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}

.simulasi-baris h2 {
  margin: 0 0 3px;
}

.simulasi-kendali {
  display: flex;
  align-items: center;
  gap: 8px;
}

.simulasi-kendali input {
  width: auto;
}
</style>
