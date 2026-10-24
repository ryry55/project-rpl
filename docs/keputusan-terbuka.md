# Keputusan terbuka

> Pertanyaan yang menunggu jawaban pemilik. Dipakai oleh sesi yang **tidak punya
> sesi induk** — sesi opencode baru yang dibuka langsung di repo ini.
>
> Aturannya ada di `AGENTS.md` §"Kalau sesi butuh keputusan pemilik".
>
> **Jangan menunggu di dalam sesi.** Tulis pertanyaannya di sini, commit berkas
> ini sendirian, lalu berhenti. Tidak ada yang akan menjawab selama sesi masih
> terbuka.

## Bentuk entri

```
## K-01 · <judul singkat> · menunggu

**Fase:** R5 · **Tanggal:** 7 November 2026

**Pertanyaan.** Satu pertanyaan, dirumuskan sebagai pilihan.

**Pilihan.** A / B / C, masing-masing dengan akibatnya.

**Karena.** Mengapa ini tidak boleh diputuskan sendiri — sebutkan bagian
`SPEC.md` atau keputusan terkunci yang tersentuh.

**Kalau tidak dijawab.** Apa yang sesi lakukan: berhenti, atau kerjakan bagian
lain lebih dulu.
```

Sesudah dijawab, ubah `menunggu` menjadi `dijawab`, tambahkan tanggal dan
jawabannya di entri yang sama, lalu commit. Entri **tidak dihapus** — riwayat
keputusan ikut menjadi bukti, sama seperti `log_audit`.

---

## Belum ada

Belum ada pertanyaan yang menunggu jawaban. Fase R4 dikerjakan dengan instruksi
yang sudah lengkap, jadi tidak ada yang perlu diputuskan pemilik di luar sana.

Kalau entri pertama muncul, hapus bagian "Belum ada" ini.
