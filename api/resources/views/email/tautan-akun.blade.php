{{ $jenis === 'aktivasi' ? 'Aktifkan akun Kos-Kosan Anda' : 'Atur ulang kata sandi Kos-Kosan Anda' }}

Halo {{ $nama }},

@if ($jenis === 'aktivasi')
Pemilik kos Anda baru membuatkan akun untuk Anda di Sistem Informasi
Operasional Kos-Kosan. Sebelum tautan di bawah dibuka, akun ini belum bisa
dipakai masuk - dan tidak ada seorang pun, termasuk pemilik kos, yang tahu
kata sandi akun ini. Kata sandi ditetapkan oleh Anda sendiri pada langkah
berikutnya.
@else
Seseorang meminta pengaturan ulang kata sandi untuk akun ini. Bila itu Anda,
buka tautan di bawah dan tetapkan kata sandi baru.
@endif

{{-- `{!! !!}`, bukan `{{ }}`. Templat ini adalah teks polos, dan `{{ }}`
     meng-escape `&` menjadi `&amp;`. Di halaman HTML itu benar; di badan
     email teks polos tidak ada yang menerjemahkannya kembali, sehingga
     tautannya sampai ke penghuni sebagai
     `/atur-sandi?token=...&amp;jenis=aktivasi`. Server membacanya sebagai
     satu parameter bernama `amp;jenis`, `jenis` jatuh ke nilai bawaan
     `reset`, dan setiap tautan aktivasi dijawab 410 oleh pemeriksaan jenis.

     Alamatnya disusun server dari `config('app.url')` dan token acak, bukan
     dari masukan pengguna, jadi tidak ada yang perlu di-escape di sini. --}}
Buka: {!! $alamat !!}{!! $tautan !!}

Tautan ini berlaku sampai {{ $berlakuSampai->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB
dan hanya bisa dipakai satu kali.

@if ($jenis === 'reset')
Bila Anda tidak meminta pengaturan ulang, abaikan email ini. Kata sandi Anda
tidak berubah, dan tidak ada yang perlu Anda lakukan.
@else
Bila Anda merasa tidak mengenal pemilik kos yang mengirimi Anda email ini,
abaikan saja - akun tanpa aktivasi tidak bisa dipakai masuk oleh siapa pun.
@endif

--
Email ini dikirim otomatis. Jangan balas email ini.
