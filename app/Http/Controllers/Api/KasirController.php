<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\Siswa;
use App\Models\Tenant;
use App\Models\Transaksi;
use App\Models\TransaksiItem;
use App\Services\Kartu;
use App\Services\Keranjang;
use App\Services\Pengaturan;
use App\Services\Stok;
use App\Services\Rekap;
use App\Services\Rupiah;
use App\Services\SmartTopup;
use App\Services\TapToken;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kasir: satu tempelan kartu = satu transaksi.
 */
class KasirController extends Controller
{
    /**
     * Kolom pemegang kartu yang boleh dilihat KASIR.
     *
     * NISN, tanggal lahir, dan nomor HP wali sengaja TIDAK ada di sini: ketiganya
     * adalah kunci masuk portal wali. Layar kasir dipakai penjaga kantin yang
     * bukan pegawai sekolah, dan mengembalikan baris santri apa adanya berarti
     * membagikan kredensial portal setiap anak yang menempelkan kartunya.
     */
    private const KOLOM_KARTU = [
        'id', 'nis', 'nama', 'kelas', 'card_uid', 'saldo', 'saldo_titipan', 'status',
        'foto', 'golongan', 'jabatan', 'berlaku_sampai',
    ];

    public function cardLookup(Request $r)
    {
        $this->wajibPeran($r, 'tenant', 'superadmin');
        $uid = preg_replace('/[^0-9a-zA-Z]/', '', (string) $r->input('uid'));
        $uid = strtoupper((string) $uid);
        if ($uid === '') {
            throw new ApiError(400, 'UID kosong');
        }

        $s = Siswa::where('card_uid', $uid)->first();
        if (! $s) {
            return response()->json(['uid' => $uid, 'siswa' => null]);
        }

        // Isi ulang dikerjakan SEBELUM kasir melihat saldo: kalau tidak, santri
        // yang titipannya masih banyak tetap tertolak karena angka di layar
        // sudah tipis.
        $smart = SmartTopup::diam($s->id, 'tap kartu');
        if ($smart) {
            $s = Siswa::find($s->id);
        }

        $g = Pengaturan::golongan($s->golongan);

        return response()->json([
            'uid' => $uid,
            'siswa' => $s->only(self::KOLOM_KARTU),
            'tap_token' => TapToken::terbitkan($s->id),
            // Kasir perlu melihat SEKARANG bahwa yang di depannya tamu atau
            // guru, bukan santri — batas belanjanya berbeda, dan foto saja tidak
            // menjelaskan itu.
            'golongan' => $g?->only(['kode', 'nama', 'jenis', 'label_unit']),
            'kedaluwarsa' => Kartu::kedaluwarsa($s),
            'smart' => $smart ? ['isi' => $smart['isi'], 'sisa_titipan' => $smart['sisa_titipan']] : null,
            // Supaya kasir tahu LEBIH DULU kenapa transaksi bisa ditolak,
            // bukan baru tahu setelah mengetik nominal (lihat periksaBatasHarian).
            'sisa_harian' => Rekap::sisaHarian($s),
        ]);
    }

    public function beli(Request $r)
    {
        $user = $this->wajibPeran($r, 'tenant');
        $siswaId = (int) $r->input('siswa_id');

        $tenant = Tenant::find($user->tenant_id);
        if (! $tenant) {
            throw new ApiError(404, 'Tenant tidak ditemukan');
        }

        // Pemegang kartu dibaca lebih dulu karena BATASNYA ikut golongannya, dan
        // masa berlaku kartu diperiksa sebelum keranjang dihitung supaya kasir
        // mendapat alasan yang jelas alih-alih "saldo tidak cukup".
        $pemegang = Siswa::find($siswaId);
        if (! $pemegang) {
            throw new ApiError(404, 'Pemegang kartu tidak ditemukan');
        }
        Kartu::pastikanBerlaku($pemegang);
        $cfg = Pengaturan::untuk($pemegang);

        [$jumlah, $nonmakanan, $kecuali, $keterangan, $rincian] = $this->hitungBelanja($r, $tenant, $cfg);

        // Langit-langit mutlak satu transaksi, diturunkan dari batas golongan —
        // bukan angka tetap, supaya kartu VIP yang memang diizinkan berbelanja
        // besar tidak tertolak di sini sebelum pemeriksaan yang sebenarnya.
        // Barang yang dikecualikan (lihat JatahKecuali) TIDAK dihitung ke
        // langit-langit ini — itu justru gunanya, seragam Rp150.000 tidak
        // boleh tertahan oleh atap yang dirancang untuk jajan harian.
        $atapTx = $cfg['max_per_transaksi'] + $cfg['max_nonmakanan_transaksi'];
        if (($jumlah - $kecuali) > $atapTx) {
            throw new ApiError(400, 'Maksimal transaksi ' . Rupiah::f($atapTx) .
                ' (di luar barang yang dikecualikan dari limit)');
        }

        $makanan = $jumlah - $nonmakanan - $kecuali;
        if ($makanan > $cfg['max_per_transaksi']) {
            throw new ApiError(400, 'Melebihi batas per transaksi (maks ' . Rupiah::f($cfg['max_per_transaksi']) . ')');
        }
        if ($nonmakanan > $cfg['max_nonmakanan_transaksi']) {
            throw new ApiError(400,
                'Barang non-makanan dalam satu transaksi maksimal ' . Rupiah::f($cfg['max_nonmakanan_transaksi']) .
                ' (keranjang ini ' . Rupiah::f($nonmakanan) . ')');
        }

        $txId = $r->input('tx_id') ? (string) $r->input('tx_id') : null;
        if ($txId && $dupe = Transaksi::where('tx_id', $txId)->first()) {
            $sDupe = Siswa::find($siswaId);
            return response()->json([
                'ok' => true,
                'siswa' => $sDupe->only(self::KOLOM_KARTU),
                'transaksi' => $dupe,
                'duplikat' => true,
                'sisa_harian' => Rekap::sisaHarian($sDupe),
            ]);
        }

        $this->periksaBatasHarian($siswaId, $makanan, $nonmakanan, $cfg);

        $kunci = TapToken::pakai($r->input('tap_token'), $siswaId);   // kunci token dulu
        try {
            $hasil = DB::transaction(function () use ($siswaId, $jumlah, $txId, $user, $keterangan, $nonmakanan, $kecuali, $rincian, $tenant) {
                // Syarat saldo ditaruh di WHERE, bukan diperiksa lebih dulu di PHP:
                // dua tap yang tiba bersamaan tidak boleh sama-sama lolos.
                $ubah = Siswa::where('id', $siswaId)
                    ->where('saldo', '>=', $jumlah)
                    ->where('status', 'aktif')
                    ->update(['saldo' => DB::raw("saldo - {$jumlah}")]);

                if ($ubah === 0) {
                    $s = Siswa::find($siswaId);
                    if (! $s) {
                        throw new ApiError(404, 'Santri tidak ditemukan');
                    }
                    if ($s->status !== 'aktif') {
                        throw new ApiError(403, 'Kartu diblokir');
                    }
                    // Titipan tidak bisa dibelanjakan langsung, tapi kasir perlu
                    // tahu uangnya ada — supaya bisa minta admin mencairkannya,
                    // bukan menyuruh santri pulang.
                    throw new ApiError(402, 'Saldo tidak cukup (saldo ' . Rupiah::f($s->saldo) .
                        ($s->saldo_titipan > 0 ? ', titipan ' . Rupiah::f($s->saldo_titipan) . ' belum dicairkan' : '') . ')');
                }

                $s = Siswa::find($siswaId);
                $trx = Transaksi::create([
                    'tx_id' => $txId, 'tipe' => 'beli', 'siswa_id' => $s->id,
                    'siswa_nama' => $s->nama, 'kelas' => $s->kelas,
                    'tenant_id' => $tenant->id, 'tenant_nama' => $tenant->nama,
                    'jumlah' => -$jumlah, 'saldo_sesudah' => $s->saldo,
                    'operator' => $user->nama, 'keterangan' => $keterangan,
                    'nonmakanan' => $nonmakanan, 'kecuali' => $kecuali, 'created_at' => WaktuIso::sekarang(),
                ]);

                // Rincian disimpan DI DALAM transaksi yang sama dengan pemotongan
                // saldo: kalau belanjanya batal, rinciannya ikut batal — laporan
                // per menu tidak boleh memuat barang yang uangnya tidak berpindah.
                $this->simpanRincian($trx, $rincian, $s, $tenant->nama);

                return ['ok' => true, 'siswa' => $s->only(self::KOLOM_KARTU),
                    'transaksi' => $trx, 'rincian' => $rincian];
            });
        } catch (\Throwable $e) {
            TapToken::lepas($kunci);   // gagal → tap masih boleh dipakai
            throw $e;
        }

        // Belanja sudah tersimpan. Kalau saldonya kini menyentuh ambang, titipan
        // diteteskan SEKARANG — di luar transaksi di atas, supaya urusan isi
        // ulang tidak pernah bisa membatalkan belanja yang sudah sah.
        if ($smart = SmartTopup::diam($siswaId, 'setelah belanja')) {
            $hasil['smart'] = ['isi' => $smart['isi'], 'sisa_titipan' => $smart['sisa_titipan']];
            $hasil['siswa'] = $smart['siswa']->only(self::KOLOM_KARTU);
        }

        // Supaya kasir (dan santri) langsung tahu sisa jatah hari ini SETELAH
        // belanja barusan ikut terhitung — sebelum ini cuma tampil saat tap,
        // sebelum transaksi, jadi sisanya sudah basi begitu belanja selesai.
        $hasil['sisa_harian'] = Rekap::sisaHarian(Siswa::find($siswaId));

        return response()->json($hasil);
    }

    /**
     * Dua bentuk kiriman: dengan rincian item (halaman kasir sekarang) atau
     * hanya nominal total (klien lama). Tenant koperasi WAJIB mengirim rincian,
     * karena tanpa itu server tidak tahu bagian mana yang non-makanan — dan
     * halaman kasir yang belum diperbarui akan lolos dari batas khusus.
     */
    private function hitungBelanja(Request $r, Tenant $tenant, array $cfg): array
    {
        $items = $r->input('items');
        if (is_array($items) && $items) {
            $x = Keranjang::ringkas($items, $tenant, $cfg);
            // Total dari klien ikut diperiksa: kalau berbeda, harga di layar
            // kasir sudah tidak sama dengan harga di basis data — lebih baik
            // gagal daripada menagih santri dengan angka yang tidak dilihat siapa pun.
            if ($r->input('jumlah') !== null && (int) floor((float) $r->input('jumlah')) !== $x['total']) {
                throw new ApiError(409,
                    'Total di layar (' . Rupiah::f((int) floor((float) $r->input('jumlah'))) . ') tidak sama dengan ' .
                    'harga menu saat ini (' . Rupiah::f($x['total']) . ') — muat ulang halaman kasir');
            }

            return [$x['total'], $x['nonmakanan'], $x['kecuali'], $x['keterangan'], $x['rincian']];
        }

        if ($tenant->jenis === 'koperasi' && ($cfg['nonmakanan_aktif'] ?? 0) === 1) {
            throw new ApiError(400, 'Muat ulang halaman kasir: koperasi harus mengirim rincian barang');
        }
        // Mode bebas mewajibkan deskripsi per barang; jalur lama yang hanya
        // membawa nominal total tidak punya tempat untuk itu.
        if (($tenant->mode_kasir ?? 'menu') === 'bebas') {
            throw new ApiError(400, 'Muat ulang halaman kasir: mode tanpa menu harus mengirim rincian barang');
        }
        $jumlah = (int) floor((float) $r->input('jumlah'));
        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal tidak valid');
        }

        return [$jumlah, 0, 0, mb_substr((string) $r->input('keterangan', ''), 0, 300), null];
    }

    private function periksaBatasHarian(int $siswaId, int $makanan, int $nonmakanan, array $cfg): void
    {
        $h = Rekap::belanjaHariIni($siswaId);

        if ($makanan > 0) {
            if ($h['n_mk'] >= $cfg['max_beli_harian']) {
                throw new ApiError(429, "Santri sudah {$h['n_mk']}x belanja hari ini (batas {$cfg['max_beli_harian']}x/hari)");
            }
            // Porsi yang dikecualikan ikut disingkirkan dari akumulasi makanan —
            // kalau tidak, barang yang dikecualikan diam-diam ikut memakan
            // jatah makanan, padahal sama sekali bukan jajan.
            $makananHariIni = $h['total'] - $h['nm'] - $h['kecuali'];
            if ($makananHariIni + $makanan > $cfg['max_belanja_harian']) {
                throw new ApiError(429,
                    'Melebihi akumulasi belanja harian (sudah ' . Rupiah::f($makananHariIni) .
                    ' hari ini, batas ' . Rupiah::f($cfg['max_belanja_harian']) . ')');
            }
        }
        if ($nonmakanan > 0) {
            if ($h['n_nm'] >= $cfg['max_beli_nonmakanan_harian']) {
                throw new ApiError(429,
                    "Santri sudah {$h['n_nm']}x belanja non-makanan hari ini (batas {$cfg['max_beli_nonmakanan_harian']}x/hari)");
            }
            if ($h['nm'] + $nonmakanan > $cfg['max_nonmakanan_harian']) {
                throw new ApiError(429,
                    'Melebihi batas belanja non-makanan harian (sudah ' . Rupiah::f($h['nm']) .
                    ' hari ini, batas ' . Rupiah::f($cfg['max_nonmakanan_harian']) . ')');
            }
        }
    }

    /**
     * Nama, harga, kelas, dan golongan sengaja DISALIN, bukan di-JOIN saat
     * membaca laporan: menu berganti nama, harganya naik, dan siswa naik kelas —
     * sementara rekap penjualan Juli harus tetap menyebut apa yang benar-benar
     * terjual pada Juli, dengan harga yang benar-benar dibayar.
     */
    private function simpanRincian(Transaksi $trx, ?array $rincian, Siswa $s, string $tenantNama): void
    {
        foreach ($rincian ?? [] as $b) {
            TransaksiItem::create([
                'transaksi_id' => $trx->id, 'produk_id' => $b['produk_id'],
                'tenant_id' => $trx->tenant_id, 'tenant_nama' => $tenantNama,
                'nama' => $b['nama'], 'kategori' => $b['kategori'],
                'jatah_kecuali_id' => $b['jatah_kecuali_id'] ?? null,
                'jatah_kecuali_keterangan' => $b['jatah_kecuali_keterangan'] ?? '',
                'harga' => $b['harga'], 'hpp' => $b['hpp'] ?? null,
                'qty' => $b['qty'], 'satuan' => $b['satuan'] ?? 'ecer',
                'isi' => (int) ($b['isi'] ?? 1), 'subtotal' => $b['subtotal'],
                'siswa_id' => $trx->siswa_id, 'kelas' => $s->kelas,
                'golongan' => $s->golongan, 'created_at' => $trx->created_at,
            ]);
            // Stok ikut berkurang untuk barang yang MEMANG dilacak stoknya
            // (lihat Services\Stok). Kantin yang tidak memakai stok sama
            // sekali tidak tersentuh — barangnya berstok NULL.
            if ($b['produk_id']) {
                // Dikali isi kemasan: stok disimpan dalam satuan eceran, jadi
                // satu dus berisi 12 memotong 12 pcs (lihat Services\Keranjang).
                Stok::jual((int) $b['produk_id'], (int) $b['qty'] * max(1, (int) ($b['isi'] ?? 1)),
                    (string) ($trx->tx_id ?? ''), 'Kasir ' . $tenantNama);
            }
        }
    }
}
