<?php

namespace App\Services;

use App\Models\Siswa;
use App\Support\WaktuIso;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Isi ulang otomatis kartu dari saldo titipan.
 *
 * Kartu hanya boleh memegang max_saldo, jadi kelebihan setoran orang tua
 * menunggu di titipan dan diteteskan ke kartu ketika saldonya menyentuh ambang.
 * Tujuannya satu: siswa yang titipannya masih banyak tidak boleh tertolak di
 * kasir hanya karena angka di kartunya sudah tipis.
 */
class SmartTopup
{
    /**
     * Aturan yang berlaku untuk satu siswa.
     *
     * Ambang dan nilai isi dimampatkan ke batas saldo yang berlaku SEKARANG,
     * supaya admin bebas menurunkan max_saldo tanpa harus menyetel ulang angka
     * tiap siswa: yang terjadi paling banyak kartu diisi sampai batas baru.
     */
    public static function aturan(Siswa $s, array $cfg): array
    {
        // 'ikut' = mengikuti sakelar sekolah; 'aktif'/'mati' = keputusan khusus siswa ini.
        $nyala = $s->smart_mode === 'aktif'
            || ($s->smart_mode !== 'mati' && ($cfg['smart_aktif'] ?? 0) === 1);
        $ambang = $s->smart_ambang !== null ? (int) $s->smart_ambang : (int) ($cfg['smart_ambang'] ?? 0);
        $isi = $s->smart_isi !== null ? (int) $s->smart_isi : (int) ($cfg['smart_isi'] ?? 0);
        $maks = (int) ($cfg['max_saldo'] ?? 0);

        return ['nyala' => $nyala, 'ambang' => min($ambang, $maks), 'isi' => min($isi, $maks)];
    }

    /**
     * Versi yang tidak pernah melempar galat.
     *
     * Isi ulang selalu dijalankan SETELAH mutasi utama tersimpan, jadi
     * kegagalannya tidak boleh membatalkan belanja atau top up yang sudah sah —
     * cukup dicatat ke log dan dilewati.
     */
    public static function diam(int $siswaId, string $pemicu): ?array
    {
        try {
            return self::jalankan($siswaId, $pemicu);
        } catch (\Throwable $e) {
            Log::error('smart top up gagal: ' . $e->getMessage());

            return null;
        }
    }

    public static function jalankan(int $siswaId, string $pemicu): ?array
    {
        return DB::transaction(function () use ($siswaId, $pemicu) {
            $s = Siswa::find($siswaId);
            if (! $s || $s->saldo_titipan <= 0) {
                return null;
            }
            // Kartu blokir/kedaluwarsa tidak diisi: uangnya aman menunggu di
            // titipan sampai kartunya dipulihkan atau titipannya ditarik admin.
            if ($s->status !== 'aktif' || Kartu::kedaluwarsa($s)) {
                return null;
            }
            $cfg = Pengaturan::untuk($s);
            $a = self::aturan($s, $cfg);
            if (! $a['nyala'] || $s->saldo > $a['ambang']) {
                return null;
            }

            $isi = min(max(0, $a['isi'] - $s->saldo), $s->saldo_titipan, $cfg['max_saldo'] - $s->saldo);
            if ($isi <= 0) {
                return null;
            }

            // Syarat di WHERE adalah remnya: kalau keadaan sudah berubah di
            // antara pembacaan dan penulisan, barisnya tidak tersentuh sama
            // sekali dan isi ulang dilewati — bukan dipaksakan.
            $ubah = Siswa::where('id', $siswaId)
                ->where('saldo_titipan', '>=', $isi)
                ->whereRaw('saldo + ? <= ?', [$isi, $cfg['max_saldo']])
                ->update([
                    'saldo' => DB::raw("saldo + {$isi}"),
                    'saldo_titipan' => DB::raw("saldo_titipan - {$isi}"),
                ]);
            if ($ubah === 0) {
                return null;
            }

            $baru = Siswa::find($siswaId);
            $ket = "Isi ulang otomatis dari saldo titipan ({$pemicu})";
            $trx = BukuBesar::catatTransaksi(
                'smart-' . $siswaId . '-' . (int) (microtime(true) * 1000),
                'topup', $baru, $isi, 'Sistem (smart top up)', $ket,
            );
            BukuBesar::catatTitipan($baru, 'isi_saldo', -$isi, 'Sistem (smart top up)', $ket, null, $trx->id);

            return [
                'isi' => $isi,
                'siswa' => $baru,
                'transaksi' => $trx,
                'sisa_titipan' => $baru->saldo_titipan,
            ];
        });
    }
}
