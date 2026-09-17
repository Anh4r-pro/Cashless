<?php

namespace App\Http\Controllers\Api;

use App\Services\Ekspor;
use App\Services\SaringTransaksi;
use Illuminate\Http\Request;

/** Riwayat transaksi, ekspor berkas, dan tampilan siap cetak. */
class TransaksiController extends Controller
{
    public function index(Request $r)
    {
        $u = $this->wajibPeran($r, 'tenant', 'superadmin');
        $this->izinJikaAdmin($r, $u, 'riwayat.lihat');

        return response()->json(SaringTransaksi::ambil($u, $r->query()));
    }

    public function ekspor(Request $r)
    {
        $u = $this->wajibPeran($r, 'tenant', 'superadmin');
        $this->izinJikaAdmin($r, $u, 'riwayat.ekspor');
        $data = SaringTransaksi::ambil($u, $r->query(), 20000);
        $meta = $this->meta($u, $r, $data);
        $stamp = date('Y-m-d');
        $format = (string) $r->query('format', 'xls');

        if ($format === 'csv') {
            return response(Ekspor::csv($data['rows']), 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"riwayat-{$stamp}.csv\"",
            ]);
        }

        $html = Ekspor::tabelHtml($data['rows'], $meta);

        if ($format === 'doc') {
            return response($html, 200, [
                'Content-Type' => 'application/msword',
                'Content-Disposition' => "attachment; filename=\"riwayat-{$stamp}.doc\"",
            ]);
        }

        // Bawaan: xls (tabel HTML — dibuka Excel/LibreOffice/Google Sheets).
        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-excel',
            'Content-Disposition' => "attachment; filename=\"riwayat-{$stamp}.xls\"",
        ]);
    }

    public function cetak(Request $r)
    {
        $u = $this->wajibPeran($r, 'tenant', 'superadmin');
        $this->izinJikaAdmin($r, $u, 'riwayat.ekspor');
        $data = SaringTransaksi::ambil($u, $r->query(), 20000);
        $tabel = Ekspor::tabelHtml($data['rows'], $this->meta($u, $r, $data));

        $html = <<<HTML
        <!DOCTYPE html><html><head><meta charset="utf-8"><title>Riwayat Transaksi</title>
        <style>body{font:12px/1.5 system-ui,sans-serif;margin:24px} table{border-collapse:collapse;width:100%}
        th,td{border:1px solid #999;padding:4px 6px;font-size:11px} th{background:#eee}
        @media print {.noprint{display:none}}</style></head><body>
        <button class="noprint" onclick="window.print()" style="padding:8px 16px;margin-bottom:12px">🖨 Cetak / Simpan PDF</button>
        {$tabel}
        <script>window.addEventListener('load',()=>setTimeout(()=>window.print(),400))</script>
        </body></html>
        HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function meta($u, Request $r, array $data): array
    {
        $dari = $r->query('dari');
        $sampai = $r->query('sampai');

        return [
            'judul' => $u->role === 'tenant' ? ($u->tenant?->nama ?? '') : 'Semua Tenant',
            'periode' => ($dari ? substr((string) $dari, 0, 10) : 'awal') . ' s/d ' .
                         ($sampai ? substr((string) $sampai, 0, 10) : 'sekarang'),
            'total_masuk' => $data['total_masuk'],
            'total_belanja' => $data['total_belanja'],
        ];
    }
}
