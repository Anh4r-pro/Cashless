<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan sekolah — HANYA menampung angka (batas saldo, batas harian,
 * sakelar fitur). Nilai berupa teks punya tabelnya sendiri: TopupInfo untuk
 * instruksi pembayaran, KiosConfig untuk sandi layar kios.
 */
class Setting extends Model
{
    public $timestamps = false;

    protected $table = 'settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'int'];

    /** Seluruh pengaturan sebagai peta key => angka. */
    public static function semua(): array
    {
        return static::query()->pluck('value', 'key')
            ->map(fn ($v) => (int) $v)->all();
    }

    public static function ambil(string $key, int $bawaan = 0): int
    {
        $row = static::find($key);

        return $row ? (int) $row->value : $bawaan;
    }

    public static function simpan(string $key, int $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
