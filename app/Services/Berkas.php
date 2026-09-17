<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\Storage;

/**
 * Penyimpanan berkas unggahan (foto santri, bukti setoran, gambar QRIS).
 *
 * Unggahan datang sebagai data URL di dalam body JSON, bukan multipart —
 * bentuk itu dipertahankan karena frontend dan suite uji sudah memakainya.
 * Nama berkas SELALU diacak ulang: nama kiriman klien tidak pernah dipakai,
 * jadi tidak ada jalan menaruh berkas di luar direktori unggahan.
 */
class Berkas
{
    private const MAKS_BYTE = 3 * 1024 * 1024;

    public static function simpanGambarBase64(string $dataUrl, string $awalan): string
    {
        if (! preg_match('#^data:image/(png|jpe?g|webp);base64,(.+)$#s', $dataUrl, $m)) {
            throw new ApiError(400, 'Format gambar harus PNG/JPG/WebP (data URL)');
        }
        $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        $isi = base64_decode($m[2], true);
        if ($isi === false) {
            throw new ApiError(400, 'Data gambar rusak');
        }
        if (strlen($isi) > self::MAKS_BYTE) {
            throw new ApiError(413, 'Gambar maksimal 3MB');
        }

        $nama = $awalan . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        Storage::disk('unggahan')->put($nama, $isi);

        return $nama;
    }

    public static function hapus(?string $nama): void
    {
        if ($nama) {
            Storage::disk('unggahan')->delete($nama);
        }
    }
}
