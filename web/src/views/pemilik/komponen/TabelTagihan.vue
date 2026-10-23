<script setup>
/**
 * Tabel daftar seluruh tagihan.
 *
 * Tetap tabel, bukan kumpulan kartu, karena pemilik membacanya dengan cara
 * memindai kolom: "periode mana yang belum masuk". Kolom `Sisa` ditebalkan
 * karena itulah angka yang dicari; pokok dan total hanya konteks.
 */
import { rupiah, tanggal } from '../../../format.js'
import BarisKosong from '../../../komponen/ui/BarisKosong.vue'
import LencanaStatus from '../../../komponen/ui/LencanaStatus.vue'

const props = defineProps({
  /** Baris tagihan dari `GET /tagihan`. */
  daftar: { type: Array, default: () => [] },
  memuat: { type: Boolean, default: false },
  memuatDetail: { type: Boolean, default: false },
})

const emit = defineEmits(['buka'])

const KOLOM = 11
</script>

<template>
  <section class="kartu">
    <div class="tabel-bungkus">
      <table class="tabel">
        <thead>
          <tr>
            <th>Periode</th>
            <th>Penghuni</th>
            <th>Kamar</th>
            <th class="kanan">Pokok</th>
            <th class="kanan">Denda</th>
            <th class="kanan">Total</th>
            <th class="kanan">Dibayar</th>
            <th class="kanan">Sisa</th>
            <th>Jatuh tempo</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in props.daftar" :key="t.id">
            <td><strong>{{ tanggal(t.periode) }}</strong></td>
            <td>{{ t.kontrak?.penghuni?.nama || '—' }}</td>
            <td>{{ t.kontrak?.kamar?.nomor || '—' }}</td>
            <td class="kanan">{{ rupiah(t.pokok) }}</td>
            <td class="kanan" :class="{ 'sel-denda': t.denda > 0 }">
              {{ t.denda > 0 ? rupiah(t.denda) : '—' }}
            </td>
            <td class="kanan"><strong>{{ rupiah(t.total) }}</strong></td>
            <td class="kanan lemah">
              {{ t.dibayar > 0 ? rupiah(t.dibayar) : '—' }}
            </td>
            <td class="kanan" :class="{ 'sel-sisa': t.sisa > 0 && t.status !== 'dibatalkan' }">
              {{ rupiah(t.sisa) }}
            </td>
            <td>{{ tanggal(t.jatuh_tempo) }}</td>
            <td><LencanaStatus :status="t.status" /></td>
            <td class="kanan">
              <button class="halus mungil" @click="emit('buka', t)">Rincian</button>
            </td>
          </tr>
          <BarisKosong v-if="!props.memuat && props.daftar.length === 0" :kolom="KOLOM">
            Belum ada tagihan. Terbitkan tagihan pertama lewat tombol di atas.
          </BarisKosong>
          <BarisKosong v-if="props.memuat" :kolom="KOLOM">Memuat…</BarisKosong>
          <BarisKosong v-if="props.memuatDetail" :kolom="KOLOM">Memuat rincian…</BarisKosong>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
/* Denda bukan angka biasa — ia satu-satunya nilai yang bertambah sendiri, jadi
   diberi warna peringatan supaya tidak terlewat saat memindai daftar. Warna ini
   sengaja berbeda dari portal penghuni (yang memakai `--bahaya`): di layar
   pemilik denda adalah hal yang wajar, bukan kesalahan. */
.sel-denda {
  color: #8a5a00;
  font-weight: 600;
}

/* Sisa yang masih harus masuk. Ditebalkan karena inilah angka yang dicari
   pemilik saat membuka halaman ini; pokok dan total hanya konteks. */
.sel-sisa {
  font-weight: 600;
}
</style>
