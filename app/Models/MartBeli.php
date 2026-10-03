<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu nota pembelian dari pemasok.
 *
 * Metode 'tempo' berarti barangnya sudah di rak tetapi uangnya belum
 * dibayarkan — kebalikan persis dari piutang member.
 */
class MartBeli extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'mart_beli';

    protected $fillable = ['tenant_id', 'supplier_id', 'no_nota', 'metode', 'status',
        'tanggal', 'jatuh_tempo', 'subtotal', 'diskon', 'total', 'n_item',
        'catatan', 'operator', 'created_at'];

    protected $casts = ['subtotal' => 'int', 'diskon' => 'int', 'total' => 'int', 'n_item' => 'int'];

    /** Hanya nota yang masih sah — dipakai setiap kali angka dijumlahkan. */
    public function scopeSah($q)
    {
        return $q->where('status', 'sah');
    }
}
