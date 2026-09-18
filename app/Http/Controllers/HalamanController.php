<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Penyaji halaman statis. Satu halaman per peran, tanpa build step.
 *
 * Halamannya statis, tapi rujukan asetnya DICAP VERSI di sini sebelum dikirim.
 * Alasannya soal kecepatan di sambungan buruk: tanpa cap, satu-satunya cara
 * menjamin pembaruan terlihat adalah menyuruh peramban memvalidasi ulang setiap
 * aset pada setiap kali halaman dibuka — enam permintaan bolak-balik sebelum
 * aplikasinya mulai jalan. Dengan cap, aset boleh disimpan permanen dan tetap
 * berganti sendiri begitu kodenya berubah, karena URL-nya ikut berubah.
 *
 * Capnya disisipkan server, bukan ditulis tangan di HTML: yang ditulis tangan
 * akan lupa diperbarui tepat pada saat paling penting — ketika ada perbaikan
 * yang harus segera sampai ke semua orang.
 */
class HalamanController
{
    public function index(): Response
    {
        return $this->sajikan('index.html');
    }

    public function ortu(): Response
    {
        return $this->sajikan('ortu.html');
    }

    public function kios(): Response
    {
        return $this->sajikan('kios.html');
    }

    private function sajikan(string $berkas): Response
    {
        $html = $this->capAset(file_get_contents(public_path($berkas)));

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // Halaman ini berubah setiap kali aplikasinya diperbarui, dan
            // peramban kios jarang di-refresh manual. Halamannya sendiri kecil
            // (~3 KB); yang berat adalah asetnya, dan itu sudah bercap versi.
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * Sisipkan `?v=…` pada setiap rujukan aset lokal.
     *
     * Hanya jalur yang diawali "/" yang disentuh, sehingga URL pihak ketiga
     * (fonta Google, misalnya) tidak ikut berubah dan tidak rusak.
     */
    private function capAset(string $html): string
    {
        return preg_replace_callback(
            '#\b(src|href)="(/[A-Za-z0-9._/-]+\.(?:js|css|png|jpe?g|svg|ico|webp))"#',
            fn ($m) => $m[1] . '="' . $m[2] . '?v=' . self::cap($m[2]) . '"',
            $html
        ) ?? $html;
    }

    /**
     * Cap versi satu berkas.
     *
     * Dihitung dari waktu ubah + ukuran, bukan dari isi berkasnya. Membaca
     * 235 KB untuk di-hash pada setiap permintaan halaman jauh lebih mahal
     * daripada manfaatnya, sementara dua versi berbeda yang kebetulan sama
     * ukurannya DAN sama detik ubahnya praktis tidak pernah terjadi pada
     * penerapan yang sebenarnya.
     */
    private static function cap(string $jalur): string
    {
        static $memo = [];
        if (isset($memo[$jalur])) {
            return $memo[$jalur];
        }
        $f = public_path(ltrim($jalur, '/'));

        return $memo[$jalur] = is_file($f)
            ? substr(md5(filemtime($f) . ':' . filesize($f)), 0, 8)
            : 'x';
    }
}
