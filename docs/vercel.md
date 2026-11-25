# Deploy Vercel — catatan lapangan

Status: **hidup** (25 November 2026) di `https://kos-kosan-rosy.vercel.app`.

Cara kerjanya: Vercel **Container Runtime** membangun `Dockerfile.vercel`
(salinan `Dockerfile.prod`) dan menjalankannya sebagai satu fungsi — nginx,
php-fpm, penjadwal, dan antrean dalam satu container. Bukan runtime PHP
komunitas, dan bukan serverless per-berkas.

## Empat jebakan yang sudah menggigit

1. **Batas 12 Serverless Functions.** Vercel memindai folder `api/` sebagai
   direktori fungsi. Dengan `api/vendor` (45 MB, ribuan `.php`) ikut terunggah,
   deployment ditolak walau project sudah memakai Container Runtime. Jawaban
   benarnya **`.vercelignore` berisi `api/vendor`**, bukan memindahkan folder
   `api/` — vendor memang dipasang di dalam image.
   Bukti bisect: image nginx kosong tetap gagal (jadi pemindaian dari pohon
   berkas); `api/` di-ignore → lolos; hanya `api/vendor` di-ignore → lolos.

2. **Urutan `COPY` di Dockerfile menentukan vendor mana yang menang.**
   `COPY api/ ./` sesudah `COPY --from=php-deps ... vendor` menimpa vendor
   bersih dengan vendor host. Gejalanya `Class
   "NunoMaduro\Collision\...\CollisionServiceProvider" not found` saat
   `composer dump-autoload`. Di laptop tersembunyi karena `.dockerignore`
   menyaring `**/vendor/`; konteks build Vercel tidak. Urutan sekarang: kode
   dulu, `rm -rf vendor`, baru vendor bersih. Dijaga `Dockerfile.vercel`
   identik `Dockerfile.prod` di `periksa-profil-produksi.sh`.

3. **Framework project harus `container`.** Tanpa itu Vercel tetap menghitung
   folder `api/` sebagai fungsi: `vercel project update --framework container`.
   `vercel pull` perlu dijalankan sekali supaya `.vercel/project.json` memuat
   pengaturan itu.

4. **`migrate`/seeder dijalankan dari laptop, ke DB cloud.**
   `RUN_MIGRATIONS=false` supaya tiap cold start tidak mencoba migrasi serentak.
   Seeder demo **tidak boleh** dipakai (ia menulis sandi `password`); akun
   pemilik dibuat dengan `kos:buat-pemilik`, yang menandai sandi wajib-ganti.
   Gerbang ini terbukti bekerja: sebelum sandi diganti, semua endpoint menjawab
   **403**, bukan data.

## Yang tidak ikut live

| Fitur | Sebab |
|---|---|
| Simulasi tanggal denda | entrypoint menolak `APP_DEMO_MODE=true`; header `X-Simulasi-Tanggal` dimatikan di produksi |
| Email sungguhan | `MAIL_MAILER=log`; tautan aktivasi hanya muncul di log Vercel |
| Cron tagihan harian | butuh endpoint cron + Vercel Cron; belum dibuat (menunggu keputusan) |

## Perintah

```powershell
# environment (nilai rahasia dibaca dari berkas, tidak dari argumen)
npx vercel env add APP_KEY production < app-key.txt
npx vercel env add DB_URL production < db-url.txt
npx vercel env remove APP_URL --environment production --confirm-name APP_URL

# deploy
npx vercel deploy --prod --yes

# migrasi + akun, dijalankan dari laptop ke DB cloud
docker compose exec -T -e DB_URL=$uri api php artisan migrate --force
docker compose exec -T -e DB_URL=$uri api php artisan kos:buat-pemilik --nama="Pemilik Kos" --email=... --sandi=...
```

`npx vercel env rm --yes` tidak berlaku untuk agen: pakai `--confirm-name <NAMA>`.
