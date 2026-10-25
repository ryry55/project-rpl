"""Membuat PNG kode QR sungguhan untuk gambar uji.

Dipakai supaya tangkapan layar bukti menampilkan kode QR yang benar-benar bisa
dipindai, bukan kotak 1x1 piksel yang secara teknis "gambar termuat" tetapi
tidak membuktikan apa pun kepada orang yang melihat tangkapan layarnya.

Implementasi QR lengkap tidak ditulis di sini - itu ratusan baris dan bukan
yang sedang diuji. Yang dipakai adalah pustaka `segno` bila ada. Bila tidak ada,
skrip ini gagal dengan pesan yang jelas alih-alih diam-diam membuat gambar
palsu: gambar palsu yang tampak seperti QR akan membuat bukti uji menyesatkan.
"""

import sys

try:
    import segno
except ImportError:
    sys.exit(
        "segno belum terpasang. Jalankan: py -m pip install segno\n"
        "(Tanpa itu, gambar QRIS uji tidak bisa dibuat.)"
    )


def buat(teks: str, jalur: str) -> None:
    """Tulis kode QR sebagai PNG hitam-putih."""
    qr = segno.make(teks, error="m")
    # `scale=8` membuat modul cukup besar untuk dipindai dari layar ponsel.
    # `border=2` adalah quiet zone minimal agar pemindai bisa menemukan tepinya.
    qr.save(jalur, kind="png", scale=8, border=2, dark="#111111", light="#ffffff")
    print(f"dibuat: {jalur}")


if __name__ == "__main__":
    tujuan = sys.argv[1] if len(sys.argv) > 1 else "qris-uji.png"
    buat("QRIS UJI PEMBAYARAN KOS-KOSAN", tujuan)
