<script setup>
/**
 * Formulir "Terbitkan tagihan" untuk satu kontrak.
 *
 * Berbeda maksud dari panel terbit massal: yang ini untuk satu kontrak yang
 * dipilih, biasanya karena kontraknya baru mulai atau tertinggal satu periode.
 */
import { ref } from 'vue'
import { sesi } from '../../../sesi.js'
import { pesanGalat, galatPerBidang } from '../../../format.js'
import PanelKartu from '../../../komponen/ui/PanelKartu.vue'
import MedanForm from '../../../komponen/ui/MedanForm.vue'
import PesanBaris from '../../../komponen/ui/PesanBaris.vue'

const props = defineProps({
  /** Kontrak aktif yang bisa dipilih. */
  kontrak: { type: Array, default: () => [] },
})

const emit = defineEmits(['tutup', 'terbit'])

const form = ref({ kontrak_id: '', periode: periodeHariIni() })
const menyimpan = ref(false)
const galat = ref('')
const galatBidang = ref({})

function periodeHariIni() {
  const sekarang = new Date()
  const bulan = String(sekarang.getMonth() + 1).padStart(2, '0')
  return `${sekarang.getFullYear()}-${bulan}-01`
}

async function terbitkan() {
  menyimpan.value = true
  galat.value = ''
  galatBidang.value = {}
  try {
    await sesi.panggil('/tagihan', {
      method: 'POST',
      body: JSON.stringify({
        kontrak_id: Number(form.value.kontrak_id),
        periode: form.value.periode,
      }),
    })
    emit('terbit')
  } catch (e) {
    galat.value = pesanGalat(e)
    galatBidang.value = galatPerBidang(e)
  } finally {
    menyimpan.value = false
  }
}
</script>

<template>
  <PanelKartu judul="Terbitkan tagihan">
    <form class="bidang" @submit.prevent="terbitkan">
      <div class="baris-bidang">
        <MedanForm id="kontrak" label="Kontrak" :galat="galatBidang.kontrak_id">
          <select
            id="kontrak"
            v-model="form.kontrak_id"
            :aria-invalid="Boolean(galatBidang.kontrak_id)"
          >
            <option value="" disabled>Pilih kontrak</option>
            <option v-for="k in props.kontrak" :key="k.id" :value="k.id">
              {{ k.penghuni?.nama }} — kamar {{ k.kamar?.nomor }}
            </option>
          </select>
        </MedanForm>
        <MedanForm
          id="periode"
          label="Periode"
          :galat="galatBidang.periode"
          bantuan="Tanggal berapa pun dalam bulan itu; disimpan sebagai tanggal 1."
        >
          <input
            id="periode"
            v-model="form.periode"
            type="date"
            :aria-invalid="Boolean(galatBidang.periode)"
          />
        </MedanForm>
      </div>

      <PesanBaris v-if="galat" jenis="galat">{{ galat }}</PesanBaris>

      <div class="aksi">
        <button type="submit" :disabled="menyimpan">
          {{ menyimpan ? 'Menerbitkan…' : 'Terbitkan' }}
        </button>
        <button type="button" class="halus" @click="emit('tutup')">Batal</button>
      </div>
    </form>
  </PanelKartu>
</template>
