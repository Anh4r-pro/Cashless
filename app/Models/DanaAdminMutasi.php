<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu pergerakan Dana Admin: biaya yang masuk, atau uang yang ditarik sekolah.
 *
 * `saldo_sesudah` ikut disimpan pada tiap baris, seperti pada buku besar
 * transaksi. Dengan begitu posisi dana bisa diperiksa ulang dari baris mana
 * pun, dan penyimpangan ketahuan tanpa harus menjumlahkan seluruh riwayat.
 */
class DanaAdminMutasi extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'dana_admin_mutasi';

    protected $fillable = [
        'jenis', 'jumlah', 'saldo_sesudah', 'ref', 'transaksi_id', 'siswa_id',
        'operator', 'keterangan', 'bukti', 'created_at',
    ];

    protected $casts = [
        'jumlah' => 'integer',
        'saldo_sesudah' => 'integer',
    ];
}
