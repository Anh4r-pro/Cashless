<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Menu/barang jualan. `kategori` menentukan batas harian mana yang berlaku
 * saat barang itu dibeli (makanan vs non-makanan koperasi).
 */
class Produk extends Model
{
    protected $table = 'produk';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'nama',
        // Barcode dipasang/diganti LANGSUNG oleh kasir Mart tanpa lewat
        // pengajuan: ia penanda barang, bukan syarat dagang seperti nama &
        // harga yang menentukan berapa santri dibebani.
        'barcode',
        // Barcode kedua, khusus satuan grosir: karton punya kode sendiri
        // (ITF-14) yang berbeda dari kode bungkusnya (EAN-13). Hanya boleh
        // terisi kalau barangnya memang disiapkan grosir — lihat
        // MartStokController::simpan().
        'barcode_grosir',
        'harga',
        'aktif',
        'kategori',
        // Hanya bermakna saat kategori='non_makanan': barang ini dikecualikan
        // TOTAL dari limit non-makanan harian (lihat JatahKecuali). NULL =
        // ikut limit seperti biasa, keadaan seluruh barang lama.
        'jatah_kecuali_id',
        // Khusus Mart: kategori dagang (etalase), modal, dan stok.
        // `stok` NULL berarti barang ini tidak dilacak stoknya sama sekali.
        'kategori_id',
        'hpp',
        'stok',
        'stok_min',
        // Grosir: satu barcode, dua satuan. `isi_grosir` NULL = tidak dijual
        // grosir, dan itu keadaan seluruh barang lama (termasuk barang kantin
        // biasa, yang memakai tabel ini juga).
        'isi_grosir',
        'hpp_grosir',
        'harga_grosir',
        // Satu tanggal kedaluwarsa per barang, diperbarui saat nota masuk.
        'expired',
    ];

    protected $casts = [
            'aktif' => 'integer',
            'harga' => 'int',
            'hpp' => 'int',
            'stok' => 'int',
            'stok_min' => 'int',
    ];

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function jatahKecuali(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(JatahKecuali::class, 'jatah_kecuali_id');
    }

    /**
     * Barang lain yang sudah memakai kode ini — pada SLOT MANA PUN.
     *
     * Satu kode yang dipindai harus menunjuk tepat satu (barang, satuan).
     * Karena itu pemeriksaannya silang dua kolom: barcode satuan tidak boleh
     * menabrak barcode dus barang lain, dan sebaliknya. Memeriksa satu kolom
     * saja menyisakan celah yang baru ketahuan saat kasir memindai dan
     * mendapat barang yang keliru di depan pembeli.
     *
     * Lingkupnya per tenant, mengikuti aturan yang sudah berlaku untuk nama
     * barang dan barcode satuan: dua tenant boleh menjual barang bermerek sama.
     *
     * @param  int|null  $kecuali  id barang yang sedang disunting
     */
    public static function bentrokBarcode(int $tenantId, string $kode, ?int $kecuali = null): ?self
    {
        if ($kode === '') {
            return null;
        }

        return self::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('barcode', $kode)->orWhere('barcode_grosir', $kode))
            ->when($kecuali, fn ($q) => $q->where('id', '!=', $kecuali))
            ->first();
    }

    /** Apakah barang ini benar-benar disiapkan untuk dijual per dus/karton? */
    public function siapGrosir(): bool
    {
        return (int) ($this->isi_grosir ?? 0) >= 2 && (int) ($this->harga_grosir ?? 0) > 0;
    }
}
