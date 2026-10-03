<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Pelanggan terdaftar IMBOS Mart — dan satu-satunya yang boleh berhutang.
 *
 * `batas_hutang` 0 berarti tidak boleh berhutang. Bawaannya begitu dengan
 * sengaja: plafon adalah keputusan yang harus diambil seseorang, bukan hak
 * yang didapat hanya karena namanya terdaftar.
 */
class MartMember extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'mart_member';

    protected $fillable = ['tenant_id', 'kode', 'nama', 'hp', 'keterangan',
        'batas_hutang', 'aktif', 'created_at'];

    protected $casts = ['batas_hutang' => 'int', 'aktif' => 'bool'];
}
