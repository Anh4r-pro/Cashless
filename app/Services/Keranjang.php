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
        // Kebutuhan stok dijumlahkan PER BARANG, bukan diperiksa per baris:
        // satu keranjang bisa memuat barang yang sama dalam dua satuan (3 pcs
        // + 1 dus), dan keduanya memakan stok yang sama.
        $butuh = [];
        $barang = [];

        foreach ($items as $it) {
            $qty = isset($it['qty']) ? (int) floor((float) $it['qty']) : 1;
            if ($qty < 1 || $qty > self::MAKS_QTY) {
                throw new ApiError(400, 'Jumlah barang harus 1–' . self::MAKS_QTY);
            }

            // Disetel ulang tiap putaran: tanpa ini, barang ketikan bebas yang
            // menyusul sebuah barang katalog akan terbaca sebagai barang itu.
            $pr = null;
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
                $kategori = $pr->kategori;

                /*
                 * Satuan: eceran (pcs) atau grosir (dus/karton).
                 *
                 * Keduanya satu barang dengan satu stok — yang berbeda hanya
                 * kemasan saat dijual. Harga & modalnya diambil dari kolom
                 * grosirnya sendiri, BUKAN dikalikan dari harga eceran: justru
                 * itu inti grosir, harganya per pcs lebih murah.
                 *
                 * `isi` dibawa keluar supaya pemanggil tahu berapa pcs yang
                 * harus dipotong dari stok, dan supaya bisa DIPOTRET di baris
                 * penjualan — kemasan pemasok bisa berubah kelak, dan nota
                 * yang sudah tercatat tidak boleh ikut berubah angkanya.
                 */
                $satuan = ($it['satuan'] ?? 'ecer') === 'grosir' ? 'grosir' : 'ecer';
                $isi = 1;
                if ($satuan === 'grosir') {
                    $isiMaster = (int) ($pr->isi_grosir ?? 0);
                    if ($isiMaster < 1 || $pr->harga_grosir === null) {
                        throw new ApiError(409, "\"{$pr->nama}\" belum disiapkan untuk dijual grosir — " .
                            'isi per grosir dan harga grosirnya belum diisi di Barang & Stok.');
                    }
                    $isi = $isiMaster;
                    $harga = (int) $pr->harga_grosir;
                    // Modal grosir dipakai apa adanya kalau diisi; kalau tidak,
                    // diturunkan dari modal eceran supaya labanya tetap terhitung.
                    $hpp = $pr->hpp_grosir !== null
                        ? (int) $pr->hpp_grosir
                        : ($pr->hpp === null ? null : (int) $pr->hpp * $isi);
                } else {
                    $harga = (int) $pr->harga;
                    // Harga modal ikut dibawa untuk DIPOTRET pada baris penjualan.
                    // Tanpa potret, laba bulan lalu ikut berubah setiap kali harga
                    // beli hari ini naik.
                    $hpp = $pr->hpp === null ? null : (int) $pr->hpp;
                }
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
                $hpp = null;        // barang ketikan bebas tidak punya harga modal
                $satuan = 'ecer';   // ketikan bebas tidak punya kemasan
                $isi = 1;
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

            if ($pr && $pr->stok !== null) {
                $butuh[$pr->id] = ($butuh[$pr->id] ?? 0) + $qty * $isi;
                $barang[$pr->id] = $pr;
            }

            $rincian[] = [
                'produk_id' => isset($it['produk_id']) && $it['produk_id'] !== null ? (int) $it['produk_id'] : null,
                'nama' => $nama, 'harga' => $harga, 'hpp' => $hpp, 'qty' => $qty,
                'satuan' => $satuan, 'isi' => $isi,
                'subtotal' => $subtotal, 'kategori' => $kategori,
            ];
        }

        /*
         * Stok diperiksa DI SERVER, bukan hanya di layar kasir.
         *
         * Layar memang sudah menahan lebih dulu — dan memang harus, supaya
         * kasir tahu sebelum pembeli menunggu. Tetapi layar bukan pagar:
         * halaman yang sudah lama terbuka memegang angka stok yang basi, dan
         * kiriman ke /api bisa disusun siapa pun yang memegang sesi kasir.
         * Tanpa pemeriksaan ini stok bisa menjadi MINUS tanpa ada satu pun
         * jejak yang menjelaskan sebabnya.
         *
         * Hanya berlaku untuk barang yang stoknya memang dilacak — kantin yang
         * tidak memakai stok sama sekali tidak tersentuh.
         */
        foreach ($butuh as $pid => $perlu) {
            $pr = $barang[$pid];
            if ($perlu > (int) $pr->stok) {
                throw new ApiError(409, "Stok \"{$pr->nama}\" tinggal " . (int) $pr->stok .
                    ", sedangkan yang diminta {$perlu}. Perbarui layar kasirnya, " .
                    'lalu ulangi transaksinya.');
            }
        }

        // Keterangan disusun di SERVER dari nama produk yang benar-benar terpakai,
        // jadi baris riwayat tidak bisa menyebut barang yang tidak dibeli.
        // Satuan grosir disebut di keterangan: "Teh ×2" dan "Teh ×2 dus" adalah
        // dua hal yang sangat berbeda nilainya, dan riwayat harus bisa dibaca
        // tanpa membuka rinciannya.
        $keterangan = mb_substr(implode(', ', array_map(
            fn ($r) => ($r['qty'] > 1 ? "{$r['nama']} ×{$r['qty']}" : $r['nama'])
                . ($r['satuan'] === 'grosir' ? ' (grosir)' : ''),
            $rincian,
        )), 0, 300);

        return compact('total', 'nonmakanan', 'rincian', 'keterangan');
    }
}
