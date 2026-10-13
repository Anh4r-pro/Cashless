<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\MartJual;
use App\Models\MartJualItem;
use App\Models\MartJualUbah;
use App\Models\MartKas;
use App\Models\MartPiutangBayar;
use App\Models\MartTahan;
use App\Models\MartTahanItem;
use App\Models\Produk;
use App\Models\Tenant;
use App\Services\Keranjang;
use App\Services\Pengaturan;
use App\Services\Rupiah;
use App\Services\Stok;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Membetulkan, membatalkan, dan menahan transaksi Mart.
 *
 * Tiga hal yang dijaga ketat di berkas ini:
 *
 *   1. ANGKA PENJUALAN TIDAK BOLEH BERGERAK SETELAH UANGNYA DIHITUNG. Begitu
 *      kas hari itu ditutup, transaksinya terkunci. Kalau tidak, selisih laci
 *      selalu bisa "dirapikan" dengan mengedit transaksi tadi pagi, dan tutup
 *      kas kehilangan seluruh gunanya.
 *   2. SETIAP PERUBAHAN BERJEJAK. Nilai lama tersimpan utuh beserta alasan,
 *      pelaku, dan waktunya. Kasir tetap merasakannya sebagai "edit biasa" —
 *      yang dijaga bukan kenyamanannya, melainkan kemampuan menjawab "kenapa
 *      angkanya lain sekarang?".
 *   3. STOK IKUT BERGESER. Membetulkan qty tanpa membetulkan stok hanya
 *      memindahkan kesalahan ke tempat yang lebih sulit ditemukan.
 *
 * Penjualan KARTU tidak bisa disentuh dari sini: uangnya sudah masuk kas
 * sekolah dan menyentuh neraca. Pembatalannya urusan admin, lewat jalur
 * tersendiri yang memang belum ada.
 */
class MartKoreksiController extends Controller
{
    private string $operator = 'Kasir Mart';

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

    /** Transaksi yang boleh disentuh: milik tenant ini, sah, dan kasnya belum ditutup. */
    private function jualBolehDiubah(Tenant $t, int $id): MartJual
    {
        $j = MartJual::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $j) {
            throw new ApiError(404, 'Transaksi tidak ditemukan');
        }
        if ($j->status !== 'sah') {
            throw new ApiError(409, 'Transaksi ini sudah dibatalkan');
        }
        // Piutang yang sudah dicicil tidak boleh diutak-atik: cicilannya sudah
        // masuk laci dan mungkin sudah ikut terhitung di tutup kas hari lain.
        if ($j->metode === 'hutang'
            && MartPiutangBayar::where('jual_id', $j->id)->exists()) {
            throw new ApiError(409, 'Piutang ini sudah pernah dicicil — ' .
                'uangnya sudah masuk dan tidak bisa ditarik kembali dari sini. ' .
                'Selesaikan sisanya, lalu betulkan lewat transaksi baru.');
        }
        $tgl = WaktuIso::tanggalLokal((string) $j->created_at);
        if (MartKas::where('tenant_id', $t->id)->where('tanggal', $tgl)->exists()) {
            throw new ApiError(409, "Kas tanggal {$tgl} sudah ditutup — transaksinya terkunci. " .
                'Uang yang sudah dihitung tidak boleh berubah angkanya di belakang.');
        }

        return $j;
    }

    private function alasanWajib(Request $r): string
    {
        $a = mb_substr(trim((string) $r->input('alasan', '')), 0, 200);
        if (mb_strlen($a) < 4) {
            throw new ApiError(400, 'Tulis dulu alasannya (minimal 4 huruf) — ' .
                'alasan inilah yang menjelaskan perubahan ini kelak');
        }

        return $a;
    }

    /** Potret transaksi + rinciannya, untuk disimpan sebagai pembanding. */
    private function potret(MartJual $j): array
    {
        return [
            'subtotal' => (int) $j->subtotal, 'diskon' => (int) $j->diskon,
            'total' => (int) $j->total, 'uang_diterima' => (int) $j->uang_diterima,
            'kembalian' => (int) $j->kembalian, 'n_item' => (int) $j->n_item,
            'status' => $j->status,
            'item' => MartJualItem::where('jual_id', $j->id)->get()
                ->map(fn ($i) => ['id' => $i->id, 'produk_id' => $i->produk_id, 'nama' => $i->nama,
                    'harga' => (int) $i->harga, 'qty' => (int) $i->qty, 'subtotal' => (int) $i->subtotal])
                ->all(),
        ];
    }

    // ------------------------------------------------------------- koreksi

    /**
     * Betulkan satu transaksi: jumlah, harga, diskon, uang diterima.
     *
     * Baris TIDAK bisa ditambah dari sini. Menambah barang berarti stok
     * tambahan yang keluar tanpa pernah dipindai, dan itu pintu yang terlalu
     * lebar untuk sebuah layar pembetulan. Barang yang terlewat dijual sebagai
     * transaksi baru — itu pula yang terjadi di dunia nyata.
     */
    public function ubah(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $j = $this->jualBolehDiubah($t, $id);
        $alasan = $this->alasanWajib($r);
        $sebelum = $this->potret($j);

        $kiriman = is_array($r->input('item')) ? $r->input('item') : [];
        if (! $kiriman) {
            throw new ApiError(400, 'Tidak ada rincian yang dikirim');
        }

        $lama = MartJualItem::where('jual_id', $j->id)->get()->keyBy('id');
        $maksHarga = (int) (Pengaturan::sekolah()['max_per_transaksi'] ?? 0);
        $baru = [];
        foreach ($kiriman as $b) {
            $it = $lama->get((int) ($b['id'] ?? 0));
            if (! $it) {
                continue;
            }
            $qty = max(0, min(Keranjang::MAKS_QTY, (int) floor((float) ($b['qty'] ?? $it->qty))));
            $harga = (int) floor((float) ($b['harga'] ?? $it->harga));
            if ($harga <= 0) {
                throw new ApiError(400, "Harga \"{$it->nama}\" tidak valid");
            }
            if ($maksHarga > 0 && $harga > $maksHarga) {
                throw new ApiError(400, "Harga \"{$it->nama}\" melebihi batas satu transaksi (" .
                    Rupiah::f($maksHarga) . ')');
            }
            $baru[$it->id] = ['qty' => $qty, 'harga' => $harga];
        }
        if (! $baru) {
            throw new ApiError(400, 'Tidak ada baris yang dikenali');
        }
        if (! array_filter($baru, fn ($x) => $x['qty'] > 0)) {
            throw new ApiError(400, 'Seluruh barang menjadi nol — pakai "Batalkan transaksi" ' .
                'supaya alasannya tercatat sebagai pembatalan, bukan sebagai koreksi');
        }

        $subtotal = 0;
        foreach ($lama as $it) {
            $n = $baru[$it->id] ?? ['qty' => (int) $it->qty, 'harga' => (int) $it->harga];
            $subtotal += $n['harga'] * $n['qty'];
        }
        $diskon = max(0, (int) floor((float) $r->input('diskon', $j->diskon)));
        if ($diskon > $subtotal) {
            throw new ApiError(400, 'Diskon tidak boleh melebihi total belanja');
        }
        $total = $subtotal - $diskon;

        if ($j->metode === 'tunai') {
            $uang = (int) floor((float) $r->input('uang_diterima', $j->uang_diterima));
            if ($uang < $total) {
                throw new ApiError(400, 'Uang diterima (' . Rupiah::f($uang) .
                    ') kurang dari total baru ' . Rupiah::f($total));
            }
            $kembalian = $uang - $total;
        } elseif ($j->metode === 'hutang') {
            $uang = 0;               // belum ada uang yang berpindah
            $kembalian = 0;
        } else {
            $uang = $total;          // transfer selalu pas
            $kembalian = 0;
        }

        $op = $this->operator;
        DB::transaction(function () use ($j, $lama, $baru, $subtotal, $diskon, $total,
            $uang, $kembalian, $alasan, $sebelum, $op, $t) {
            $ubahTeks = [];
            $nItem = 0;
            foreach ($lama as $it) {
                $n = $baru[$it->id] ?? ['qty' => (int) $it->qty, 'harga' => (int) $it->harga];
                $nItem += $n['qty'];
                $bedaQty = $n['qty'] - (int) $it->qty;
                $bedaHarga = $n['harga'] - (int) $it->harga;
                if ($bedaQty === 0 && $bedaHarga === 0) {
                    continue;
                }
                if ($bedaQty !== 0) {
                    $ubahTeks[] = "{$it->nama}: jumlah {$it->qty} → {$n['qty']}";
                    // Stok ikut bergeser. Qty NAIK berarti barang keluar lagi,
                    // jadi stoknya berkurang — tandanya kebalikan dari qty.
                    if ($it->produk_id) {
                        Stok::koreksi((int) $it->produk_id, -$bedaQty,
                            'koreksi transaksi ' . $j->id . ': ' . $alasan, $op);
                    }
                }
                if ($bedaHarga !== 0) {
                    $ubahTeks[] = "{$it->nama}: harga " . Rupiah::f($it->harga) .
                        ' → ' . Rupiah::f($n['harga']);
                }
                $it->update(['qty' => $n['qty'], 'harga' => $n['harga'],
                    'subtotal' => $n['harga'] * $n['qty']]);
            }
            if ((int) $j->diskon !== $diskon) {
                $ubahTeks[] = 'Diskon ' . Rupiah::f($j->diskon) . ' → ' . Rupiah::f($diskon);
            }
            if ((int) $j->uang_diterima !== $uang) {
                $ubahTeks[] = 'Uang diterima ' . Rupiah::f($j->uang_diterima) . ' → ' . Rupiah::f($uang);
            }
            if (! $ubahTeks) {
                throw new ApiError(400, 'Tidak ada yang berubah');
            }

            $totalLama = (int) $j->total;
            $j->update([
                'subtotal' => $subtotal, 'diskon' => $diskon, 'total' => $total,
                'uang_diterima' => $uang, 'kembalian' => $kembalian, 'n_item' => $nItem,
                'n_ubah' => (int) $j->n_ubah + 1,
                'diubah_oleh' => $op, 'diubah_pada' => WaktuIso::sekarang(),
            ]);

            MartJualUbah::create([
                'jual_id' => $j->id, 'tenant_id' => $t->id, 'jenis' => 'ubah',
                'alasan' => $alasan, 'total_lama' => $totalLama, 'total_baru' => $total,
                'ringkas' => mb_substr(implode(' · ', $ubahTeks), 0, 400),
                'sebelum' => json_encode($sebelum, JSON_UNESCAPED_UNICODE),
                'sesudah' => json_encode($this->potret($j->fresh()), JSON_UNESCAPED_UNICODE),
                'operator' => $op, 'created_at' => WaktuIso::sekarang(),
            ]);
        });

        return response()->json(['ok' => true, 'jual' => $j->fresh(),
            'item' => MartJualItem::where('jual_id', $j->id)->get()]);
    }

    /**
     * Batalkan transaksi. Barisnya TIDAK dihapus — hanya ditandai batal,
     * dikeluarkan dari semua penjumlahan, dan stoknya dikembalikan.
     */
    public function batal(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $j = $this->jualBolehDiubah($t, $id);
        $alasan = $this->alasanWajib($r);
        $sebelum = $this->potret($j);

        $op = $this->operator;
        DB::transaction(function () use ($j, $alasan, $sebelum, $op, $t) {
            foreach (MartJualItem::where('jual_id', $j->id)->get() as $it) {
                if ($it->produk_id) {
                    Stok::batal((int) $it->produk_id, (int) $it->qty,
                        'batal transaksi ' . $j->id, $op);
                }
            }
            $j->update([
                'status' => 'batal', 'n_ubah' => (int) $j->n_ubah + 1,
                'diubah_oleh' => $op, 'diubah_pada' => WaktuIso::sekarang(),
            ]);
            MartJualUbah::create([
                'jual_id' => $j->id, 'tenant_id' => $t->id, 'jenis' => 'batal',
                'alasan' => $alasan, 'total_lama' => $sebelum['total'], 'total_baru' => 0,
                'ringkas' => 'Transaksi dibatalkan, stok dikembalikan',
                'sebelum' => json_encode($sebelum, JSON_UNESCAPED_UNICODE),
                'sesudah' => json_encode($this->potret($j->fresh()), JSON_UNESCAPED_UNICODE),
                'operator' => $op, 'created_at' => WaktuIso::sekarang(),
            ]);
        });

        return response()->json(['ok' => true, 'jual' => $j->fresh()]);
    }

    // ------------------------------------------------- transaksi tertahan

    public function tahanDaftar(Request $r)
    {
        $t = $this->martKu($r);

        return response()->json(['tahan' => MartTahan::where('tenant_id', $t->id)
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn ($x) => array_merge($x->toArray(), [
                'ringkas' => MartTahanItem::where('tahan_id', $x->id)->orderBy('id')
                    ->limit(4)->pluck('nama')->implode(', '),
            ]))]);
    }

    /**
     * Tahan keranjang yang sedang berjalan.
     *
     * Harga dibaca ULANG dari basis data, sama seperti saat menjual — layar
     * kasir tidak pernah jadi sumber harga, bahkan untuk keranjang yang cuma
     * diparkir. Stok TIDAK dipotong: barangnya belum terjual.
     */
    public function tahanSimpan(Request $r)
    {
        $t = $this->martKu($r);
        $items = $r->input('items');
        if (! is_array($items) || ! $items) {
            throw new ApiError(400, 'Keranjang kosong — tidak ada yang bisa ditahan');
        }
        if (MartTahan::where('tenant_id', $t->id)->count() >= 30) {
            throw new ApiError(409, 'Sudah ada 30 transaksi tertahan — ' .
                'selesaikan atau buang sebagian dulu supaya daftarnya tetap terbaca');
        }

        $x = Keranjang::ringkas($items, $t, Pengaturan::sekolah());
        $diskon = max(0, min((int) floor((float) $r->input('diskon', 0)), $x['total']));
        $catatan = mb_substr(trim((string) $r->input('catatan', '')), 0, 80);

        $op = $this->operator;
        $tahan = DB::transaction(function () use ($t, $x, $diskon, $catatan, $op) {
            $h = MartTahan::create([
                'tenant_id' => $t->id, 'catatan' => $catatan,
                'n_item' => array_sum(array_map(fn ($b) => (int) ($b['qty'] ?? 1), $x['rincian'])),
                'subtotal' => $x['total'], 'diskon' => $diskon, 'total' => $x['total'] - $diskon,
                'operator' => $op, 'created_at' => WaktuIso::sekarang(),
            ]);
            foreach ($x['rincian'] as $b) {
                MartTahanItem::create([
                    'tahan_id' => $h->id, 'produk_id' => $b['produk_id'] ?? null,
                    'nama' => $b['nama'], 'kategori' => $b['kategori'] ?? 'makanan',
                    'harga' => (int) $b['harga'], 'qty' => (int) ($b['qty'] ?? 1),
                    'subtotal' => (int) $b['subtotal'],
                ]);
            }

            return $h;
        });

        return response()->json(['ok' => true, 'tahan' => $tahan->fresh()]);
    }

    /**
     * Ambil kembali keranjang yang tertahan — dan HAPUS catatannya.
     *
     * Dihapus saat diambil, bukan saat dibayar: kalau ia tetap tinggal, dua
     * meja kasir bisa mengambil keranjang yang sama dan menjual barangnya dua
     * kali. Kasir yang batal melanjutkan tinggal menahannya lagi.
     */
    public function tahanAmbil(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $h = MartTahan::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $h) {
            throw new ApiError(404, 'Transaksi tertahan tidak ditemukan — ' .
                'mungkin baru saja dilanjutkan di meja kasir lain');
        }
        $item = MartTahanItem::where('tahan_id', $h->id)->orderBy('id')->get();

        // Barang yang keburu dihapus atau dinonaktifkan sejak ditahan disebut
        // apa adanya, bukan dibuang diam-diam dari keranjang.
        $hilang = [];
        $siap = [];
        foreach ($item as $i) {
            if ($i->produk_id === null) {
                // Barang input manual: tidak ada katalog untuk dicek ulang —
                // ia bukan "hilang", kembalikan apa adanya seperti saat ditahan.
                $siap[] = ['manual' => true, 'nama' => $i->nama, 'harga' => (int) $i->harga,
                    'kategori' => $i->kategori, 'qty' => (int) $i->qty];

                continue;
            }
            $p = Produk::where('id', $i->produk_id)
                ->where('tenant_id', $t->id)->where('aktif', 1)->first();
            if (! $p) {
                $hilang[] = $i->nama;

                continue;
            }
            $siap[] = ['produk_id' => $p->id, 'nama' => $p->nama, 'harga' => (int) $p->harga,
                'kategori' => $p->kategori, 'qty' => (int) $i->qty];
        }

        $diskon = (int) $h->diskon;
        $catatan = (string) $h->catatan;
        DB::transaction(function () use ($h) {
            MartTahanItem::where('tahan_id', $h->id)->delete();
            $h->delete();
        });

        return response()->json(['ok' => true, 'items' => $siap, 'diskon' => $diskon,
            'catatan' => $catatan, 'hilang' => $hilang]);
    }

    public function tahanBuang(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $h = MartTahan::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $h) {
            throw new ApiError(404, 'Transaksi tertahan tidak ditemukan');
        }
        MartTahanItem::where('tahan_id', $h->id)->delete();
        $h->delete();

        return response()->json(['ok' => true]);
    }
}
