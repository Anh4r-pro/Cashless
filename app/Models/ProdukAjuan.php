<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\WaktuIso;

/**
 * Pengajuan perubahan menu oleh tenant. Menu TIDAK pernah berubah sampai
 * disetujui superadmin — tidak ada perubahan harga diam-diam.
 */
class ProdukAjuan extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'produk_ajuan';

    protected $fillable = [
        'tenant_id',
        'produk_id',
        'jenis',
        'paket',
        'kategori_usulan',
        'nama',
        'harga',
        'nama_lama',
        'harga_lama',
        'status',
        'catatan',
        'pengaju',
        'penilai',
        'created_at',
        'decided_at',
    ];

    protected $casts = [
            'harga' => 'int',
            'harga_lama' => 'int',
    ];

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function produk(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Produk::class, 'produk_id');
    }
}
