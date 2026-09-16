<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kategori alasan pemotongan saldo — hanya daftar pilihan cepat. Yang
 * tersimpan di transaksi tetap TEKS alasannya, jadi menghapus kategori
 * tidak pernah mengubah riwayat yang sudah ada.
 */
class AlasanPotong extends Model
{
    protected $table = 'alasan_potong';

    public $timestamps = false;

    protected $fillable = [
        'teks',
        'urutan',
        'aktif',
    ];

    protected $casts = [
            'aktif' => 'integer',
    ];
}
