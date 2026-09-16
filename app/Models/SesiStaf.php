<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sesi staf berbasis token (cookie `sid`). Sengaja tabel sendiri, bukan sesi
 * Laravel: kontrak cookie dan masa berlakunya dipakai langsung oleh frontend
 * dan suite uji, dan menyimpannya sebagai baris membuat pencabutan sesi
 * (logout paksa, ganti sandi) menjadi satu DELETE, bukan menunggu kedaluwarsa.
 */
class SesiStaf extends Model
{
    public $timestamps = false;

    protected $table = 'sessions';

    protected $primaryKey = 'token';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['token', 'user_id', 'expires_at'];

    protected $casts = ['expires_at' => 'int'];
}
