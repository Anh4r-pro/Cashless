<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\WaktuIso;

/**
 * Rincian baris belanja. nama & harga DIDENORMALISASI dengan sengaja: menu
 * bisa berganti nama atau naik harga, sedangkan laporan bulan lalu harus
 * tetap menyebut apa yang benar-benar terjual saat itu.
 */
class TransaksiItem extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'transaksi_item';

    protected $fillable = [
        'transaksi_id',
        'produk_id',
        'tenant_id',
        'tenant_nama',
        'nama',
        'kategori',
        'jatah_kecuali_id',
        'jatah_kecuali_keterangan',
        'satuan',
        'isi',
        'harga',
        'hpp',
        'qty',
        'subtotal',
        'siswa_id',
        'kelas',
        'golongan',
        'created_at',
    ];

    protected $casts = [
            'harga' => 'int',
            'qty' => 'int',
            'subtotal' => 'int',
    ];

    public function transaksi(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Transaksi::class, 'transaksi_id');
    }

    public function produk(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Produk::class, 'produk_id');
    }
}
