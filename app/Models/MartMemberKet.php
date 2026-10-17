<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Pilihan untuk kolom "Keterangan" member — daftar yang dikelola admin.
 *
 * Kolom `mart_member.keterangan` tetap berupa TEKS, bukan kunci asing ke sini:
 * daftar ini hanya memasok pilihannya. Dengan begitu keterangan bebas yang
 * sudah terlanjur diketik tetap terbaca apa adanya, dan menghapus satu pilihan
 * tidak mengosongkan keterangan member yang sudah memakainya.
 */
class MartMemberKet extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'mart_member_ket';

    protected $fillable = ['tenant_id', 'teks', 'urutan', 'aktif', 'created_at'];

    protected $casts = ['urutan' => 'int', 'aktif' => 'bool'];
}
