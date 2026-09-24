<?php

namespace App\Http\Controllers;

use App\Models\Siswa;
use App\Models\TopupRequest;
use App\Services\Sesi;
use App\Services\SesiKios;
use App\Http\Controllers\Api\TopupOrtuController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Penyaji berkas unggahan (foto santri, bukti setoran, gambar QRIS).
 *
 * Berkas ini TIDAK diletakkan di public/ karena tiap berkas punya pemiliknya:
 * bukti setoran seorang santri bukan berkas yang boleh dibuka siapa saja yang
 * menebak namanya. Staf yang sudah masuk boleh melihat semuanya; selain itu ada
 * dua perkecualian, masing-masing dibatasi berkas yang memang menjadi urusan
 * pemegang sesi itu sendiri:
 *
 *   - wali yang sedang masuk portal: foto anaknya, bukti bayar yang ia sendiri
 *     unggah, dan gambar QRIS sekolah yang sedang berlaku;
 *   - layar kios yang sedang memegang sesi tap: foto santri itu saja. Tokennya
 *     dikirim lewat ?kios=… karena tag <img> tidak bisa mengirim header.
 */
class UnggahanController
{
    public function ambil(Request $r, string $nama): Response
    {
        if (! Sesi::staf($r) && ! $this->bolehTanpaLogin($r, $nama)) {
            return response('login dulu', 401);
        }

        $disk = Storage::disk('unggahan');
        if (! $disk->exists($nama)) {
            return response('tidak ada', 404);
        }

        $kepala = [
            'Content-Type' => $disk->mimeType($nama) ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ];

        /*
         * ?unduh=1 membuat berkasnya TERSIMPAN, bukan tampil di tab baru.
         *
         * Dipakai wali untuk menyimpan gambar QRIS sekolah ke galerinya, supaya
         * bisa dibuka dari aplikasi bank tanpa harus membuka portal lagi —
         * memindai QRIS dari layar ponsel yang sama tidak bisa dilakukan.
         * Nama berkasnya dibuat berarti; nama acak di penyimpanan tidak
         * memberi tahu apa pun saat sudah ada di galeri.
         */
        if ($r->boolean('unduh')) {
            $ext = pathinfo($nama, PATHINFO_EXTENSION) ?: 'png';
            $ramah = str_starts_with($nama, 'qris-')
                ? 'QRIS-' . preg_replace('/[^A-Za-z0-9]+/', '-', config('app.name', 'Kantin')) . '.' . $ext
                : $nama;
            $kepala['Content-Disposition'] = 'attachment; filename="' . $ramah . '"';
        }

        return response($disk->get($nama), 200, $kepala);
    }

    private function bolehTanpaLogin(Request $r, string $berkas): bool
    {
        if ($ortu = Sesi::ortu($r)) {
            if ($ortu->foto === $berkas) {
                return true;
            }
            if ($berkas !== '' && $berkas === TopupOrtuController::info_()['qris_gambar']) {
                return true;
            }
            if (TopupRequest::where('siswa_id', $ortu->id)->where('bukti', $berkas)->exists()) {
                return true;
            }
        }

        if ($token = $r->query('kios')) {
            try {
                $siswa = SesiKios::ambil($token)['siswa'];
                if ($siswa->foto === $berkas) {
                    return true;
                }
            } catch (\Throwable) {
                // Sesi kios yang sudah mati bukan galat di sini — cukup ditolak.
            }
        }

        return false;
    }
}
