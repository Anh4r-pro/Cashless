<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\WaktuIso;

/**
 * Pengajuan top up dari portal wali. Baris di sini BUKAN saldo — hanya klaim
 * yang menunggu diperiksa admin. transaksi_id adalah bukti pencairan:
 * selama terisi, pengajuan yang sama tidak bisa dicairkan dua kali.
 */
class TopupRequest extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'topup_request';

    protected $fillable = [
        'siswa_id',
        'siswa_nama',
        'kelas',
        'jumlah',
        // Tarif biaya admin yang DIKUNCI saat wali mengajukan — lihat
        // App\Services\BiayaAdmin::untukPengajuan().
        'biaya_admin',
        'metode',
        'tgl_bayar',
        'pengirim',
        'bukti',
        'bukti_sidik',
        'status',
        'alasan',
        'transaksi_id',
        'operator',
        'created_at',
        'decided_at',
    ];

    protected $casts = [
            'jumlah' => 'int',
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
