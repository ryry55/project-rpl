<?php

namespace App\Http\Resources;

use App\Domain\Akses\Wewenang;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Akun yang sedang masuk.
 *
 * `wewenang` ikut dikirim supaya layar bisa menyembunyikan menu yang tidak
 * boleh dipakai. Itu **bukan** pengamanan - server tetap memeriksa setiap
 * permintaan - melainkan kesopanan: menampilkan tombol yang pasti ditolak
 * membuat pengelola mengira aplikasinya rusak. Pengamannya tetap middleware
 * `wewenang:` di rute.
 *
 * Daftar wewenang selalu disertakan, termasuk untuk pemilik, dalam bentuk
 * seluruh wewenang yang ada. Layar jadi cukup bertanya "apakah `ubah_tarif` ada
 * di daftar ini" tanpa perlu cabang "kalau pemilik, semua boleh" - cabang yang
 * mudah terlupa di satu tempat dan membuat menu pemilik menghilang.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->name,
            'email' => $this->email,
            'peran' => $this->peran,
            'penghuni_id' => $this->penghuni_id,
            'pemilik_id' => $this->pemilik_id,
            'wewenang' => $this->daftarWewenang(),
        ];
    }

    /**
     * @return list<string>
     */
    private function daftarWewenang(): array
    {
        if ($this->isPemilik()) {
            return Wewenang::SEMUA;
        }

        if (! $this->isPengelola()) {
            return [];
        }

        return $this->kewenangan->pluck('wewenang')->values()->all();
    }
}
