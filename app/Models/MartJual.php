<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu penjualan TUNAI atau TRANSFER di IMBOS Mart.
 *
 * Sengaja berdiri sendiri, di luar tabel `transaksi`: uang tunai maupun
 * transfer tidak pernah masuk kas sekolah, jadi tidak boleh ikut terhitung
 * sebagai penjualan tenant maupun hutang sekolah. Lihat catatan di migrasi
 * `imbos_mart`.
 *
 * `bank` & `pengirim` hanya catatan kasir: sistem ini tidak terhubung ke bank
 * mana pun, dan dua kolom itulah yang dipakai mencocokkan ke mutasi rekening
 * saat tutup kas.
 */
class MartJual extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'mart_jual';

    // Kolom yang tidak terdaftar di sini DIBUANG DIAM-DIAM oleh Eloquent saat
    // create(): penjualan tetap tersimpan, hanya metode & diskonnya hilang.
    // Setiap kolom baru di mart_jual harus ikut ditambahkan ke daftar ini.
    protected $fillable = [
        'tenant_id', 'tx_id', 'metode', 'subtotal', 'diskon', 'total',
        'uang_diterima', 'kembalian', 'bank', 'pengirim',
        'n_item', 'operator', 'created_at',
    ];

    protected $casts = [
        'subtotal' => 'int', 'diskon' => 'int', 'total' => 'int',
        'uang_diterima' => 'int', 'kembalian' => 'int', 'n_item' => 'int',
    ];

    public function item(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MartJualItem::class, 'jual_id');
    }
}
