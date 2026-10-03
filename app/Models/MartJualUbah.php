<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Jejak satu pembetulan atau pembatalan transaksi Mart.
 *
 * Baris di sini TIDAK PERNAH diubah maupun dihapus. Inilah satu-satunya
 * tempat yang bisa menjawab "kenapa penjualan tadi pagi angkanya lain
 * sekarang?", dan jawaban itu kehilangan artinya kalau ia sendiri bisa
 * disunting.
 */
class MartJualUbah extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'mart_jual_ubah';

    protected $fillable = ['jual_id', 'tenant_id', 'jenis', 'alasan', 'total_lama',
        'total_baru', 'ringkas', 'sebelum', 'sesudah', 'operator', 'created_at'];

    protected $casts = ['total_lama' => 'int', 'total_baru' => 'int'];
}
