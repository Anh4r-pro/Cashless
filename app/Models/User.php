<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Akun staf: sisi admin (role `superadmin`) atau kasir satu kantin (`tenant`).
 *
 * Peran menentukan jangkauan data, bukan sekadar menu: akun tenant tidak
 * pernah bisa membaca transaksi tenant lain, dan pembatasannya dipasang di
 * kueri, bukan di tampilan.
 *
 * Di dalam sisi admin, yang memilah aksi adalah `kategori_id` — kategori akses
 * yang sakelar fiturnya disetel admin sendiri (App\Services\Izin).
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
        'kategori_id',
        'tenant_id',
        'created_at',
    ];

    protected $hidden = ['password'];

    protected $casts = ['password' => 'hashed'];

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Kategori akses. NULL berarti akun superadmin warisan yang memegang
     * seluruh katalog — lihat App\Services\Izin::milik().
     */
    public function kategori(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(KategoriAkses::class, 'kategori_id');
    }

    public function superadmin(): bool
    {
        return $this->role === 'superadmin';
    }
}
