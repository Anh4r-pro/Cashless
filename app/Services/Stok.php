<?php

namespace App\Services;

use App\Models\MartStokMutasi;
use App\Models\Produk;
use App\Support\WaktuIso;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya pintu perubahan stok.
 *
 * Semua jalur — penjualan tunai, penjualan kartu, barang masuk, koreksi
 * admin, rekonsiliasi opname — lewat sini, supaya setiap angka stok selalu
 * punya baris riwayat yang menjelaskan asalnya. Pernah ada sistem stok yang
 * angkanya bisa berubah dari lima tempat berbeda; yang terjadi kemudian
 * adalah tak seorang pun bisa menjawab kenapa stoknya tinggal tiga.
 *
 * Barang dengan `stok` NULL TIDAK DILACAK (kantin biasa, gorengan dadakan):
 * jalur ini sengaja mendiamkannya, bukan memaksa angka nol.
 */
class Stok
{
    /**
     * Kurangi stok karena terjual. Dipanggil kasir Mart maupun kasir biasa.
     *
     * Stok TIDAK ditolak saat minus: penjualan sudah terjadi di dunia nyata,
     * dan menolak mencatatnya hanya membuat buku makin jauh dari kenyataan.
     * Yang benar adalah mencatat apa adanya, lalu stok minus itu muncul
     * mencolok di layar sebagai tanda ada yang perlu dibereskan.
     */
    public static function jual(int $produkId, int $qty, string $ref, string $operator): void
    {
        self::gerak($produkId, -abs($qty), 'jual', $ref, '', $operator);
    }

    /** Kembalikan stok (pembatalan penjualan). */
    public static function batal(int $produkId, int $qty, string $ref, string $operator): void
    {
        self::gerak($produkId, abs($qty), 'jual', $ref, 'pembatalan', $operator);
    }

    /** Barang masuk dari pemasok. */
    public static function masuk(int $produkId, int $qty, string $ket, string $operator): void
    {
        self::gerak($produkId, abs($qty), 'masuk', '', $ket, $operator);
    }

    /** Koreksi manual admin (bukan hasil opname). */
    public static function koreksi(int $produkId, int $qtySelisih, string $ket, string $operator): void
    {
        self::gerak($produkId, $qtySelisih, 'koreksi', '', $ket, $operator);
    }

    /** Penyesuaian hasil rekonsiliasi opname. */
    public static function opname(int $produkId, int $qtySelisih, string $ref, string $ket, string $operator): void
    {
        self::gerak($produkId, $qtySelisih, 'opname', $ref, $ket, $operator);
    }

    private static function gerak(int $produkId, int $qty, string $jenis,
        string $ref, string $ket, string $operator): void
    {
        if ($qty === 0) {
            return;
        }
        $p = Produk::find($produkId);
        // Tidak dilacak stoknya — diamkan, jangan diubah jadi angka.
        if (! $p || $p->stok === null) {
            return;
        }

        // Perubahan dilakukan di basis data (bukan hitung-lalu-simpan) supaya
        // dua kasir yang menjual barang terakhir bersamaan tidak saling menimpa.
        Produk::where('id', $produkId)->update(['stok' => DB::raw("stok + ({$qty})")]);
        $baru = (int) Produk::where('id', $produkId)->value('stok');

        MartStokMutasi::create([
            'tenant_id' => $p->tenant_id, 'produk_id' => $produkId, 'jenis' => $jenis,
            'qty' => $qty, 'stok_sesudah' => $baru,
            'nilai' => $qty * (int) ($p->hpp ?? 0),
            'ref' => mb_substr($ref, 0, 80), 'keterangan' => mb_substr($ket, 0, 200),
            'operator' => mb_substr($operator, 0, 120), 'created_at' => WaktuIso::sekarang(),
        ]);
    }

    /** Status rak untuk dipakai layar & peringatan: aman / menipis / habis. */
    public static function status(?int $stok, ?int $min): string
    {
        if ($stok === null) {
            return 'tak_dilacak';
        }
        if ($stok <= 0) {
            return 'habis';
        }

        return $stok <= (int) ($min ?? 5) ? 'menipis' : 'aman';
    }
}
