<?php

namespace App\Services;

use App\Models\Transaksi;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Penyaring riwayat yang dipakai bersama oleh daftar, ekspor, dan tampilan cetak.
 *
 * Pembatasan tenant dipasang di KUERI, bukan di tampilan: akun tenant tidak
 * pernah bisa membaca transaksi tenant lain, apa pun parameter yang dikirimnya.
 */
class SaringTransaksi
{
    private const TIPE_SAH = ['topup', 'beli', 'koreksi', 'donasi', 'biaya_admin',
        'transfer_keluar', 'transfer_masuk', 'iuran_keluar', 'iuran_masuk'];

    public static function bangun(User $u, array $q): Builder
    {
        $t = Transaksi::query();

        if ($u->role === 'tenant') {
            $t->where('tenant_id', $u->tenant_id);
        } elseif (! empty($q['tenant_id'])) {
            $t->where('tenant_id', (int) $q['tenant_id']);
        }

        // tipe boleh berisi beberapa nilai dipisah koma, mis. "topup,koreksi"
        // (dipakai panel penyesuaian saldo admin yang menggabungkan keduanya).
        if (! empty($q['tipe'])) {
            $tipe = array_values(array_intersect(explode(',', (string) $q['tipe']), self::TIPE_SAH));
            if ($tipe) {
                $t->whereIn('tipe', $tipe);
            }
        }
        if (! empty($q['dari'])) {
            $t->where('created_at', '>=', $q['dari']);
        }
        if (! empty($q['sampai'])) {
            $t->where('created_at', '<=', $q['sampai']);
        }
        if (! empty($q['kelas'])) {
            $t->where('kelas', strtoupper((string) $q['kelas']));
        }
        if (! empty($q['min'])) {
            $t->whereRaw('ABS(jumlah) >= ?', [(int) $q['min']]);
        }
        if (! empty($q['max'])) {
            $t->whereRaw('ABS(jumlah) <= ?', [(int) $q['max']]);
        }
        if (! empty($q['cari'])) {
            $like = '%' . $q['cari'] . '%';
            $t->where(fn ($w) => $w->where('siswa_nama', 'like', $like)
                ->orWhereExists(fn ($s) => $s->from('siswa')
                    ->whereColumn('siswa.id', 'transaksi.siswa_id')
                    ->where(fn ($x) => $x->where('nis', 'like', $like)->orWhere('card_uid', 'like', $like))));
        }

        return $t;
    }

    /** @return array{rows:\Illuminate\Support\Collection,total:int,total_masuk:int,total_belanja:int,page:int,limit:int} */
    public static function ambil(User $u, array $q, ?int $batasEkspor = null): array
    {
        $dasar = self::bangun($u, $q);
        $page = max(1, (int) ($q['page'] ?? 1));
        $limit = $batasEkspor ?: min(100, (int) ($q['limit'] ?? 25) ?: 25);

        $agg = (clone $dasar)
            ->selectRaw('COUNT(*) AS n')
            ->selectRaw('COALESCE(SUM(CASE WHEN jumlah > 0 THEN jumlah END), 0) AS total_masuk')
            ->selectRaw('COALESCE(SUM(CASE WHEN jumlah < 0 THEN -jumlah END), 0) AS total_belanja')
            ->first();

        $rows = (clone $dasar)->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)->offset($batasEkspor ? 0 : ($page - 1) * $limit)->get();

        return [
            'rows' => $rows,
            'total' => (int) $agg->n,
            'total_masuk' => (int) $agg->total_masuk,
            'total_belanja' => (int) $agg->total_belanja,
            'page' => $page,
            'limit' => $limit,
        ];
    }
}
