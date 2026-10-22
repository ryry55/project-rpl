## Agent skills

### Issue tracker

GitHub Issues, driven with the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Five canonical roles, each label string equal to its role name. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `GLOSSARY.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.

## Menjalankan dan memeriksa

Satu perintah memeriksa seluruh repo:

```powershell
powershell -ExecutionPolicy Bypass -File periksa.ps1
```

Ia menjalankan build image, tes PHP, pint, lalu seluruh uji browser dan uji balapan.
`-Cepat` melewati uji browser. **Pakai ini, jangan menjalankan tes satu per satu** —
urutan langkahnya menentukan, dan salah urutan menghasilkan hijau yang menyesatkan
(lihat di bawah).

### Lima jebakan yang sudah menggigit

- **Kode dibakar ke image, bukan di-mount.** `php artisan test` tanpa
  `docker compose build api` menguji kode LAMA dan memberi hasil hijau padahal
  perubahan belum masuk container. Tidak ada galat yang muncul. Hal yang sama
  berlaku untuk `web`. `periksa.ps1` melakukan build lebih dulu karena itu.
- **Tes harus lewat container.** PHP lokal (8.3.30) tidak punya `pdo_pgsql`:
  `docker compose exec api php artisan test`. Pengecualiannya pint, yang justru
  harus di HOST — di dalam container ia memeriksa kode lama.
- **Tab Chrome yatim merusak E2E berikutnya.** Bersihkan sebelum menjalankan:
  `Invoke-RestMethod http://127.0.0.1:9222/json/list`, lalu `/json/close/<id>`
  untuk tiap entri `page`. `periksa.ps1` melakukannya otomatis.
- **Database demo diubah oleh uji browser.** Jalankan
  `docker compose exec api php artisan migrate:fresh --seed` sebelum tiap skrip.
  Skrip asap adalah pengecualian: ia idempoten, jadi boleh dijalankan berulang
  tanpa menyegarkan database.
- **Perekam galat yang dipasang lewat `evaluasi` mati saat navigasi.** `Page.navigate`
  memuat ulang dokumen, sehingga `window.__rekam` hilang dan setiap halaman
  lulus dengan "tidak ada galat konsol" padahal tidak ada yang diperiksa.
  Pasang lewat `Page.addScriptToEvaluateOnNewDocument` (lihat `pasangPerekam` di
  `asap-semua-halaman.mjs`), dan sertakan kontrol negatif yang sengaja melempar
  galat untuk membuktikan perekam masih hidup.

### Menulis skrip uji

Skrip browser memakai `docs/uji/_cdp.mjs` — jangan menyalin helper CDP ke berkas
baru. Untuk memeriksa teks di layar pakai `teksMemuat(teks, '15.000')`, bukan
regex: `/15\\.000/` di dalam template literal berarti "backslash literal" dan
asersinya tidak pernah cocok. Kesalahan itu sudah terjadi tiga kali (T5, T6, T9).

### PowerShell

`git commit -m` memotong pesan panjang; tulis ke berkas, pakai `-F <berkas>`,
lalu hapus. `>` menulis UTF-16 (pakai `cmd /c "..." > berkas`). Menyalurkan
keluaran `gh` lewat `|` merusak UTF-8. Skrip `.ps1` di repo ini disimpan UTF-8
tanpa BOM dan dibaca Windows PowerShell 5.1 sebagai ANSI — jangan menaruh simbol
non-ASCII di dalamnya.

## Dokumen

- `SPEC.md` — kontrak implementasi: aturan bisnis, keputusan, model data, API.
- `docs/tiket.md` — daftar tiket dan urutan pengerjaan.
- `docs/uji/audit-ketertelusuran.md` — peta aturan → implementasi → tes.
- `docs/uji/README.md` — indeks skrip uji beserta prasyaratnya.
