# Dokumen Produk

Sembilan dokumen yang menjelaskan sistem ini dari masalah sampai operasi.

**Mulai dari [`KONTEKS_PROYEK.md`](KONTEKS_PROYEK.md).** Ia pintu masuknya dan
menjelaskan bagaimana delapan sisanya menjadi satu.

| # | Berkas | Menjawab |
|---|---|---|
| — | [`KONTEKS_PROYEK.md`](KONTEKS_PROYEK.md) | Apa proyek ini, untuk siapa, sampai mana, apa berikutnya |
| 1 | [`1_PRD.md`](1_PRD.md) | Masalah apa yang dipecahkan, untuk siapa, dan bagaimana tahu sudah terpecahkan |
| 2 | [`2_DESIGN_SYSTEM.md`](2_DESIGN_SYSTEM.md) | Bagaimana antarmuka dibangun, dan aturan yang tidak boleh dilanggar |
| 3 | [`3_ARCHITECTURE.md`](3_ARCHITECTURE.md) | Bagaimana sistem dibentuk, dan mengapa begitu |
| 4 | [`4_AGENTS.md`](4_AGENTS.md) | Cara mengubah kode tanpa merusak apa pun |
| 5 | [`5_SCHEMAS.md`](5_SCHEMAS.md) | Bentuk data yang sebenarnya, dan penjaga yang menegakkannya |
| 6 | [`6_TOOLS_CONTRACTS.md`](6_TOOLS_CONTRACTS.md) | Endpoint apa yang ada, siapa boleh memanggil, apa yang kembali |
| 7 | [`7_TESTING_EVALS.md`](7_TESTING_EVALS.md) | Apa yang dibuktikan tes, dan bagaimana menambah tes yang menahan |
| 8 | [`8_OPERATIONS_SOP.md`](8_OPERATIONS_SOP.md) | Menjalankan, mencadangkan, memulihkan, memperbaiki |

## Urutan membaca menurut kebutuhan

| Kalau Anda… | Baca |
|---|---|
| baru bergabung | `KONTEKS_PROYEK.md` → `3_ARCHITECTURE.md` → `4_AGENTS.md` |
| akan mengubah kode | `4_AGENTS.md` → `5_SCHEMAS.md` → `7_TESTING_EVALS.md` |
| akan menambah endpoint | `6_TOOLS_CONTRACTS.md` → `5_SCHEMAS.md` → `4_AGENTS.md` |
| akan menambah halaman | `2_DESIGN_SYSTEM.md` (daftar periksa §8) → `7_TESTING_EVALS.md` |
| men-deploy | `8_OPERATIONS_SOP.md` |
| mengulas keputusan | `3_ARCHITECTURE.md` §3 (sembilan keputusan + alternatif yang ditolak) |
| menguji klaim | `7_TESTING_EVALS.md` §6 (U-1..U-9) |

## Prinsip yang menyatukan kesembilannya

Dokumen yang saling bertentangan lebih buruk daripada tidak ada dokumen. Karena
itu:

1. **Angka diambil dari sistem yang berjalan**, bukan dari ingatan. 81 endpoint
   dari `route:list --json`; 23 tabel domain dari `pg_attribute`; 809 tes dari
   `artisan test`.
2. **Setiap keputusan besar mencatat alternatif yang ditolak.** Keputusan tanpa
   alasannya akan diusulkan ulang, dan tanpa catatan tidak ada yang bisa
   menjelaskan mengapa dulu ditolak.
3. **Yang belum dikerjakan dicatat sebagai utang**, dengan tiga hal: apa yang
   kurang, kenapa ditunda, dan rencana penutupannya.
4. **Setiap pagar pernah dilihat gagal.** Asersi yang belum pernah merah mungkin
   hanya mengukur keadaan kosong.

Register utang teknis tinggal di [`../utang-teknis.md`](../utang-teknis.md).
