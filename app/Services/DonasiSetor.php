<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\DonasiProgram;
use App\Models\Siswa;
use App\Models\Transaksi;
use App\Support\WaktuIso;
use Illuminate\Support\Facades\DB;

/**
 * Donasi satu arah dari kartu — satu sumber dipakai bersama kios
 * (KiosController::donasi) dan portal pegawai, supaya kedua jalur selalu
 * sepakat soal pemotongan saldo & pencatatan buku besar. Validasi yang
 * sifatnya per-jalur (bentuk request, PIN, batas harian) tetap tinggal di
 * controller masing-masing — di sini murni eksekusi uangnya.
 */
class DonasiSetor
{
    public static function hariIni(int $siswaId): int
    {
        return (int) Transaksi::where('tipe', 'donasi')->where('siswa_id', $siswaId)
            ->where('created_at', '>=', WaktuIso::awalHariIni())->sum(DB::raw('-jumlah'));
    }

    /** WAJIB dipanggil dari dalam DB::transaction oleh pemanggilnya. */
    public static function setor(Siswa $s, DonasiProgram $p, int $jumlah, string $operator, ?string $txId = null): array
    {
        $ubah = Siswa::where('id', $s->id)->where('saldo', '>=', $jumlah)->where('status', 'aktif')
            ->update(['saldo' => DB::raw("saldo - {$jumlah}")]);
        if ($ubah === 0) {
            $cek = Siswa::find($s->id);
            if (! $cek) {
                throw new ApiError(404, 'Santri tidak ditemukan');
            }
            if ($cek->status !== 'aktif') {
                throw new ApiError(403, 'Kartu sedang diblokir — hubungi admin kantin');
            }
            throw new ApiError(402, 'Saldo tidak cukup (saldo ' . Rupiah::f($cek->saldo) . ')');
        }
        $s2 = Siswa::find($s->id);
        $trx = Transaksi::create([
            'tx_id' => $txId, 'tipe' => 'donasi', 'siswa_id' => $s2->id,
            'siswa_nama' => $s2->nama, 'kelas' => $s2->kelas,
            'jumlah' => -$jumlah, 'saldo_sesudah' => $s2->saldo,
            'operator' => $operator, 'keterangan' => 'Donasi ' . $p->nama,
            'donasi_id' => $p->id, 'created_at' => WaktuIso::sekarang(),
        ]);

        return ['saldo' => $s2->saldo, 'waktu' => $trx->created_at];
    }
}
