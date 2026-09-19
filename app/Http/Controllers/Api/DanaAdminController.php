<?php

namespace App\Http\Controllers\Api;

use App\Models\DanaAdminMutasi;
use App\Services\Berkas;
use App\Services\DanaAdmin;
use Illuminate\Http\Request;

/**
 * Dana Admin — pos dana milik sekolah tempat bermuaranya biaya admin.
 *
 * Perlu ditegaskan di sini juga karena inilah layar yang dibaca bendahara:
 * ini BUKAN rekening bank. Uangnya berada di rekening sekolah yang sama,
 * bercampur dengan titipan wali. "Menarik" di sini tidak memindahkan apa pun
 * secara otomatis — ia MENCATAT bahwa sekolah sudah mengambil bagiannya.
 * Pemindahan fisiknya dikerjakan bendahara di bank, dan catatan inilah yang
 * membuat pengambilan itu bisa dipertanggungjawabkan.
 *
 * Hak melihat dan hak menarik sengaja dipisah: bendahara boleh memantau
 * posisinya tanpa otomatis berhak mengambil uangnya.
 */
class DanaAdminController extends Controller
{
    public function index(Request $r)
    {
        $this->wajibIzin($r, 'dana.lihat');

        $q = DanaAdminMutasi::query()->orderByDesc('id');
        if ($jenis = $r->query('jenis')) {
            if (in_array($jenis, ['masuk', 'tarik'], true)) {
                $q->where('jenis', $jenis);
            }
        }
        if ($dari = $r->query('dari')) {
            $q->where('created_at', '>=', $dari);
        }
        if ($sampai = $r->query('sampai')) {
            $q->where('created_at', '<=', $sampai . 'T23:59:59.999Z');
        }

        $limit = min(200, (int) $r->query('limit', 50) ?: 50);

        return response()->json([
            'ringkas' => DanaAdmin::ringkas(),
            // Rekap bulanan: angka total seumur sistem tidak berguna untuk
            // pembukuan, dan tanpa ini bendahara harus menjumlahkan sendiri
            // dari Riwayat setiap akhir bulan.
            'per_bulan' => DanaAdmin::perBulan(12),
            'mutasi' => $q->limit($limit)->get(),
            'boleh_tarik' => \App\Services\Izin::boleh($this->staf($r), 'dana.tarik'),
        ]);
    }

    public function tarik(Request $r)
    {
        $u = $this->wajibIzin($r, 'dana.tarik');

        // Konfirmasi kedua dari layar ikut dikirim. Bukan lapis keamanan —
        // klien mana pun bisa menyertakannya — melainkan pengaman agar klik
        // nyasar atau permintaan yang terulang tidak diam-diam mengambil uang.
        if ($r->input('konfirmasi') !== true) {
            throw new \App\Exceptions\ApiError(400, 'Penarikan harus melewati konfirmasi kedua');
        }

        $bukti = null;
        if ($data = $r->input('bukti')) {
            $bukti = Berkas::simpanGambarBase64((string) $data, 'dana');
        }

        $m = DanaAdmin::tarik(
            (int) floor((float) $r->input('jumlah')),
            $u->nama,
            (string) $r->input('keterangan', ''),
            $bukti,
            $r->input('ref') ? (string) $r->input('ref') : null,
        );

        return response()->json(['ok' => true, 'mutasi' => $m, 'ringkas' => DanaAdmin::ringkas()]);
    }
}
