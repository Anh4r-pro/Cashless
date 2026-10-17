<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Kategori member, penentu siapa yang BOLEH berhutang.
 *
 * Menyaring lebih dulu daripada plafon, tidak menggantikannya: kategori yang
 * `boleh_hutang`-nya mati menolak hutang berapa pun plafon orangnya. Member
 * yang belum dikategorikan (kategori_id NULL) berperilaku seperti sebelum
 * kategori ini ada — hanya plafonnya yang menentukan.
 */
class MartMemberKategori extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'mart_member_kategori';

    protected $fillable = ['tenant_id', 'nama', 'boleh_hutang', 'urutan', 'aktif', 'created_at'];

    protected $casts = ['boleh_hutang' => 'bool', 'urutan' => 'int', 'aktif' => 'bool'];
}
