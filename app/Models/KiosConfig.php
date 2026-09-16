<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sandi keluar mode layar penuh kios.
 * Tabel key/value bertipe TEKS, terpisah dari `settings` yang hanya angka.
 */
class KiosConfig extends Model
{
    public $timestamps = false;

    protected $table = 'kios_config';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    /** Seluruh baris sebagai peta key => teks. */
    public static function semua(): array
    {
        return static::query()->pluck('value', 'key')->all();
    }

    public static function ambil(string $key, string $bawaan = ''): string
    {
        $row = static::find($key);

        return $row ? (string) $row->value : $bawaan;
    }

    public static function simpan(string $key, string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
