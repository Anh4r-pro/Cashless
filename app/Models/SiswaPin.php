<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PIN kios santri. Tabel terpisah dari `santri`, bukan kolom di sana: banyak
 * endpoint mengembalikan baris santri apa adanya ke klien, jadi menaruh hash
 * di tabel utama berisiko ikut terkirim ke layar admin/kasir tanpa disadari.
 *
 * TIDAK ada baris = PIN masih bawaan (NIS santri). Admin mereset PIN dengan
 * menghapus barisnya, bukan menulis nilai bawaan.
 */
class SiswaPin extends Model
{
    public $timestamps = false;

    protected $table = 'siswa_pin';

    protected $primaryKey = 'siswa_id';

    public $incrementing = false;

    protected $fillable = ['siswa_id', 'pin_hash', 'updated_at'];

    protected $hidden = ['pin_hash'];
}
