<?php

namespace App\Http\Resources;

use App\Models\Rekening;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rekening
 */
class RekeningResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'jenis' => $this->jenis,
            // Label dikirim server, bukan disusun layar: daftar kosakata jenis
            // rekening hanya boleh ada di satu tempat (`Rekening::LABEL_JENIS`),
            // sama seperti label metode pembayaran.
            'jenis_label' => Rekening::LABEL_JENIS[$this->jenis] ?? $this->jenis,
            'nama' => $this->nama,
            'nomor' => $this->nomor,
            'atas_nama' => $this->atas_nama,
            'catatan' => $this->catatan,

            // Gambar QRIS (utang teknis yang ditutup). Yang dikirim hanya
            // penanda dan alamatnya, bukan isinya: daftar rekening dengan lima
            // gambar 300 kB akan menjadi 1,5 MB respons yang langsung dibuang
            // layar. Isinya disajikan `/rekening/{id}/gambar` saat diminta.
            'punya_gambar' => $this->punyaGambar(),
            'gambar_mime' => $this->gambar_mime,
            'gambar_ukuran' => $this->gambar_ukuran === null ? null : (int) $this->gambar_ukuran,
            // **Path relatif, bukan URL absolut.** Rute ini butuh token, dan
            // `<img src>` tidak bisa mengirim header `Authorization` - jadi
            // gambarnya diambil lewat `fetch` ber-token lalu dijadikan blob.
            // Path relatif juga satu-satunya bentuk yang benar di dev, tempat
            // API dan SPA berbeda port: URL absolut dari `route()` akan memakai
            // `APP_URL` dan menunjuk alamat yang tidak melayani SPA.
            //
            // `null` saat tidak ada gambar - bukan path yang akan menjawab 404,
            // karena <img> yang menunjuk 404 menampilkan ikon rusak, bukan
            // ruang kosong.
            'gambar_path' => $this->punyaGambar()
                ? '/rekening/'.$this->id.'/gambar'
                : null,

            'aktif' => (bool) $this->aktif,
            'urutan' => (int) $this->urutan,
        ];
    }
}
