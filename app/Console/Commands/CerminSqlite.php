<?php

namespace App\Console\Commands;

use App\Models\Siswa;
use App\Models\Transaksi;
use Illuminate\Console\Command;

/**
 * Cermin SQLite untuk HARNESS PENGUJIAN, bukan untuk aplikasi.
 *
 * Tiga suite uji warisan (test-ortu, test-topup-ortu, test-riwayat-kios)
 * mencari kredensial contoh dengan membaca berkas kantin.db secara langsung,
 * bukan lewat API. Perintah ini menuliskan salinan seperlunya dari MySQL supaya
 * suite itu bisa dijalankan APA ADANYA terhadap Laravel — berkas ini tidak
 * pernah dibaca aplikasi, dan tidak ikut dipasang di produksi.
 */
class CerminSqlite extends Command
{
    protected $signature = 'kantin:cermin-sqlite {tujuan : Path berkas SQLite yang akan ditulis}';

    protected $description = 'Tulis cermin SQLite (santri + transaksi) untuk harness suite uji warisan';

    public function handle(): int
    {
        $tujuan = $this->argument('tujuan');
        @unlink($tujuan);

        // Nama tabel di cermin ini sengaja tetap `siswa`, bukan `santri`:
        // yang membacanya adalah suite uji warisan dari repo Node, dan
        // kuerinya menyebut nama tabel itu apa adanya.
        $db = new \PDO('sqlite:' . $tujuan);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE siswa (
            id INTEGER PRIMARY KEY, nis TEXT, nama TEXT, kelas TEXT, card_uid TEXT,
            saldo INTEGER, saldo_titipan INTEGER, status TEXT, nisn TEXT,
            tgl_lahir TEXT, hp_ortu TEXT, golongan TEXT)');
        // Suite riwayat-kios ikut MENGHAPUS baris di sini sebagai pembersihan;
        // tabelnya disediakan supaya perintahnya tidak menggagalkan suite.
        $db->exec('CREATE TABLE siswa_pin (
            siswa_id INTEGER PRIMARY KEY, pin_hash TEXT, updated_at TEXT)');
        $db->exec('CREATE TABLE transaksi (
            id INTEGER PRIMARY KEY, tipe TEXT, siswa_id INTEGER, jumlah INTEGER,
            keterangan TEXT, created_at TEXT)');

        $insS = $db->prepare('INSERT INTO siswa VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach (Siswa::cursor() as $s) {
            $insS->execute([$s->id, $s->nis, $s->nama, $s->kelas, $s->card_uid, $s->saldo,
                $s->saldo_titipan, $s->status, $s->nisn, $s->tgl_lahir, $s->hp_ortu, $s->golongan]);
        }

        $insT = $db->prepare('INSERT INTO transaksi VALUES (?,?,?,?,?,?)');
        foreach (Transaksi::cursor() as $t) {
            $insT->execute([$t->id, $t->tipe, $t->siswa_id, $t->jumlah, $t->keterangan, $t->created_at]);
        }

        $this->info("Cermin ditulis: {$tujuan}");

        return self::SUCCESS;
    }
}
