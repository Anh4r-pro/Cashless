<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fondasi IMBOS Mart tahap dua: stok, modal (HPP), dan kategori dagang.
 *
 * `produk.stok` sengaja NULLABLE, dan NULL berarti "tidak dilacak".
 * Kantin biasa menjual gorengan yang dibuat dadakan — memaksa mereka
 * punya angka stok hanya akan melahirkan angka yang tidak pernah benar.
 * Jadi pengurangan stok saat penjualan hanya berlaku untuk barang yang
 * memang punya angka stok, dan tenant lama sama sekali tidak tersentuh.
 *
 * `kategori` lama (makanan / non_makanan) TETAP ADA dan tidak diganti: ia
 * menentukan jatah belanja harian santri, bukan sekadar label. Kategori
 * dagang yang bisa disunting admin (Sembako, ATK, …) berdiri di kolom
 * terpisah — dua hal yang kebetulan sama-sama bernama "kategori", tetapi
 * sama sekali berbeda akibatnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mart_kategori', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenant');
            $t->string('nama', 40);
            $t->integer('urutan')->default(0);
            $t->boolean('aktif')->default(true);
            $t->char('created_at', 24)->nullable();
            $t->index(['tenant_id', 'urutan']);
        });

        Schema::table('produk', function (Blueprint $t) {
            $t->foreignId('kategori_id')->nullable()->after('kategori')
                ->constrained('mart_kategori')->nullOnDelete();
            $t->bigInteger('hpp')->nullable()->after('harga');      // modal per satuan
            $t->integer('stok')->nullable()->after('hpp');          // NULL = tidak dilacak
            $t->integer('stok_min')->default(5)->after('stok');     // ambang "menipis"
        });

        Schema::create('mart_stok_mutasi', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenant');
            $t->foreignId('produk_id')->constrained('produk');
            // jual/jual_batal dari kasir, masuk/koreksi dari admin, opname dari audit
            $t->enum('jenis', ['masuk', 'jual', 'koreksi', 'opname']);
            $t->integer('qty');                                      // + menambah, − mengurangi
            $t->integer('stok_sesudah');
            $t->bigInteger('nilai')->default(0);                     // qty × HPP saat itu
            $t->string('ref', 80)->default('');                      // tx_id penjualan / id opname
            $t->string('keterangan', 200)->default('');
            $t->string('operator')->default('');
            $t->char('created_at', 24)->nullable();
            $t->index(['tenant_id', 'created_at']);
            $t->index(['produk_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mart_stok_mutasi');
        Schema::table('produk', function (Blueprint $t) {
            $t->dropConstrainedForeignId('kategori_id');
            $t->dropColumn(['hpp', 'stok', 'stok_min']);
        });
        Schema::dropIfExists('mart_kategori');
    }
};
