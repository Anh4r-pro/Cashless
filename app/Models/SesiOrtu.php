<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sesi portal orang tua (cookie `osid`). Terpisah TOTAL dari sesi staf supaya
 * kredensial ringan wali — NISN + tanggal lahir + 4 digit HP — tidak pernah
 * bisa menjelma menjadi sesi admin.
 */
class SesiOrtu extends Model
{
    public $timestamps = false;

    protected $table = 'ortu_sessions';

    protected $primaryKey = 'token';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['token', 'siswa_id', 'expires_at', 'remember', 'login_at'];

    protected $casts = ['expires_at' => 'int', 'login_at' => 'int', 'remember' => 'bool'];
}
