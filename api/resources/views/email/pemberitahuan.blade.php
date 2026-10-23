{{-- Templat ini adalah badan email TEKS POLOS, jadi tidak ada `{{ }}` di
     sini. `{{ }}` meng-escape `&` menjadi `&amp;` dan `<` menjadi `&lt;`; di
     halaman HTML itu benar, tetapi di badan teks polos tidak ada yang
     menerjemahkannya kembali. Akibatnya tautan dengan dua parameter sampai
     rusak, dan nama kamar yang memuat `&` tampil sebagai `&amp;`.

     Tidak ada risiko injeksi di sini karena tidak ada yang dirender sebagai
     HTML: yang diketik pengguna muncul apa adanya, sebagai teks. --}}
{!! $judul !!}

{!! $pesan !!}

--
{!! $label !!} - Sistem Informasi Operasional Kos-Kosan

@if ($tautan)
Buka: {!! $alamat !!}{!! $tautan !!}
@else
Buka: {!! $alamat !!}
@endif

Email ini dikirim otomatis. Jejak resmi setiap perubahan ada di menu Jejak audit
di aplikasi, bukan di kotak masuk ini.
