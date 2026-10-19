<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Jembatan ke gateway WhatsApp milik kantin sendiri (bukan API pihak ketiga
 * berbayar) — kode servernya di `/var/www/kantin-wa-gateway`, hidup di VPS
 * yang sama lewat loopback, tidak pernah lewat internet publik.
 *
 * URL & token-nya hidup di .env (lihat config/services.php), bukan
 * dikonfigurasi admin lewat UI — ini kredensial infrastruktur milik app
 * sendiri, satu untuk semua tenant Mart, bukan per-admin seperti kredensial
 * gateway pembayaran.
 */
class WaGateway
{
    public function kirim(string $nomor, string $teks): bool
    {
        $url = config('services.wa_gateway.url');
        $token = config('services.wa_gateway.token');
        if (! $url || ! $token) {
            Log::warning('WaGateway: belum dikonfigurasi (WA_GATEWAY_URL/WA_GATEWAY_TOKEN kosong di .env)');

            return false;
        }

        try {
            $resp = Http::timeout(10)->withToken($token)
                ->post(rtrim($url, '/') . '/send', ['number' => $nomor, 'message' => $teks]);

            if (! $resp->successful()) {
                Log::warning('WaGateway: gagal kirim', ['status' => $resp->status(), 'body' => $resp->body()]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('WaGateway: galat saat memanggil gateway', ['pesan' => $e->getMessage()]);

            return false;
        }
    }
}
