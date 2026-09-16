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
 * Penyaji berkas unggahan (foto siswa, bukti setoran, gambar QRIS).
 *
 * Berkas ini TIDAK diletakkan di public/ karena tiap berkas punya pemiliknya:
 * bukti setoran seorang siswa bukan berkas yang boleh dibuka siapa saja yang
 * menebak namanya. Staf yang sudah masuk boleh melihat semuanya; selain itu ada
 * dua perkecualian, masing-masing dibatasi berkas yang memang menjadi urusan
 * pemegang sesi itu sendiri:
 *
 *   - wali yang sedang masuk portal: foto anaknya, bukti bayar yang ia sendiri
 *     unggah, dan gambar QRIS sekolah yang sedang berlaku;
 *   - layar kios yang sedang memegang sesi tap: foto siswa itu saja. Tokennya
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

        return response($disk->get($nama), 200, [
            'Content-Type' => $disk->mimeType($nama) ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
