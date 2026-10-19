<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\MartLaporanController;
use App\Models\Tenant;
use App\Services\Kartu;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Kirim ringkasan SPD ke WhatsApp untuk tiap unit Mart yang menyalakan
 * "kirim otomatis" dan jamnya cocok dengan sekarang.
 *
 * Dijadwalkan `->everyMinute()` di bootstrap/app.php — tiap menit cuma
 * MENGECEK jam, bukan mengirim tiap menit. `wa_terakhir_kirim` adalah
 * penjaga supaya satu tenant tidak terkirim dua kali di menit yang sama
 * (atau kalau scheduler sempat jalan dobel).
 */
class KirimSpdWa extends Command
{
    protected $signature = 'mart:kirim-spd-wa';

    protected $description = 'Kirim laporan SPD harian ke WhatsApp untuk unit Mart yang jadwalnya cocok sekarang';

    public function handle(MartLaporanController $laporan): int
    {
        $hariIni = Kartu::hariIni();
        $jamSekarang = (new \DateTime('now', new \DateTimeZone(config('app.timezone', 'Asia/Jakarta'))))->format('H:i');

        $tenants = Tenant::where('mode_kasir', 'mart')
            ->where('wa_kirim_otomatis', true)
            ->whereNotNull('wa_nomor')
            ->get();

        foreach ($tenants as $t) {
            if ($t->wa_terakhir_kirim === $hariIni) {
                continue;   // sudah terkirim hari ini
            }
            if ($t->wa_jam_kirim !== $jamSekarang) {
                continue;   // belum jamnya
            }

            if ($laporan->kirimWaUntukTenant($t)) {
                $t->update(['wa_terakhir_kirim' => $hariIni]);
                $this->info("✔ SPD terkirim ke WA — {$t->nama} ({$t->wa_nomor})");
            } else {
                // TIDAK ditandai terkirim — biar dicoba lagi besok lewat
                // tombol manual, atau kalau jadwalnya pas lagi jam yang sama.
                Log::warning("KirimSpdWa: gagal kirim untuk tenant #{$t->id} ({$t->nama})");
                $this->warn("✘ Gagal kirim — {$t->nama} ({$t->wa_nomor})");
            }
        }

        return self::SUCCESS;
    }
}
