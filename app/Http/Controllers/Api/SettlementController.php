<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\Settlement;
use App\Models\Tenant;
use App\Services\Berkas;
use App\Services\Ekspor;
use App\Services\Rupiah;
use App\Services\StatistikTenant;
use Illuminate\Http\Request;

/** Setoran hasil jualan tenant ke sekolah. */
class SettlementController extends Controller
{
    public function index(Request $r)
    {
        $u = $this->wajibPeran($r, 'tenant', 'superadmin');
        $this->izinJikaAdmin($r, $u, 'dashboard.lihat');
        $tid = $u->role === 'tenant' ? $u->tenant_id : ((int) $r->query('tenant_id') ?: null);

        $dari = $r->query('dari');
        $sampai = $r->query('sampai');
        $cari = mb_substr(trim((string) $r->query('cari', '')), 0, 60);
        $like = '%' . $cari . '%';

        $q = Settlement::query()
            ->join('tenant', 'tenant.id', '=', 'settlement.tenant_id')
            ->select('settlement.*', 'tenant.nama as tenant_nama')
            ->when($tid, fn ($w) => $w->where('settlement.tenant_id', $tid))
            ->when($dari, fn ($w) => $w->where('settlement.created_at', '>=', $dari))
            ->when($sampai, fn ($w) => $w->where('settlement.created_at', '<=', $sampai))
            // "petugas pencairan" = operator yang mencatat setoran ini.
            ->when($cari !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('tenant.nama', 'like', $like)
                ->orWhere('settlement.operator', 'like', $like)));

        if ($r->query('format')) {
            return $this->unduh((clone $q)->orderByDesc('settlement.created_at')->get(),
                Ekspor::formatSah($r->query('format')), $dari, $sampai);
        }

        // Paginasi OPSIONAL lewat ?hal=, pola sama persis TenantController::
        // index — pemanggil lama (dashboard tenant sendiri, "Riwayat Setoran
        // dari Admin") tidak mengirim ?hal= dan tetap menerima 200 baris
        // terakhir seperti sebelumnya, tanpa dari/sampai/cari (yang memang
        // tidak pernah dikirimnya).
        if (! $r->has('hal')) {
            return response()->json(['settlements' => $q->orderByDesc('settlement.created_at')->limit(200)->get()]);
        }

        $hal = max(1, (int) $r->query('hal', 1));
        $per = min(100, max(10, (int) $r->query('per', 10) ?: 10));
        $total = (clone $q)->count();
        $nHalaman = max(1, (int) ceil($total / $per));
        $baris = $q->orderByDesc('settlement.created_at')->limit($per)->offset(($hal - 1) * $per)->get();

        return response()->json(['settlements' => $baris, 'hal' => $hal, 'n_halaman' => $nHalaman]);
    }

    /** Riwayat setoran sebagai berkas unduhan (.xls/.doc/.csv) — PDF selalu lewat dialog cetak peramban. */
    private function unduh($rows, string $format, ?string $dari, ?string $sampai)
    {
        $kepala = ['Tanggal', 'Tenant', 'Jumlah (Rp)', 'Metode', 'Petugas Pencairan', 'Keterangan'];
        $isi = $rows->map(fn ($s) => [
            substr((string) $s->created_at, 0, 16), $s->tenant_nama, (int) $s->jumlah,
            $s->metode, $s->operator, $s->keterangan,
        ])->all();
        $judul = 'Riwayat Setoran ke Tenant';
        $catatan = array_filter([($dari || $sampai) ? 'Periode: ' . ($dari ?: '…') . ' s/d ' . ($sampai ?: '…') : null]);
        $nama = 'setoran-tenant' . ($dari ? "-{$dari}" : '') . ($sampai ? "-sd-{$sampai}" : '') . ".{$format}";
        [$tipe] = Ekspor::TIPE[$format];

        $badan = $format === 'csv'
            ? Ekspor::csvUmum($kepala, array_merge($isi, [[]], [[$judul]], array_map(fn ($c) => [$c], $catatan)))
            : Ekspor::tabelUmum($judul, $kepala, $isi, $catatan, [2]);

        return response($badan, 200, [
            'Content-Type' => $tipe,
            'Content-Disposition' => "attachment; filename=\"{$nama}\"",
        ]);
    }

    public function store(Request $r)
    {
        $u = $this->wajibPeran($r, 'superadmin');
        $tenantId = (int) $r->input('tenant_id');
        $jumlah = (int) floor((float) $r->input('jumlah'));
        $metode = (string) $r->input('metode');

        if (! Tenant::whereKey($tenantId)->exists()) {
            throw new ApiError(404, 'Tenant tidak ditemukan');
        }
        if ($jumlah <= 0) {
            throw new ApiError(400, 'Nominal tidak valid');
        }
        if (! in_array($metode, ['cash', 'transfer'], true)) {
            throw new ApiError(400, 'Metode harus cash atau transfer');
        }

        // Setoran tidak boleh melebihi tagihan berjalan: selisihnya akan muncul
        // sebagai uang entah dari mana di neraca kas.
        $stats = StatistikTenant::untuk($tenantId);
        if ($jumlah > $stats['outstanding']) {
            throw new ApiError(400, 'Nominal melebihi tagihan berjalan (' . Rupiah::f($stats['outstanding']) . ')');
        }

        $bukti = $r->input('bukti_data')
            ? Berkas::simpanGambarBase64((string) $r->input('bukti_data'), 'bukti') : null;

        $s = Settlement::create([
            'tenant_id' => $tenantId, 'jumlah' => $jumlah, 'metode' => $metode,
            'bukti' => $bukti, 'keterangan' => mb_substr((string) $r->input('keterangan', ''), 0, 300),
            'operator' => $u->nama,
        ])->fresh();

        return response()->json(['ok' => true, 'settlement' => $s]);
    }

    public function bukti(Request $r, int $id)
    {
        $this->wajibPeran($r, 'superadmin');
        $s = Settlement::find($id);
        if (! $s) {
            throw new ApiError(404, 'Setoran tidak ditemukan');
        }
        $nama = Berkas::simpanGambarBase64((string) $r->input('data'), 'bukti');
        Berkas::hapus($s->bukti);
        $s->update(['bukti' => $nama]);

        return response()->json(['ok' => true, 'bukti' => $nama]);
    }
}
