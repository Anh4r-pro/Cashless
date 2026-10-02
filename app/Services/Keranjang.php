<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\Produk;
use App\Models\Tenant;

/**
 * Mengubah rincian keranjang dari kasir menjadi angka yang layak dipercaya.
 *
 * Harga SELALU diambil dari tabel produk, tidak pernah dari kiriman klien:
 * layar kasir boleh keliru atau tertinggal versi, tetapi yang menagih santri
 * harus angka yang tercatat di basis data.
 *
 * Yang menentukan kategori makanan/non-makanan juga kolom produk.kategori —
 * ditetapkan superadmin, bukan tenant. Kalau tenant boleh menandai sendiri,
 * setiap barang akan ditandai "makanan" agar lolos batas khusus, dan fitur
 * pemisahan jatah itu kehilangan gunanya.
 */
class Keranjang
{
    public const MAKS_BARIS = 60;

    public const MAKS_QTY = 99;

    /**
     * Mode kasir tenant — lihat migrasi mode_kasir_tenant.
     * 'mart' memakai layar swalayan tersendiri (pindai barcode, qty, tunai).
     */
    public const MODE_KASIR = ['menu', 'bebas', 'mart'];

    /** Deskripsi terpendek yang masih bermakna di riwayat santri ("Pulpen" lolos, "a" tidak). */
    public const MIN_DESKRIPSI = 3;

    /**
     * Bolehkah tenant ini menjual lewat nominal ketik-sendiri?
     *
     * Mode 'bebas' selalu boleh, apa pun isi sakelarnya: di mode itu SELURUH
     * penjualan lewat jalur manual, jadi mematikannya sama dengan mematikan
     * kasirnya — kesalahan yang mudah terjadi karena kedua pengaturan ini
     * bersebelahan di layar admin.
     */
    public static function bolehManual(Tenant $t): bool
    {
        return ($t->mode_kasir ?? 'menu') === 'bebas' || (bool) ($t->input_manual ?? true);
    }

    public static function ringkas(array $items, Tenant $tenant, array $cfg): array
    {
        if (! $items) {
            throw new ApiError(400, 'Rincian belanja kosong');
        }
        if (count($items) > self::MAKS_BARIS) {
            throw new ApiError(400, 'Maksimal ' . self::MAKS_BARIS . ' baris dalam satu transaksi');
        }

        $total = 0;
        $nonmakanan = 0;
        $rincian = [];

        foreach ($items as $it) {
            $qty = isset($it['qty']) ? (int) floor((float) $it['qty']) : 1;
            if ($qty < 1 || $qty > self::MAKS_QTY) {
                throw new ApiError(400, 'Jumlah barang harus 1–' . self::MAKS_QTY);
            }

            if (isset($it['produk_id']) && $it['produk_id'] !== null) {
                $pr = Produk::where('id', (int) $it['produk_id'])
                    ->where('tenant_id', $tenant->id)->first();
                if (! $pr) {
                    throw new ApiError(404, 'Ada menu dalam keranjang yang bukan milik tenant ini');
                }
                if (! $pr->aktif) {
                    throw new ApiError(409, "\"{$pr->nama}\" sedang ditandai habis — hapus dari keranjang");
                }
                $nama = $pr->nama;
                $harga = (int) $pr->harga;
                $kategori = $pr->kategori;
            } else {
                // Input manual: kasir menyebut kategorinya sendiri, dan
                // non-makanan hanya boleh di tenant koperasi.
                //
                // Pagarnya ADA DI SINI, bukan sekadar menyembunyikan kotaknya
                // di layar: menyembunyikan tombol tidak pernah menjadi
                // pengamanan, dan kiriman /api/beli bisa disusun siapa saja
                // yang memegang sesi kasir.
                if (! self::bolehManual($tenant)) {
                    throw new ApiError(403,
                        'Tenant ini hanya boleh menjual menu yang sudah disetujui admin. ' .
                        'Mintalah admin menyalakan "input manual" bila memang diperlukan.');
                }
                $harga = (int) floor((float) ($it['harga'] ?? 0));
                if ($harga <= 0) {
                    throw new ApiError(400, 'Nominal input manual tidak valid');
                }
                $deskripsi = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($it['nama'] ?? ''))), 0, 60);
                // Di mode bebas SEMUA barang lewat jalur ini, jadi deskripsi
                // diwajibkan: tanpanya riwayat santri dan portal wali hanya
                // berisi deretan "Input manual" yang tidak menjelaskan apa pun.
                if (($tenant->mode_kasir ?? 'menu') === 'bebas' && mb_strlen($deskripsi) < self::MIN_DESKRIPSI) {
                    throw new ApiError(400, 'Tulis apa yang dibeli (minimal ' . self::MIN_DESKRIPSI .
                        ' huruf) — tenant ini memakai mode kasir tanpa menu');
                }
                $nama = $deskripsi !== '' ? $deskripsi : 'Input manual';
                $kategori = (($it['kategori'] ?? '') === 'non_makanan') ? 'non_makanan' : 'makanan';
                if ($kategori === 'non_makanan' && $tenant->jenis !== 'koperasi') {
                    throw new ApiError(400, 'Hanya tenant koperasi yang boleh menjual barang non-makanan');
                }
            }

            $subtotal = $harga * $qty;
            $total += $subtotal;
            // Sakelar mati = semua barang diperlakukan sebagai makanan, persis
            // seperti sebelum fitur ini ada.
            if ($kategori === 'non_makanan' && ($cfg['nonmakanan_aktif'] ?? 0) === 1) {
                $nonmakanan += $subtotal;
            }

            $rincian[] = [
                'produk_id' => isset($it['produk_id']) && $it['produk_id'] !== null ? (int) $it['produk_id'] : null,
                'nama' => $nama, 'harga' => $harga, 'qty' => $qty,
                'subtotal' => $subtotal, 'kategori' => $kategori,
            ];
        }

        // Keterangan disusun di SERVER dari nama produk yang benar-benar terpakai,
        // jadi baris riwayat tidak bisa menyebut barang yang tidak dibeli.
        $keterangan = mb_substr(implode(', ', array_map(
            fn ($r) => $r['qty'] > 1 ? "{$r['nama']} ×{$r['qty']}" : $r['nama'],
            $rincian,
        )), 0, 300);

        return compact('total', 'nonmakanan', 'rincian', 'keterangan');
    }
}
