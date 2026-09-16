<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\WaktuIso;

/**
 * Buku besar saldo titipan. `ref` adalah kunci idempotensi (UNIQUE):
 * kiriman ulang impor atau klik ganda ditolak basis data, bukan kode.
 */
class TitipanMutasi extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'titipan_mutasi';

    protected $fillable = [
        'siswa_id',
        'jenis',
        'jumlah',
        'titipan_sesudah',
        'transaksi_id',
        'operator',
        'keterangan',
        'ref',
        'created_at',
    ];

    protected $casts = [
            'jumlah' => 'int',
            'titipan_sesudah' => 'int',
    ];

    public function siswa(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Siswa::class, 'siswa_id');
    }

    public function transaksi(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Transaksi::class, 'transaksi_id');
    }
}
