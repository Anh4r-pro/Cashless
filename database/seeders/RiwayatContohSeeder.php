<?php

namespace Database\Seeders;

use App\Models\Produk;
use App\Models\Siswa;
use App\Models\Tenant;
use App\Models\Transaksi;
use App\Models\TransaksiItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Riwayat jajan CONTOH untuk demo dan pengujian.
 *
 * Sengaja TERPISAH dari DatabaseSeeder dan tidak ikut `db:seed` biasa:
 * pemasangan sungguhan tidak boleh kejatuhan transaksi karangan. Jalankan
 * dengan sadar bila memang perlu:
 *
 *     php artisan db:seed --class=RiwayatContohSeeder
 *
 * Saldo siswa DISESUAIKAN mengikuti transaksi yang dibuat, jadi neraca kas
 * tetap seimbang dan panel pemeriksaan keutuhan tidak melaporkan selisih palsu.
 */
class RiwayatContohSeeder extends Seeder
{
    private const HARI = 30;

    /**
     * Layar analitik punya pilihan rentang cepat "Juli 2026" sebagai bawaan —
     * peninggalan data contoh terdahulu. Riwayat di bulan itu ikut dibuat supaya
     * layar analitik tidak tampil kosong saat pertama dibuka.
     */
    private const BULAN_ARSIP = '2026-07';

    public function run(): void
    {
        $siswa = Siswa::where('golongan', 'siswa')->where('status', 'aktif')->get();
        $tenants = Tenant::where('status', 'aktif')->get();
        if ($siswa->isEmpty() || $tenants->isEmpty()) {
            $this->command?->warn('Belum ada siswa/tenant — jalankan ContohSeeder dulu.');

            return;
        }

        // Deterministik: data demo yang bisa dibuat ulang persis sama jauh lebih
        // mudah dipakai untuk menelusuri laporan yang angkanya terlihat aneh.
        mt_srand(20260905);

        DB::transaction(function () use ($siswa, $tenants) {
            foreach ($siswa as $s) {
                $saldo = (int) $s->saldo;

                // Seluruh kejadian dikumpulkan dan DIURUTKAN dulu, baru saldo
                // berjalannya dihitung. Kalau transaksi dibuat dengan waktu acak
                // sambil mengurangi saldo, kolom saldo_sesudah tidak akan runut
                // secara kronologis — dan pemeriksa keutuhan neraca
                // (drift_saldo) memang dirancang menangkap persis keadaan itu.
                $jadwal = [];
                foreach ([self::BULAN_ARSIP, null] as $blok) {
                    $jadwal[] = ['isi', $this->waktuTopup($blok), 100000 + mt_rand(0, 6) * 25000];
                    $n = 8 + mt_rand(0, 12);
                    for ($i = 0; $i < $n; $i++) {
                        $jadwal[] = ['beli', $this->waktuAcak($blok), 0];
                    }
                }
                usort($jadwal, fn ($a, $b) => $a[1] <=> $b[1]);

                foreach ($jadwal as [$jenis, $waktu, $nominal]) {
                    if ($jenis === 'isi') {
                        $saldo += $nominal;
                        $this->topup($s, $nominal, $saldo, $waktu);

                        continue;
                    }
                    $t = $tenants[mt_rand(0, $tenants->count() - 1)];
                    $menu = Produk::where('tenant_id', $t->id)->where('kategori', 'makanan')
                        ->inRandomOrder()->first();
                    if (! $menu) {
                        continue;
                    }
                    $qty = mt_rand(1, 2);
                    $total = $menu->harga * $qty;
                    if ($saldo < $total) {
                        continue;
                    }
                    $saldo -= $total;
                    $this->beli($s, $t, $menu, $qty, $total, $saldo, $waktu);
                }

                $s->update(['saldo' => $saldo]);
            }
        });

        $this->command?->info('Riwayat contoh dibuat untuk ' . $siswa->count() . ' siswa.');
    }

    private function topup(Siswa $s, int $jumlah, int $saldo, string $waktu): void
    {
        Transaksi::create([
            'tipe' => 'topup', 'siswa_id' => $s->id, 'siswa_nama' => $s->nama, 'kelas' => $s->kelas,
            'jumlah' => $jumlah, 'saldo_sesudah' => $saldo, 'operator' => 'Super Admin',
            'keterangan' => 'Top up (data contoh)', 'created_at' => $waktu,
        ]);
    }

    private function beli(Siswa $s, Tenant $t, Produk $menu, int $qty, int $total, int $saldo, string $waktu): void
    {
        $ket = $qty > 1 ? "{$menu->nama} ×{$qty}" : $menu->nama;
        $trx = Transaksi::create([
            'tipe' => 'beli', 'siswa_id' => $s->id, 'siswa_nama' => $s->nama, 'kelas' => $s->kelas,
            'tenant_id' => $t->id, 'tenant_nama' => $t->nama,
            'jumlah' => -$total, 'saldo_sesudah' => $saldo,
            'operator' => $t->pemilik, 'keterangan' => $ket, 'created_at' => $waktu,
        ]);
        TransaksiItem::create([
            'transaksi_id' => $trx->id, 'produk_id' => $menu->id,
            'tenant_id' => $t->id, 'tenant_nama' => $t->nama,
            'nama' => $menu->nama, 'kategori' => $menu->kategori,
            'harga' => $menu->harga, 'qty' => $qty, 'subtotal' => $total,
            'siswa_id' => $s->id, 'kelas' => $s->kelas, 'golongan' => $s->golongan,
            'created_at' => $waktu,
        ]);
    }

    /**
     * Waktu acak pada jam istirahat sekolah.
     * $bulan null = 30 hari terakhir; 'YYYY-MM' = di dalam bulan itu.
     */
    private function waktuAcak(?string $bulan = null): string
    {
        $wib = new \DateTimeZone('Asia/Jakarta');
        if ($bulan) {
            $awal = new \DateTime($bulan . '-01', $wib);
            $d = (clone $awal)->modify('+' . mt_rand(0, (int) $awal->format('t') - 1) . ' days');
        } else {
            $d = new \DateTime('-' . mt_rand(0, self::HARI - 3) . ' days', $wib);
        }
        $d->setTime(mt_rand(9, 14), mt_rand(0, 59), mt_rand(0, 59));
        $d->setTimezone(new \DateTimeZone('UTC'));

        return $d->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Top up selalu jatuh di awal bloknya, sebelum belanja blok itu terjadi. */
    private function waktuTopup(?string $bulan): string
    {
        $wib = new \DateTimeZone('Asia/Jakarta');
        $d = $bulan
            ? new \DateTime($bulan . '-01', $wib)
            : new \DateTime('-' . (self::HARI - 2) . ' days', $wib);
        $d->setTime(7, mt_rand(0, 59), 0);
        $d->setTimezone(new \DateTimeZone('UTC'));

        return $d->format('Y-m-d\TH:i:s.v\Z');
    }
}
