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
        return self::simpanGambarLengkap($dataUrl, $awalan)['nama'];
    }

    /**
     * Sama seperti simpanGambarBase64, tapi ikut mengembalikan sidik jari isinya.
     *
     * Sidik jari dipakai pemanggil yang perlu mengenali gambar yang SAMA PERSIS
     * dikirim dua kali — mis. satu bukti transfer dipakai untuk dua pengajuan.
     */
    public static function simpanGambarLengkap(string $dataUrl, string $awalan, int $minSisi = 0): array
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

        [$lebar, $tinggi] = self::wajibUtuh($isi, $minSisi);

        $nama = $awalan . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
        Storage::disk('unggahan')->put($nama, $isi);

        return ['nama' => $nama, 'sidik' => hash('sha256', $isi), 'byte' => strlen($isi),
            'lebar' => $lebar, 'tinggi' => $tinggi];
    }

    /**
     * Pastikan gambarnya benar-benar UTUH, bukan sekadar berawalan benar.
     *
     * Ini bukan kehati-hatian berlebihan — kejadiannya nyata. Sebuah bukti
     * transfer pernah tersimpan hanya 62 byte: kepala PNG-nya lengkap dan
     * menyebut ukuran 720x2249, tapi isinya terpotong. Sistem menerimanya tanpa
     * bersuara, dan barulah berhari-hari kemudian admin mendapati gambarnya
     * tidak bisa dibuka — pada saat wali sudah pulang dan uangnya sudah
     * ditransfer. Penyebab paling sering: berkas dipilih dari galeri ponsel
     * ketika unduhannya belum selesai, hal biasa di sinyal yang seadanya.
     *
     * getimagesize() SAJA TIDAK CUKUP: ia hanya membaca kepala berkas dan tetap
     * melaporkan ukuran gambar yang terpotong dengan gembira. Yang menyatakan
     * kebenaran adalah pembacaan seluruh piksel, dan itulah yang dilakukan di
     * sini — sekali, saat diunggah, selagi walinya masih di depan layar dan
     * bisa langsung mengulang.
     */
    private static function wajibUtuh(string $isi, int $minSisi): array
    {
        $ukur = @getimagesizefromstring($isi);
        if ($ukur === false) {
            throw new ApiError(422, 'Berkas itu bukan gambar yang bisa dibaca. ' .
                'Unggah berkas gambar (PNG/JPG/WebP).');
        }

        /*
         * INI pemeriksaan yang menyatakan kebenarannya, dan tidak ada
         * penggantinya yang lebih murah: seluruh piksel benar-benar dibaca.
         * Berkas 62 byte di atas lolos getimagesize dengan nilai 720x2249 dan
         * gagal di sini — persis seperti yang seharusnya.
         */
        $im = @imagecreatefromstring($isi);
        if ($im === false) {
            throw new ApiError(422, 'Gambar terpotong atau rusak sehingga tidak bisa dibuka. ' .
                'Pastikan berkasnya sudah selesai terunduh di ponsel Anda, lalu unggah ulang.');
        }
        imagedestroy($im);

        [$lebar, $tinggi] = [(int) $ukur[0], (int) $ukur[1]];
        if ($minSisi > 0 && min($lebar, $tinggi) < $minSisi) {
            throw new ApiError(422, "Gambarnya terlalu kecil ({$lebar}×{$tinggi} piksel) untuk " .
                'bisa dibaca sebagai bukti. Unggah foto atau tangkapan layar yang utuh.');
        }

        return [$lebar, $tinggi];
    }

    public static function hapus(?string $nama): void
    {
        if ($nama) {
            Storage::disk('unggahan')->delete($nama);
        }
    }
}
