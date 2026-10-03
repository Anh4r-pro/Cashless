<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/** Pemasok barang IMBOS Mart. */
class MartSupplier extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'mart_supplier';

    protected $fillable = ['tenant_id', 'kode', 'nama', 'hp', 'alamat',
        'keterangan', 'aktif', 'created_at'];

    protected $casts = ['aktif' => 'bool'];
}
