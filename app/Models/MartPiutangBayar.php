<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu kali pembayaran piutang.
 *
 * Dicatat terpisah dari penjualannya karena waktunya memang berbeda: barang
 * keluar hari ini, uangnya datang minggu depan. Tutup kas mencari uang ini di
 * hari PEMBAYARAN, bukan di hari penjualannya.
 */
class MartPiutangBayar extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'mart_piutang_bayar';

    protected $fillable = ['jual_id', 'tenant_id', 'member_id', 'jumlah', 'metode',
        'bank', 'pengirim', 'catatan', 'operator', 'created_at'];

    protected $casts = ['jumlah' => 'int'];
}
