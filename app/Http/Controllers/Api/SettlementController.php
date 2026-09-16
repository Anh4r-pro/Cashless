<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Models\Settlement;
use App\Models\Tenant;
use App\Services\Berkas;
use App\Services\Rupiah;
use App\Services\StatistikTenant;
use Illuminate\Http\Request;

/** Setoran hasil jualan tenant ke sekolah. */
class SettlementController extends Controller
{
    public function index(Request $r)
    {
        $u = $this->wajibPeran($r, 'tenant', 'superadmin');
        $tid = $u->role === 'tenant' ? $u->tenant_id : ((int) $r->query('tenant_id') ?: null);

        $q = Settlement::query()
            ->join('tenant', 'tenant.id', '=', 'settlement.tenant_id')
            ->select('settlement.*', 'tenant.nama as tenant_nama')
            ->orderByDesc('settlement.created_at')->limit(200);

        if ($tid) {
            $q->where('settlement.tenant_id', $tid);
        }

        return response()->json(['settlements' => $q->get()]);
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
