<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Buku besar saldo kartu — SATU-SATUNYA sumber kebenaran saldo.
 *
 * Kolom siswa_nama / kelas / tenant_nama sengaja didenormalisasi: riwayat dan
 * laporan harus tetap menyebut keadaan saat transaksi terjadi, bukan keadaan
 * hari ini. tx_id UNIQUE berperan sebagai rem anti-dobel-catat.
 */
class Transaksi extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'transaksi';

    protected $fillable = [
        'tx_id', 'tipe', 'siswa_id', 'siswa_nama', 'kelas', 'tenant_id', 'tenant_nama',
        'jumlah', 'saldo_sesudah', 'operator', 'keterangan', 'donasi_id', 'nonmakanan', 'kecuali',
        'lawan_id', 'lawan_nama', 'created_at',
    ];

    protected $casts = [
        'jumlah' => 'int',
        'saldo_sesudah' => 'int',
        'nonmakanan' => 'int',
        'kecuali' => 'int',
    ];

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TransaksiItem::class, 'transaksi_id');
    }

    public function siswa(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Siswa::class, 'siswa_id');
    }

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
