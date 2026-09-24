<?php

namespace App\Http\Controllers\Api;

use App\Models\ProdukAjuan;
use App\Models\TopupRequest;
use App\Services\Izin;
use Illuminate\Http\Request;

/**
 * Hitungan pekerjaan yang MENUNGGU keputusan admin.
 *
 * Endpoint ini sengaja dibuat sekecil mungkin karena ia dipanggil berulang
 * selama admin membuka aplikasi. Jawabannya hanya dua angka — di bawah 40 byte
 * sebelum header — jadi biayanya jauh lebih murah daripada satu kali memuat
 * halaman, bahkan di sambungan yang buruk. Tidak ada daftar, tidak ada nama,
 * tidak ada nominal: semua itu sudah ada di layarnya masing-masing.
 *
 * Yang dihitung hanya yang BOLEH DILIHAT akun itu. Admin yang tidak berhak
 * membuka antrean topup tidak akan melihat lencananya — angka yang menuntut
 * tindakan tapi layarnya terkunci hanya membingungkan, dan sekaligus
 * membocorkan bahwa ada sesuatu di sana.
 */
class NotifController extends Controller
{
    public function hitung(Request $r)
    {
        $u = $this->wajibPeran($r, 'superadmin');

        return response()->json([
            'topup' => Izin::boleh($u, 'topup_ortu.lihat')
                ? TopupRequest::where('status', 'pending')->count() : 0,
            'menu' => Izin::boleh($u, 'ajuan.lihat')
                ? ProdukAjuan::where('status', 'menunggu')->count() : 0,
        ]);
    }
}
