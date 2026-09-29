<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\Golongan;
use App\Models\Siswa;

/**
 * Pembersihan dan pemeriksaan bidang identitas pemegang kartu.
 *
 * Dikumpulkan di sini karena dipakai oleh dua jalur (tambah & sunting), dan
 * aturan seperti "NIS wajib khas" harus berlaku sama di keduanya — NIS adalah
 * kunci masuk portal wali sekaligus kunci penerima transfer di kios.
 */
class BidangSiswa
{
    /**
     * Pilihan Unit untuk SDM & personil.
     *
     * Hanya MENGIKAT pada impor kolektif dan sebagai isi daftar pilihan di
     * layar. Endpoint satuan sengaja dibiarkan menerima nilai lain: data lama
     * (dan kartu tamu instansi) sudah terlanjur memakai unit di luar daftar
     * ini, dan menolaknya saat penyuntingan akan membuat baris lama mustahil
     * disimpan ulang.
     */
    public const UNIT_SDM = ['Pengasuhan', 'SMP', 'SMA', 'Ekskul', 'RT', 'LE', 'Lainnya'];

    /**
     * Bidang portal wali. NISN wajib khas karena dipakai sebagai kunci
     * pencarian saat wali murid masuk.
     */
    public static function ortu(array $b, ?Siswa $lama, ?int $siswaId): array
    {
        $nisn = array_key_exists('nisn', $b)
            ? preg_replace('/\D/', '', (string) $b['nisn']) : ($lama->nisn ?? '');
        $tgl = array_key_exists('tgl_lahir', $b)
            ? trim((string) $b['tgl_lahir']) : ($lama->tgl_lahir ?? '');
        $hp = array_key_exists('hp_ortu', $b)
            ? preg_replace('/\D/', '', (string) $b['hp_ortu']) : ($lama->hp_ortu ?? '');

        if ($nisn && (strlen($nisn) < 4 || strlen($nisn) > 20)) {
            throw new ApiError(400, 'NISN harus 4–20 angka');
        }
        if ($tgl && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
            throw new ApiError(400, 'Tanggal lahir harus format YYYY-MM-DD');
        }
        if ($tgl && ! strtotime($tgl)) {
            throw new ApiError(400, 'Tanggal lahir tidak valid');
        }
        if ($hp && (strlen($hp) < 8 || strlen($hp) > 15)) {
            throw new ApiError(400, 'Nomor HP wali harus 8–15 angka');
        }
        if ($nisn) {
            $q = Siswa::where('nisn', $nisn);
            if ($siswaId) {
                $q->where('id', '!=', $siswaId);
            }
            if ($bentrok = $q->first()) {
                throw new ApiError(409, "NISN sudah dipakai {$bentrok->nama}");
            }
        }

        return ['nisn' => $nisn, 'tgl_lahir' => $tgl, 'hp_ortu' => $hp];
    }

    /**
     * Bidang khas golongan. Golongan yang tidak dikenal DITOLAK, bukan
     * dibetulkan diam-diam: kartu dengan golongan salah ketik akan memakai
     * batas yang sama sekali lain dari yang dimaksud admin.
     */
    public static function golongan(array $b, ?Siswa $lama): array
    {
        $kode = array_key_exists('golongan', $b)
            ? trim((string) $b['golongan']) : ($lama->golongan ?? 'siswa');
        $g = Golongan::where('kode', $kode)->first();
        if (! $g) {
            throw new ApiError(400, "Golongan \"{$kode}\" tidak terdaftar");
        }
        if (! $g->aktif && $kode !== ($lama->golongan ?? '')) {
            throw new ApiError(400, "Golongan \"{$g->nama}\" sedang nonaktif");
        }

        $sampai = array_key_exists('berlaku_sampai', $b)
            ? trim((string) ($b['berlaku_sampai'] ?? '')) : ($lama->berlaku_sampai ?? '');
        if ($sampai && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
            throw new ApiError(400, 'Masa berlaku harus berupa tanggal (YYYY-MM-DD) atau dikosongkan');
        }

        return [
            'golongan' => $kode,
            'jabatan' => mb_substr(trim((string) ($b['jabatan'] ?? $lama->jabatan ?? '')), 0, 120),
            'catatan' => mb_substr(trim((string) ($b['catatan'] ?? $lama->catatan ?? '')), 0, 300),
            'berlaku_sampai' => $sampai,
            'biaya_admin' => self::biayaAdmin($b, $lama),
            // PJ iuran ditandai per ORANG, bukan per golongan — dua orang di
            // golongan SDM yang sama bisa saja hanya satu yang jadi PJ.
            'terima_iuran' => array_key_exists('terima_iuran', $b)
                ? (bool) $b['terima_iuran'] : (bool) ($lama->terima_iuran ?? false),
        ];
    }

    /**
     * Penimpa biaya admin per orang.
     *
     * Tiga keadaan yang BERBEDA dan mudah tertukar:
     *   tidak dikirim / ''  → NULL, artinya "ikut golongannya"
     *   0                   → dibebaskan dari biaya
     *   angka               → tarif khusus orang ini
     *
     * Karena itu string kosong TIDAK boleh dibaca sebagai nol: yang pertama
     * berarti mengikuti aturan umum, yang kedua berarti pengecualian yang
     * ditetapkan dengan sadar.
     */
    public static function biayaAdmin(array $b, ?Siswa $lama): ?int
    {
        if (! array_key_exists('biaya_admin', $b)) {
            return $lama->biaya_admin ?? null;
        }
        $v = $b['biaya_admin'];
        if ($v === null || $v === '' || $v === false) {
            return null;
        }
        if (! is_numeric($v)) {
            throw new ApiError(400, 'Biaya admin harus berupa angka, atau dikosongkan untuk mengikuti golongan');
        }
        $n = (int) floor((float) $v);
        if ($n < 0) {
            throw new ApiError(400, 'Biaya admin tidak boleh negatif');
        }

        return $n;
    }

    /**
     * NIS yang terisi wajib khas; yang kosong tetap diizinkan — basis data lama
     * boleh saja sudah terlanjur punya banyak NIS kosong.
     */
    public static function nisKhas(?string $nis, ?int $kecualiId): string
    {
        $v = trim((string) $nis);
        if ($v === '') {
            return $v;
        }
        $q = Siswa::where('nis', $v);
        if ($kecualiId) {
            $q->where('id', '!=', $kecualiId);
        }
        if ($bentrok = $q->first()) {
            throw new ApiError(409, "NIS {$v} sudah dipakai {$bentrok->nama}");
        }

        return $v;
    }

    public static function uid(?string $raw): ?string
    {
        $u = strtoupper((string) preg_replace('/[^0-9a-zA-Z]/', '', (string) $raw));

        return $u === '' ? null : $u;
    }
}
