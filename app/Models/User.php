<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Akun staf: superadmin (satu sekolah) atau tenant (satu kantin/koperasi).
 *
 * Peran menentukan jangkauan data, bukan sekadar menu: akun tenant tidak
 * pernah bisa membaca transaksi tenant lain, dan pembatasannya dipasang di
 * kueri, bukan di tampilan.
 */
class User extends Authenticatable
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'users';

    protected $fillable = [
        'username',
        'password',
        'nama',
        'role',
        'tenant_id',
        'created_at',
    ];

    protected $hidden = ['password'];

    protected $casts = ['password' => 'hashed'];

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function superadmin(): bool
    {
        return $this->role === 'superadmin';
    }
}
