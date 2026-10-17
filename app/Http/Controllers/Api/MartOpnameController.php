<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\MartOpname;
use App\Models\MartOpnameItem;
use App\Models\Produk;
use App\Models\Tenant;
use App\Services\Stok;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stok opname — verifikasi fisik barang, lalu rekonsiliasi.
 *
 * Alurnya tiga langkah, dan langkah terakhir sengaja TIDAK BISA DIULANG:
 *
 *   1. Mulai  — sistem memotret stok buku saat itu ke baris-baris opname.
 *   2. Hitung — staf mengisi stok fisik per barang (boleh bertahap).
 *   3. Selesaikan & rekonsiliasi — stok buku disesuaikan ke angka fisik,
 *      dan nilai selisihnya (× HPP) dicatat sebagai rugi/laba inventaris.
 *
 * Stok buku DIPOTRET DI LANGKAH 1 dan tidak diperbarui lagi sesudahnya:
 * kalau ada penjualan selama opname berlangsung, selisihnya harus terlihat
 * sebagai selisih — bukan ditutupi diam-diam oleh angka yang ikut bergerak.
 * Itu sebabnya opname sebaiknya dikerjakan saat toko tutup.
 */
class MartOpnameController extends Controller
{
    private string $operator = 'Kasir Mart';

    // "expired" (dulu "kedaluwarsa") — diseragamkan dengan istilah Expired yang
    // sudah dipakai di layar Barang & Stok (permintaan tim kasir, 17 Okt 2026).
    // Murni label teks bebas (kolom string biasa, bukan ENUM basis data), jadi
    // tidak perlu migrasi — dan belum ada baris opname yang memakai nilai lama.
    private const ALASAN = ['sesuai', 'rusak', 'hilang', 'expired', 'salah catat', 'lainnya'];

    private function martKu(Request $r): Tenant
    {
        $u = $this->wajibPeran($r, 'tenant');
        $this->operator = (string) ($u->nama ?: 'Kasir Mart');
        $t = Tenant::find($u->tenant_id);
        if (! $t || ($t->mode_kasir ?? 'menu') !== 'mart') {
            throw new ApiError(403, 'Akun ini bukan kasir IMBOS Mart');
        }

        return $t;
    }

    public function index(Request $r)
    {
        $t = $this->martKu($r);

        return response()->json([
            'berjalan' => $this->isi(MartOpname::where('tenant_id', $t->id)
                ->where('status', 'berjalan')->orderByDesc('id')->first()),
            'riwayat' => MartOpname::where('tenant_id', $t->id)->where('status', 'selesai')
                ->orderByDesc('id')->limit(20)->get(),
            'alasan' => self::ALASAN,
        ]);
    }

    private function isi(?MartOpname $o): ?array
    {
        if (! $o) {
            return null;
        }

        return array_merge($o->toArray(), [
            'item' => MartOpnameItem::where('opname_id', $o->id)->orderBy('nama')->get(),
        ]);
    }

    public function mulai(Request $r)
    {
        $t = $this->martKu($r);
        if (MartOpname::where('tenant_id', $t->id)->where('status', 'berjalan')->exists()) {
            throw new ApiError(409, 'Masih ada opname yang berjalan — selesaikan dulu');
        }

        $barang = Produk::where('tenant_id', $t->id)->where('aktif', 1)
            ->whereNotNull('stok')->orderBy('nama')->get();
        if ($barang->isEmpty()) {
            throw new ApiError(400, 'Belum ada barang berstok untuk diopname');
        }

        $o = DB::transaction(function () use ($t, $barang) {
            $o = MartOpname::create([
                'tenant_id' => $t->id, 'status' => 'berjalan', 'n_item' => $barang->count(),
                'operator' => $this->operator, 'created_at' => WaktuIso::sekarang(),
            ]);
            foreach ($barang as $p) {
                MartOpnameItem::create([
                    'opname_id' => $o->id, 'produk_id' => $p->id,
                    'barcode' => (string) ($p->barcode ?? ''), 'nama' => $p->nama,
                    'stok_sistem' => (int) $p->stok, 'stok_fisik' => null,
                    // HPP disalin: audit bulan ini harus tetap terbaca dengan
                    // harga modal bulan ini, walau modalnya berubah kemudian.
                    'hpp' => (int) ($p->hpp ?? 0), 'alasan' => 'sesuai',
                ]);
            }

            return $o;
        });

        return response()->json(['ok' => true, 'opname' => $this->isi($o->fresh())]);
    }

    /** Isi hitungan fisik — boleh sebagian, boleh berkali-kali. */
    public function isiHitungan(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $o = MartOpname::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $o) {
            throw new ApiError(404, 'Opname tidak ditemukan');
        }
        if ($o->status !== 'berjalan') {
            throw new ApiError(409, 'Opname ini sudah selesai — mulai sesi baru untuk menghitung lagi');
        }

        $baris = is_array($r->input('item')) ? $r->input('item') : [];
        if (! $baris) {
            throw new ApiError(400, 'Tidak ada baris yang dikirim');
        }

        DB::transaction(function () use ($baris, $o) {
            foreach ($baris as $b) {
                $it = MartOpnameItem::where('id', (int) ($b['id'] ?? 0))
                    ->where('opname_id', $o->id)->first();
                if (! $it) {
                    continue;
                }
                $mentah = $b['stok_fisik'] ?? null;
                $fisik = $mentah === null || $mentah === ''
                    ? null : max(0, (int) floor((float) $mentah));
                $selisih = $fisik === null ? 0 : $fisik - $it->stok_sistem;
                $alasan = in_array($b['alasan'] ?? '', self::ALASAN, true) ? $b['alasan'] : $it->alasan;
                $it->update([
                    'stok_fisik' => $fisik, 'selisih' => $selisih,
                    'nilai_selisih' => $selisih * (int) $it->hpp,
                    'alasan' => $selisih === 0 ? 'sesuai' : ($alasan === 'sesuai' ? 'lainnya' : $alasan),
                ]);
            }
            $this->hitungUlang($o);
        });

        return response()->json(['ok' => true, 'opname' => $this->isi($o->fresh())]);
    }

    private function hitungUlang(MartOpname $o): void
    {
        $agg = MartOpnameItem::where('opname_id', $o->id)
            ->selectRaw('COUNT(*) n')
            ->selectRaw('SUM(CASE WHEN selisih > 0 THEN 1 ELSE 0 END) n_surplus')
            ->selectRaw('SUM(CASE WHEN selisih < 0 THEN 1 ELSE 0 END) n_defisit')
            ->selectRaw('COALESCE(SUM(CASE WHEN selisih > 0 THEN nilai_selisih END),0) nilai_surplus')
            ->selectRaw('COALESCE(SUM(CASE WHEN selisih < 0 THEN -nilai_selisih END),0) nilai_defisit')
            ->first();

        $o->update([
            'n_item' => (int) $agg->n, 'n_surplus' => (int) $agg->n_surplus,
            'n_defisit' => (int) $agg->n_defisit,
            'nilai_surplus' => (int) $agg->nilai_surplus,
            'nilai_defisit' => (int) $agg->nilai_defisit,
            'nilai_bersih' => (int) $agg->nilai_surplus - (int) $agg->nilai_defisit,
        ]);
    }

    /**
     * Selesaikan & rekonsiliasi otomatis.
     *
     * Baris yang BELUM dihitung (stok_fisik NULL) sengaja dibiarkan apa
     * adanya — tidak dianggap nol. Menganggapnya nol akan menghapus seluruh
     * stok barang yang kebetulan belum sempat dihitung staf, dan itu
     * kerusakan yang jauh lebih mahal daripada opname yang belum lengkap.
     */
    public function selesaikan(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $o = MartOpname::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $o) {
            throw new ApiError(404, 'Opname tidak ditemukan');
        }
        if ($o->status !== 'berjalan') {
            throw new ApiError(409, 'Opname ini sudah selesai');
        }

        $belum = MartOpnameItem::where('opname_id', $o->id)->whereNull('stok_fisik')->count();
        if ($belum > 0 && $r->input('abaikan_belum') !== true) {
            throw new ApiError(409, "Masih ada {$belum} barang yang belum dihitung fisiknya. " .
                'Selesaikan hitungannya, atau centang "lanjutkan tanpa yang belum dihitung" — ' .
                'stok barang itu akan dibiarkan apa adanya.');
        }

        $op = $this->operator;
        DB::transaction(function () use ($o, $op) {
            $ubah = MartOpnameItem::where('opname_id', $o->id)
                ->whereNotNull('stok_fisik')->where('selisih', '!=', 0)->get();
            foreach ($ubah as $it) {
                if (! $it->produk_id) {
                    continue;
                }
                Stok::opname($it->produk_id, (int) $it->selisih, 'opname-' . $o->id,
                    'opname: ' . $it->alasan, $op);
            }
            $this->hitungUlang($o);
            $o->update([
                'status' => 'selesai',
                'catatan' => mb_substr(trim((string) request()->input('catatan', '')), 0, 300),
                'selesai_pada' => WaktuIso::sekarang(),
            ]);
        });

        return response()->json(['ok' => true, 'opname' => $this->isi($o->fresh()), 'belum_dihitung' => $belum]);
    }

    public function batal(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $o = MartOpname::where('id', $id)->where('tenant_id', $t->id)->where('status', 'berjalan')->first();
        if (! $o) {
            throw new ApiError(404, 'Opname berjalan tidak ditemukan');
        }
        MartOpnameItem::where('opname_id', $o->id)->delete();
        $o->delete();

        return response()->json(['ok' => true]);
    }

    public function detail(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $o = MartOpname::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $o) {
            throw new ApiError(404, 'Opname tidak ditemukan');
        }

        return response()->json(['opname' => $this->isi($o)]);
    }
}
