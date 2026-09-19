<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\DanaAdminMutasi;
use App\Models\Siswa;
use App\Models\Transaksi;
use App\Support\WaktuIso;
use Illuminate\Support\Facades\DB;

/**
 * Pos dana milik sekolah, tempat bermuaranya biaya admin.
 *
 * Perlu ditegaskan karena mudah disalahpahami: ini BUKAN rekening bank.
 * Uangnya tetap berada di rekening sekolah yang sama, bercampur dengan
 * titipan wali. Yang dilacak di sini adalah berapa bagian dari saldo rekening
 * itu yang sudah menjadi hak sekolah dan boleh dipakai — sisanya tetap uang
 * orang lain yang kebetulan dititipkan di tempat yang sama.
 *
 * Karena itu "menarik" dari sini tidak memindahkan apa pun secara otomatis:
 * ia MENCATAT bahwa sekolah sudah mengambil uangnya. Pemindahan fisiknya
 * dikerjakan bendahara di bank, dan catatan di sini yang membuatnya
 * dipertanggungjawabkan.
 */
class DanaAdmin
{
    public static function saldo(): int
    {
        $r = DanaAdminMutasi::selectRaw(
            "COALESCE(SUM(CASE WHEN jenis='masuk' THEN jumlah ELSE -jumlah END),0) AS s")->first();

        return (int) $r->s;
    }

    /** Ringkasan untuk layar: posisi, total masuk, total tarik. */
    public static function ringkas(): array
    {
        $r = DanaAdminMutasi::selectRaw("COALESCE(SUM(CASE WHEN jenis='masuk' THEN jumlah END),0) AS masuk")
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis='tarik' THEN jumlah END),0) AS tarik")
            ->selectRaw("COUNT(CASE WHEN jenis='masuk' THEN 1 END) AS n_masuk")
            ->selectRaw("COUNT(CASE WHEN jenis='tarik' THEN 1 END) AS n_tarik")
            ->first();

        return [
            'masuk' => (int) $r->masuk,
            'tarik' => (int) $r->tarik,
            'saldo' => (int) $r->masuk - (int) $r->tarik,
            'n_masuk' => (int) $r->n_masuk,
            'n_tarik' => (int) $r->n_tarik,
        ];
    }

    /**
     * Rekap per bulan — yang dipakai bendahara untuk pembukuan.
     *
     * Angka total seumur sistem tidak berguna untuk laporan bulanan, dan
     * tanpa ini bendahara harus membuka Riwayat, menyaring tipe & tanggal,
     * lalu menjumlahkan sendiri setiap akhir bulan.
     */
    public static function perBulan(int $bulan = 12): array
    {
        return DanaAdminMutasi::selectRaw('SUBSTRING(created_at, 1, 7) AS periode')
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis='masuk' THEN jumlah END),0) AS masuk")
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis='tarik' THEN jumlah END),0) AS tarik")
            ->selectRaw("COUNT(CASE WHEN jenis='masuk' THEN 1 END) AS n_pungut")
            ->groupBy('periode')->orderByDesc('periode')->limit($bulan)->get()
            ->map(fn ($r) => [
                'periode' => $r->periode,
                'masuk' => (int) $r->masuk,
                'tarik' => (int) $r->tarik,
                'n_pungut' => (int) $r->n_pungut,
            ])->all();
    }

    /**
     * Catat biaya yang masuk ke dana.
     *
     * `ref` unik membuat pemanggilan berulang tidak menambah dana dua kali —
     * penting karena persetujuan pengajuan bisa saja terulang karena klik
     * ganda, dan wali hanya dipungut sekali.
     */
    public static function terima(int $jumlah, Siswa $s, ?Transaksi $tx, string $operator, string $ref): ?DanaAdminMutasi
    {
        if ($jumlah <= 0) {
            return null;
        }
        if (DanaAdminMutasi::where('ref', $ref)->exists()) {
            return null;
        }

        return DanaAdminMutasi::create([
            'jenis' => 'masuk',
            'jumlah' => $jumlah,
            'saldo_sesudah' => self::saldo() + $jumlah,
            'ref' => $ref,
            'transaksi_id' => $tx?->id,
            'siswa_id' => $s->id,
            'operator' => $operator,
            'keterangan' => mb_substr("Biaya admin dari {$s->nama}", 0, 300),
            'created_at' => WaktuIso::sekarang(),
        ]);
    }

    /**
     * Sekolah mengambil uangnya.
     *
     * Tidak boleh melebihi yang terkumpul: dana ini bukan rekening yang bisa
     * minus, ia hanya pencatat berapa yang sudah menjadi hak sekolah. Menarik
     * lebih dari itu berarti mengambil uang titipan wali, dan angka negatif
     * di sini hanya akan menyamarkan kejadian itu.
     */
    public static function tarik(int $jumlah, string $operator, string $keterangan, ?string $bukti = null, ?string $ref = null): DanaAdminMutasi
    {
        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal penarikan harus lebih dari nol');
        }
        if (mb_strlen(trim($keterangan)) < 3) {
            throw new ApiError(400, 'Keterangan penarikan wajib diisi — dana ini harus bisa dipertanggungjawabkan');
        }

        return DB::transaction(function () use ($jumlah, $operator, $keterangan, $bukti, $ref) {
            if ($ref && DanaAdminMutasi::where('ref', $ref)->exists()) {
                throw new ApiError(409, 'Penarikan dengan penanda yang sama sudah tercatat');
            }
            $saldo = self::saldo();
            if ($jumlah > $saldo) {
                throw new ApiError(400, 'Penarikan ' . Rupiah::f($jumlah) .
                    ' melebihi Dana Admin yang terkumpul (' . Rupiah::f($saldo) . ')');
            }

            return DanaAdminMutasi::create([
                'jenis' => 'tarik',
                'jumlah' => $jumlah,
                'saldo_sesudah' => $saldo - $jumlah,
                'ref' => $ref,
                'operator' => $operator,
                'keterangan' => mb_substr(trim($keterangan), 0, 300),
                'bukti' => $bukti,
                'created_at' => WaktuIso::sekarang(),
            ]);
        });
    }
}
