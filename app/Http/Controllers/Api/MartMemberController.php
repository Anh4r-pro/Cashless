<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\MartJual;
use App\Models\MartMember;
use App\Models\MartMemberKategori;
use App\Models\MartMemberKet;
use App\Models\MartPiutangBayar;
use App\Models\Tenant;
use App\Services\Rupiah;
use App\Support\WaktuIso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Member IMBOS Mart dan piutangnya.
 *
 * Member ada di sini bukan untuk gagah-gagahan: ia SYARAT agar hutang boleh
 * terjadi. Piutang tanpa identitas yang pasti adalah piutang yang tidak bisa
 * ditagih, dan nama yang diketik bebas membuat daftar piutang tidak bisa
 * dijumlahkan per orang.
 *
 * Dua waktu yang sengaja dipisahkan di seluruh berkas ini:
 *
 *   KAPAN BARANG KELUAR → omzet, laba, stok. Terjadi saat penjualan hutang.
 *   KAPAN UANG DATANG   → isi laci, tutup kas. Terjadi saat cicilan dibayar.
 *
 * Menyatukan keduanya adalah kesalahan yang paling mahal di sini: kalau
 * penjualan hutang ikut terhitung sebagai uang masuk, tutup kas akan selalu
 * terlihat kurang sebesar hutang hari itu, dan kasir akan belajar mengabaikan
 * selisih laci.
 */
class MartMemberController extends Controller
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

    // -------------------------------------------------------------- member

    /**
     * Kategori member & pilihan keterangan — dua daftar kecil yang dikelola
     * admin toko sendiri.
     *
     * Digabung dalam satu endpoint karena keduanya dipakai oleh layar yang
     * sama (formulir member), dan memisahkannya hanya menambah satu perjalanan
     * jaringan tanpa menambah kejelasan apa pun.
     */
    public function daftarKategori(Request $r)
    {
        $t = $this->martKu($r);
        $this->wajibAdminToko($r, 'tenant');

        return response()->json([
            'kategori' => MartMemberKategori::where('tenant_id', $t->id)
                ->orderBy('urutan')->orderBy('nama')->get(),
            'keterangan' => MartMemberKet::where('tenant_id', $t->id)
                ->orderBy('urutan')->orderBy('teks')->get(),
        ]);
    }

    public function kategoriSimpan(Request $r, ?int $id = null)
    {
        $t = $this->martKu($r);
        $this->wajibAdminToko($r, 'tenant');
        $nama = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $r->input('nama', ''))), 0, 40);
        if (mb_strlen($nama) < 2) {
            throw new ApiError(400, 'Nama kategori minimal 2 huruf');
        }
        $kembar = MartMemberKategori::where('tenant_id', $t->id)
            ->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])
            ->when($id, fn ($q) => $q->where('id', '!=', $id))->first();
        if ($kembar) {
            throw new ApiError(409, "Kategori \"{$nama}\" sudah ada");
        }

        $isi = [
            'nama' => $nama,
            // Bawaannya BOLEH berhutang: kategori dibuat untuk mengelompokkan,
            // dan diam-diam melarang orang berhutang karena lupa mencentang
            // adalah kejutan yang muncul di depan pembeli.
            'boleh_hutang' => $r->input('boleh_hutang') === null ? true : (bool) $r->input('boleh_hutang'),
            'urutan' => (int) $r->input('urutan', 0),
            'aktif' => $r->input('aktif') === null ? true : (bool) $r->input('aktif'),
        ];

        if ($id) {
            $k = MartMemberKategori::where('id', $id)->where('tenant_id', $t->id)->first();
            if (! $k) {
                throw new ApiError(404, 'Kategori tidak ditemukan');
            }
            $k->update($isi);
        } else {
            $k = MartMemberKategori::create(array_merge($isi, [
                'tenant_id' => $t->id, 'created_at' => WaktuIso::sekarang(),
            ]));
        }

        return response()->json(['ok' => true, 'kategori' => $k->fresh()]);
    }

    public function kategoriHapus(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $this->wajibAdminToko($r, 'tenant');
        $k = MartMemberKategori::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $k) {
            throw new ApiError(404, 'Kategori tidak ditemukan');
        }
        // Membernya TIDAK ikut terhapus — kunci asingnya nullOnDelete, jadi
        // mereka kembali ke keadaan "belum dikategorikan", bukan hilang.
        $n = MartMember::where('tenant_id', $t->id)->where('kategori_id', $k->id)->count();
        $k->delete();

        return response()->json(['ok' => true, 'n_member_terlepas' => $n]);
    }

    public function ketSimpan(Request $r, ?int $id = null)
    {
        $t = $this->martKu($r);
        $this->wajibAdminToko($r, 'tenant');
        $teks = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $r->input('teks', ''))), 0, 160);
        if (mb_strlen($teks) < 2) {
            throw new ApiError(400, 'Keterangan minimal 2 huruf');
        }
        $kembar = MartMemberKet::where('tenant_id', $t->id)
            ->whereRaw('LOWER(teks) = ?', [mb_strtolower($teks)])
            ->when($id, fn ($q) => $q->where('id', '!=', $id))->first();
        if ($kembar) {
            throw new ApiError(409, "Pilihan keterangan \"{$teks}\" sudah ada");
        }

        $isi = ['teks' => $teks, 'urutan' => (int) $r->input('urutan', 0),
            'aktif' => $r->input('aktif') === null ? true : (bool) $r->input('aktif')];

        if ($id) {
            $k = MartMemberKet::where('id', $id)->where('tenant_id', $t->id)->first();
            if (! $k) {
                throw new ApiError(404, 'Pilihan keterangan tidak ditemukan');
            }
            $k->update($isi);
        } else {
            $k = MartMemberKet::create(array_merge($isi, [
                'tenant_id' => $t->id, 'created_at' => WaktuIso::sekarang(),
            ]));
        }

        return response()->json(['ok' => true, 'keterangan' => $k->fresh()]);
    }

    public function ketHapus(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $this->wajibAdminToko($r, 'tenant');
        $k = MartMemberKet::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $k) {
            throw new ApiError(404, 'Pilihan keterangan tidak ditemukan');
        }
        // Keterangan member disimpan sebagai TEKS, jadi menghapus pilihannya
        // tidak mengosongkan keterangan siapa pun — ia hanya berhenti
        // ditawarkan pada formulir berikutnya.
        $k->delete();

        return response()->json(['ok' => true]);
    }

    public function index(Request $r)
    {
        $t = $this->martKu($r);
        $cari = trim((string) $r->query('cari', ''));

        $q = MartMember::where('tenant_id', $t->id)
            ->when($cari !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('nama', 'like', '%' . $cari . '%')
                ->orWhere('hp', 'like', '%' . $cari . '%')
                ->orWhere('kode', 'like', '%' . $cari . '%')))
            ->orderBy('nama')->get();

        $sisa = $this->sisaPerMember($t);
        $kat = MartMemberKategori::where('tenant_id', $t->id)->get()->keyBy('id');

        return response()->json([
            'member' => $q->map(fn ($m) => array_merge($m->toArray(), [
                'sisa_piutang' => (int) ($sisa[$m->id] ?? 0),
                'sisa_plafon' => max(0, (int) $m->batas_hutang - (int) ($sisa[$m->id] ?? 0)),
                'kategori_nama' => $m->kategori_id ? ($kat[$m->kategori_id]->nama ?? null) : null,
                // Dipakai layar kasir untuk mematikan tombol Hutang sejak awal,
                // bukan menunggu penolakan muncul saat tombolnya ditekan.
                'boleh_hutang' => $m->kategori_id
                    ? (bool) ($kat[$m->kategori_id]->boleh_hutang ?? true) : true,
            ])),
            'ringkas' => [
                'n_member' => MartMember::where('tenant_id', $t->id)->count(),
                'n_berhutang' => count(array_filter($sisa, fn ($v) => $v > 0)),
                'piutang_total' => array_sum($sisa),
            ],
        ]);
    }

    /** Sisa piutang seluruh member sekaligus — satu kueri, bukan satu per member. */
    private function sisaPerMember(Tenant $t): array
    {
        $hutang = MartJual::sah()->where('tenant_id', $t->id)->where('metode', 'hutang')
            ->whereNotNull('member_id')
            ->selectRaw('member_id, SUM(total) n')->groupBy('member_id')->pluck('n', 'member_id');
        $bayar = MartPiutangBayar::where('tenant_id', $t->id)
            ->selectRaw('member_id, SUM(jumlah) n')->groupBy('member_id')->pluck('n', 'member_id');

        $hasil = [];
        foreach ($hutang as $id => $n) {
            $hasil[(int) $id] = max(0, (int) $n - (int) ($bayar[$id] ?? 0));
        }

        return $hasil;
    }

    public function simpan(Request $r, ?int $id = null)
    {
        $t = $this->martKu($r);
        $this->wajibAdminToko($r, 'tenant');
        $nama = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $r->input('nama', ''))), 0, 120);
        if (mb_strlen($nama) < 3) {
            throw new ApiError(400, 'Nama member minimal 3 huruf');
        }
        $hp = preg_replace('/[^0-9+]/', '', (string) $r->input('hp', ''));
        $batas = max(0, (int) floor((float) $r->input('batas_hutang', 0)));

        $kembar = MartMember::where('tenant_id', $t->id)
            ->whereRaw('LOWER(nama) = ?', [mb_strtolower($nama)])
            ->when($id, fn ($q) => $q->where('id', '!=', $id))->first();
        if ($kembar) {
            throw new ApiError(409, "Member bernama \"{$nama}\" sudah ada — " .
                'bedakan namanya supaya piutangnya tidak tertukar');
        }

        // Kategori boleh kosong — itu keadaan seluruh member yang sudah ada,
        // dan mereka tetap berperilaku seperti sebelum kategori ini ada.
        $katId = $r->input('kategori_id') ?: null;
        if ($katId && ! MartMemberKategori::where('id', $katId)->where('tenant_id', $t->id)->exists()) {
            throw new ApiError(400, 'Kategori member tidak dikenal');
        }

        $isi = [
            'nama' => $nama, 'kategori_id' => $katId, 'hp' => mb_substr($hp, 0, 25),
            'keterangan' => mb_substr(trim((string) $r->input('keterangan', '')), 0, 160),
            'batas_hutang' => $batas,
            'aktif' => $r->input('aktif') === null ? true : (bool) $r->input('aktif'),
        ];

        if ($id) {
            $m = MartMember::where('id', $id)->where('tenant_id', $t->id)->first();
            if (! $m) {
                throw new ApiError(404, 'Member tidak ditemukan');
            }
            // Plafon tidak boleh diturunkan di bawah piutang yang sudah berjalan:
            // angkanya akan langsung berbohong, dan tidak ada yang bisa dilakukan
            // kasir untuk membetulkannya selain menagih.
            $sisa = $this->sisaPerMember($t)[$m->id] ?? 0;
            if ($batas > 0 && $batas < $sisa) {
                throw new ApiError(409, 'Plafon baru (' . Rupiah::f($batas) .
                    ') di bawah piutang yang masih berjalan (' . Rupiah::f($sisa) .
                    ') — tagih dulu, atau pakai angka yang lebih besar');
            }
            $m->update($isi);
        } else {
            $m = MartMember::create(array_merge($isi, [
                'tenant_id' => $t->id,
                'kode' => 'M-' . str_pad((string) (MartMember::where('tenant_id', $t->id)
                    ->count() + 1), 4, '0', STR_PAD_LEFT),
                'created_at' => WaktuIso::sekarang(),
            ]));
        }

        return response()->json(['ok' => true, 'member' => $m->fresh()]);
    }

    /**
     * Hapus member. Yang pernah bertransaksi hanya dinonaktifkan — menghapusnya
     * akan membuat riwayat penjualan menyebut hutang atas nama yang tidak ada.
     */
    public function hapus(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $this->wajibAdminToko($r, 'tenant');
        $m = MartMember::where('id', $id)->where('tenant_id', $t->id)->first();
        if (! $m) {
            throw new ApiError(404, 'Member tidak ditemukan');
        }
        $sisa = $this->sisaPerMember($t)[$m->id] ?? 0;
        if ($sisa > 0) {
            throw new ApiError(409, 'Masih ada piutang ' . Rupiah::f($sisa) .
                ' atas nama ini — lunasi atau putihkan dulu');
        }
        if (MartJual::where('member_id', $m->id)->exists()) {
            $m->update(['aktif' => false]);

            return response()->json(['ok' => true, 'dinonaktifkan' => true,
                'pesan' => 'Member pernah bertransaksi, jadi dinonaktifkan — riwayatnya tetap utuh.']);
        }
        $m->delete();

        return response()->json(['ok' => true, 'dinonaktifkan' => false]);
    }

    // ------------------------------------------------------------- piutang

    /** Daftar penjualan hutang yang belum lunas, terlama lebih dulu. */
    public function piutang(Request $r)
    {
        $t = $this->martKu($r);
        $memberId = (int) $r->query('member_id');

        $bayar = MartPiutangBayar::where('tenant_id', $t->id)
            ->selectRaw('jual_id, SUM(jumlah) n')->groupBy('jual_id')->pluck('n', 'jual_id');

        $baris = MartJual::sah()->where('tenant_id', $t->id)->where('metode', 'hutang')
            ->when($memberId, fn ($q) => $q->where('member_id', $memberId))
            ->orderBy('id')->get()
            ->map(function ($j) use ($bayar) {
                $sudah = (int) ($bayar[$j->id] ?? 0);

                return [
                    'id' => (int) $j->id, 'no' => 'MRT-' . str_pad((string) $j->id, 6, '0', STR_PAD_LEFT),
                    'waktu' => $j->created_at, 'member_id' => (int) $j->member_id,
                    'total' => (int) $j->total, 'dibayar' => $sudah,
                    'sisa' => max(0, (int) $j->total - $sudah),
                ];
            })
            ->filter(fn ($b) => $b['sisa'] > 0)->values();

        $nama = MartMember::whereIn('id', $baris->pluck('member_id')->all())->pluck('nama', 'id');

        return response()->json([
            'piutang' => $baris->map(fn ($b) => array_merge($b, [
                'member' => $nama[$b['member_id']] ?? '(member terhapus)',
                // Umur piutang dalam hari — angka yang dicari saat menagih.
                'umur_hari' => (int) floor((time() - strtotime($b['waktu'])) / 86400),
            ])),
            'total_sisa' => (int) $baris->sum('sisa'),
        ]);
    }

    /**
     * Terima cicilan.
     *
     * Uangnya diakui pada HARI INI, bukan pada hari penjualannya — itulah
     * sebabnya ia dicatat sebagai baris tersendiri dan bukan sebagai perubahan
     * pada penjualan lamanya. Tutup kas hari ini yang akan mencarinya di laci.
     */
    public function bayar(Request $r, int $id)
    {
        $t = $this->martKu($r);
        $j = MartJual::where('id', $id)->where('tenant_id', $t->id)
            ->where('metode', 'hutang')->first();
        if (! $j) {
            throw new ApiError(404, 'Piutang tidak ditemukan');
        }
        if ($j->status !== 'sah') {
            throw new ApiError(409, 'Transaksi ini sudah dibatalkan');
        }

        $sudah = (int) MartPiutangBayar::where('jual_id', $j->id)->sum('jumlah');
        $sisa = (int) $j->total - $sudah;
        if ($sisa <= 0) {
            throw new ApiError(409, 'Piutang ini sudah lunas');
        }

        $jumlah = (int) floor((float) $r->input('jumlah', 0));
        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal pembayaran harus lebih dari nol');
        }
        if ($jumlah > $sisa) {
            throw new ApiError(400, 'Pembayaran (' . Rupiah::f($jumlah) .
                ') melebihi sisa piutang ' . Rupiah::f($sisa) .
                ' — kelebihan uang tidak disimpan di sini, kembalikan saja ke pembayarnya');
        }

        $metode = $r->input('metode') === 'transfer' ? 'transfer' : 'tunai';
        $bank = '';
        $pengirim = '';
        if ($metode === 'transfer') {
            $bank = mb_substr(trim((string) $r->input('bank', '')), 0, 40);
            $pengirim = mb_substr(trim((string) $r->input('pengirim', '')), 0, 80);
            if ($bank === '' || mb_strlen($pengirim) < 3) {
                throw new ApiError(400, 'Transfer wajib menyebut bank dan nama pengirim (minimal 3 huruf)');
            }
        }

        $op = $this->operator;
        $bayar = DB::transaction(fn () => MartPiutangBayar::create([
            'jual_id' => $j->id, 'tenant_id' => $t->id, 'member_id' => (int) $j->member_id,
            'jumlah' => $jumlah, 'metode' => $metode, 'bank' => $bank, 'pengirim' => $pengirim,
            'catatan' => mb_substr(trim((string) $r->input('catatan', '')), 0, 160),
            'operator' => $op, 'created_at' => WaktuIso::sekarang(),
        ]));

        return response()->json(['ok' => true, 'bayar' => $bayar,
            'sisa' => $sisa - $jumlah, 'lunas' => $sisa - $jumlah === 0]);
    }

    /** Riwayat cicilan — untuk layar piutang dan untuk menelusuri tutup kas. */
    public function riwayatBayar(Request $r)
    {
        $t = $this->martKu($r);
        $nama = MartMember::where('tenant_id', $t->id)->pluck('nama', 'id');

        return response()->json(['bayar' => MartPiutangBayar::where('tenant_id', $t->id)
            ->when($r->query('member_id'), fn ($q) => $q->where('member_id', (int) $r->query('member_id')))
            ->when($r->query('dari'), fn ($q) => $q->where('created_at', '>=', $r->query('dari')))
            ->when($r->query('sampai'), fn ($q) => $q->where('created_at', '<=', $r->query('sampai')))
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn ($b) => array_merge($b->toArray(), [
                'no' => 'MRT-' . str_pad((string) $b->jual_id, 6, '0', STR_PAD_LEFT),
                'member' => $nama[$b->member_id] ?? '(member terhapus)',
            ]))]);
    }
}
