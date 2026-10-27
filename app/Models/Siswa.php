<?php

namespace App\Models;

use App\Support\WaktuIso;
use Illuminate\Database\Eloquent\Model;

/**
 * Pemegang kartu — santri maupun SDM, tamu, dan VIP.
 *
 * Satu tabel untuk semuanya adalah keputusan yang disengaja: seluruh buku besar
 * (transaksi, titipan_mutasi, settlement, neraca) menunjuk ke sini. Memecah SDM
 * ke tabelnya sendiri berarti memecah buku besarnya juga, dan persamaan neraca
 * kas ("tidak ada pintu lain") langsung kehilangan artinya.
 */
class Siswa extends Model
{
    use WaktuIso;

    public $timestamps = false;

    protected $table = 'siswa';

    protected $fillable = [
        'nis', 'nama', 'kelas', 'card_uid', 'saldo', 'status', 'foto',
        'nisn', 'tgl_lahir', 'hp_ortu',
        // Gender & kode keluarga — dasar aturan transfer antar gender.
        // SENGAJA tidak diturunkan dari `kelas` di mana pun: lihat migrasi
        // 2026_10_18_000100_santri_gender_kode_keluarga.
        'jenis_kelamin', 'kode_keluarga',
        'saldo_titipan', 'smart_mode', 'smart_ambang', 'smart_isi',
        'golongan', 'jabatan', 'catatan', 'berlaku_sampai',
        // Daur hidup arsip (alumni / lanjut_study / pindah). Wajib ada di sini:
        // update() mengabaikan atribut yang tidak fillable TANPA BERSUARA, jadi
        // kolom yang lupa didaftarkan hanya terlihat sebagai nilai yang diam-diam
        // tidak pernah tersimpan.
        'arsip_pada', 'arsip_catatan',
        // NULL = ikut golongan · 0 = dibebaskan · angka = tarif sendiri
        'biaya_admin',
        // PJ iuran kios — ditandai per orang, lihat BidangSiswa::golongan().
        'terima_iuran',
        'created_at',
    ];

    protected $casts = [
        'saldo' => 'int',
        'saldo_titipan' => 'int',
        'smart_ambang' => 'int',
        'smart_isi' => 'int',
        'terima_iuran' => 'bool',
    ];

    /**
     * Kunci login portal wali: NIS + tanggal lahir + 4 digit terakhir HP.
     * NIS memang publik (tercetak di kartu, dipakai sebagai PIN kios bawaan),
     * jadi dua kolom inilah satu-satunya yang membuat kuncinya tidak bisa
     * ditebak teman sekelas.
     */
    public const KUNCI_WALI = ['tgl_lahir', 'hp_ortu'];

    /**
     * Kolom yang TIDAK pernah boleh ikut terkirim ke klien.
     * Kunci portal wali (tgl_lahir, hp_ortu) diketahui teman sekelas, jadi
     * membocorkannya lewat layar admin sama saja membagikan kata sandinya.
     *
     * Dulu daftar ini KOSONG — docblock di atas menjanjikan pagar yang tidak
     * pernah ada. Akibatnya setiap respons yang mengirim model ini apa adanya
     * (topup, setor/tarik titipan, kenaikan kelas, arsip) ikut membawa kunci
     * wali SELURUH santri yang disentuh, padahal izin jalur-jalur itu
     * (`saldo.topup`, `titipan.setor`, …) sama sekali terpisah dari
     * `santri.lihat`. Bendahara yang hanya berhak mengisi saldo jadi bisa
     * memanen kunci portal satu per satu tanpa pernah membuka layar Santri.
     *
     * Sekarang tertutup secara BAWAAN: jalur yang memang berhak membukanya
     * (layar Santri — form edit butuh nilainya, dan gerbangnya `santri.lihat`/
     * `sdm.lihat` yang tepat) menyatakannya eksplisit dengan
     * `makeVisible(Siswa::KUNCI_WALI)`. Jadi jalur baru yang lahir nanti
     * aman tanpa perlu ingat apa-apa.
     */
    protected $hidden = self::KUNCI_WALI;

    public function golonganRow(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Golongan::class, 'golongan', 'kode');
    }

    public function transaksi(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Transaksi::class, 'siswa_id');
    }

    public function pin(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SiswaPin::class, 'siswa_id');
    }

    /** Kartu tamu/VIP lazimnya berlaku sementara. Kosong = tanpa batas waktu. */
    public function kedaluwarsa(): bool
    {
        return $this->berlaku_sampai !== ''
            && $this->berlaku_sampai < now(config('app.timezone'))->format('Y-m-d');
    }
}
