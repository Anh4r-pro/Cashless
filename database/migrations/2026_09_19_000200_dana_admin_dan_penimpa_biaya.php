<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dana Admin — pos dana milik sekolah, tempat bermuaranya biaya admin.
 *
 * BUKAN rekening bank. Uangnya tetap berada di rekening sekolah yang sama,
 * bercampur dengan titipan wali; yang dilacak di sini adalah berapa bagian
 * dari saldo rekening itu yang sudah menjadi HAK SEKOLAH dan boleh dipakai.
 *
 * Kehadirannya menggeser persamaan neraca. Sebelum ada pos ini, biaya
 * diperlakukan langsung keluar dari kas begitu dipungut. Sekarang uangnya
 * masih ada sampai benar-benar ditarik, jadi kas terbagi dua:
 *
 *     kas = kewajiban (milik orang lain) + dana admin (milik sekolah)
 *
 * Tanpa memasukkan dana admin ke sisi klaim, kas akan melebihi kewajiban
 * sebesar akumulasi biaya dan neraca melaporkan selisih yang tidak bisa
 * dijelaskan siapa pun.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('dana_admin_mutasi', function (Blueprint $t) {
            $t->id();
            // masuk = biaya yang dipungut; tarik = uang yang diambil sekolah.
            $t->enum('jenis', ['masuk', 'tarik']);
            $t->bigInteger('jumlah');                    // selalu positif
            $t->bigInteger('saldo_sesudah');             // posisi dana sesudah baris ini
            /*
             * `ref` UNIK adalah pengaman idempotensi, bukan sekadar catatan.
             * Tanpa itu, satu pengajuan yang tersetujui dua kali karena klik
             * ganda akan menambah dana dua kali sementara wali hanya dipungut
             * sekali — dan selisihnya tidak akan pernah tertelusur.
             */
            $t->string('ref')->nullable()->unique();
            $t->foreignId('transaksi_id')->nullable()->constrained('transaksi');
            $t->foreignId('siswa_id')->nullable()->constrained('siswa');
            $t->string('operator');
            $t->string('keterangan', 300)->default('');
            $t->string('bukti')->nullable();             // berkas di uploads/
            $t->char('created_at', 24)->nullable();
            $t->index(['jenis', 'created_at']);
        });

        Schema::table('golongan', function (Blueprint $t) {
            /*
             * Sakelar hidup/mati, BUKAN kolom nominal yang boleh kosong.
             *
             * Tujuh kolom batas di sebelahnya memakai aturan "kosong = ikut
             * sekolah". Untuk biaya, aturan itu berbahaya: menyalakan portal
             * wali bagi sebuah golongan akan langsung memungut uang dari
             * mereka tanpa keputusan terpisah. Sakelar yang mati sejak lahir
             * membuat golongan mana pun aman sampai dicentang dengan sadar.
             */
            $t->boolean('biaya_admin_kena')->default(false)->after('transfer');
        });

        Schema::table('siswa', function (Blueprint $t) {
            /*
             * Penimpa per orang: NULL = ikut golongannya, 0 = dibebaskan,
             * angka = tarif sendiri. Dipakai untuk santri berprestasi atau
             * penerima beasiswa, tanpa harus memindahkan golongannya — yang
             * akan ikut mengubah seluruh batas belanjanya.
             */
            $t->bigInteger('biaya_admin')->nullable()->after('berlaku_sampai');
        });

        Schema::table('topup_request', function (Blueprint $t) {
            /*
             * Tarif DIKUNCI saat wali mengajukan.
             *
             * Wali sudah benar-benar mentransfer uang berdasarkan angka yang
             * ditampilkan di layarnya. Kalau tarif naik selagi pengajuannya
             * menunggu di antrean, menagihnya dengan angka baru berarti
             * mengenakan harga yang tidak pernah ia setujui — sesudah uangnya
             * terlanjur dikirim. Yang dipungut adalah yang terkecil antara
             * tarif terkunci dan tarif saat disetujui: tidak pernah lebih mahal
             * dari yang dijanjikan, tapi ikut turun kalau tarifnya turun.
             */
            $t->bigInteger('biaya_admin')->nullable()->after('jumlah');
        });
    }

    public function down(): void
    {
        Schema::table('topup_request', fn (Blueprint $t) => $t->dropColumn('biaya_admin'));
        Schema::table('siswa', fn (Blueprint $t) => $t->dropColumn('biaya_admin'));
        Schema::table('golongan', fn (Blueprint $t) => $t->dropColumn('biaya_admin_kena'));
        Schema::dropIfExists('dana_admin_mutasi');
    }
};
