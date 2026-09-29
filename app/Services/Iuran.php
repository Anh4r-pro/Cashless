<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\Siswa;
use App\Models\Transaksi;
use App\Support\WaktuIso;

/**
 * Iuran ke PJ (SDM/pegawai) lewat kios.
 *
 * Mesinnya sama persis dengan Transfer (kartu ke kartu, dua baris buku besar,
 * batas harian) — bedanya cuma cara memilih penerima dan tujuannya. Transfer
 * mencari lewat NIS yang diketik (santri hafal NIS teman sekelas); iuran
 * memilih dari DAFTAR yang sudah dikurasi admin lewat `terima_iuran`, karena
 * santri tidak mungkin hafal NIY seorang guru. Karena tujuannya beda (uang
 * jajan antar teman vs kas kelompok ke PJ yang jelas identitasnya), batasnya
 * pun sengaja dipisah — lihat migrasi `2026_09_29_000200_iuran_pj`.
 */
class Iuran
{
    public static function harusAktif(): void
    {
        if ((Pengaturan::sekolah()['iuran_aktif'] ?? 0) !== 1) {
            throw new ApiError(503, 'Iuran ke PJ sedang dimatikan admin');
        }
    }

    /** Daftar PJ yang boleh menerima iuran — dikurasi admin, bukan hasil pencarian. */
    public static function daftarPJ(): array
    {
        return Siswa::where('terima_iuran', 1)->where('status', 'aktif')
            ->orderBy('nama')->get(['id', 'nama', 'kelas', 'jabatan', 'golongan'])
            ->map(fn (Siswa $s) => [
                'id' => $s->id, 'nama' => $s->nama,
                'label' => trim((string) $s->jabatan) ?: (Pengaturan::golongan($s->golongan)?->nama ?? ''),
            ])->values()->all();
    }

    /** Penerima iuran yang sah — harus ada di daftar PJ, bukan sembarang siswa. */
    public static function penerima(int $id): Siswa
    {
        $s = Siswa::where('id', $id)->where('terima_iuran', 1)->where('status', 'aktif')->first();
        if (! $s) {
            throw new ApiError(404, 'Penerima iuran tidak ditemukan, atau sudah tidak lagi jadi PJ');
        }

        return $s;
    }

    /** Rekap kirim & terima iuran seseorang hari ini (untuk batas harian). */
    public static function hariIni(int $siswaId): array
    {
        $r = Transaksi::where('siswa_id', $siswaId)
            ->whereIn('tipe', ['iuran_keluar', 'iuran_masuk'])
            ->where('created_at', '>=', WaktuIso::awalHariIni())
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='iuran_keluar' THEN -jumlah END),0) AS kirim")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='iuran_keluar' THEN 1 END),0) AS n_kirim")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipe='iuran_masuk' THEN jumlah END),0) AS terima")
            ->first();

        return ['kirim' => (int) $r->kirim, 'n_kirim' => (int) $r->n_kirim, 'terima' => (int) $r->terima];
    }
}
