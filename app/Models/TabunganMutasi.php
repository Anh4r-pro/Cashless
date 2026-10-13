<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Buku besar TABUNGAN — mirip TitipanMutasi, tapi per jenis. `transaksi_id`
 * menunjuk baris kartu pasangannya (tipe 'tabungan') kalau sebagian/seluruh
 * nominal menyentuh saldo kartu, supaya dua sisi mutasi selalu bisa dicocokkan.
 */
class TabunganMutasi extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'tabungan_mutasi';

    protected $fillable = [
        'siswa_id',
        'jenis_id',
        'jenis_mutasi',
        'jumlah',
        'saldo_sesudah',
        'sumber',
        'transaksi_id',
        'operator',
        'keterangan',
        'ref',
        'created_at',
    ];

    protected $casts = [
        'jumlah' => 'integer',
        'saldo_sesudah' => 'integer',
    ];

    public function jenis(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TabunganJenis::class, 'jenis_id');
    }
}
