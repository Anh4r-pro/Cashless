<?php

namespace App\Services;

use App\Support\WaktuIso;
use Illuminate\Support\Collection;

/**
 * Form pengajuan perubahan menu, untuk ditinjau pimpinan di luar sistem.
 *
 * Keputusan menyetujui menu sering tidak diambil di depan layar: berkasnya
 * dicetak, dibawa ke rapat, dan dibubuhi tanda tangan. Karena itu yang
 * dihasilkan di sini BUKAN sekadar tabel data, melainkan sebuah formulir —
 * lengkap dengan kop, periode, ringkasan, dan blok tanda tangan. Tanpa itu,
 * berkasnya tidak bisa dipakai sebagai lampiran keputusan.
 *
 * Formatnya tabel HTML, sama seperti Ekspor: Excel dan LibreOffice membukanya
 * sebagai .xls, peramban mencetaknya sebagai PDF. Sekolah tidak perlu memasang
 * apa pun, dan tidak ada pustaka PDF yang harus dirawat.
 */
class FormAjuan
{
    public const SEKOLAH = 'IMBOS Cashless';

    public const JUDUL = ['No', 'Tenant', 'Jenis Usulan', 'Menu Sekarang', 'Harga Sekarang',
        'Usulan Menu', 'Usulan Harga', 'Selisih', 'Diajukan', 'Pengaju', 'Status'];

    /** Nilai enum di basis data: baru | ubah | hapus. */
    private const JENIS = [
        'baru' => 'Menu baru',
        'ubah' => 'Ubah nama/harga',
        'hapus' => 'Hapus menu',
    ];

    private const STATUS = [
        'menunggu' => 'Menunggu',
        'disetujui' => 'Disetujui',
        'ditolak' => 'Ditolak',
        'batal' => 'Dibatalkan',
    ];

    /** Satu baris formulir, waktunya sudah digeser ke WIB. */
    public static function baris(object $a, int $no): array
    {
        $lama = $a->jenis === 'baru' ? '—' : ($a->nama_lama ?: '—');
        $hargaLama = $a->jenis === 'baru' ? null : $a->harga_lama;
        $baru = $a->jenis === 'hapus' ? '—' : ($a->nama ?: '—');
        $hargaBaru = $a->jenis === 'hapus' ? null : $a->harga;

        // Selisih hanya berarti kalau kedua sisinya ada; usulan menu baru dan
        // penghapusan tidak punya pembanding, dan memaksakan angka di situ
        // hanya akan terbaca sebagai kenaikan atau penurunan yang tidak terjadi.
        $selisih = ($hargaLama !== null && $hargaBaru !== null)
            ? self::rp($hargaBaru - $hargaLama, true) : '—';

        return [
            $no,
            $a->tenant_nama ?? '—',
            self::JENIS[$a->jenis] ?? $a->jenis,
            $lama,
            $hargaLama !== null ? self::rp($hargaLama) : '—',
            $baru,
            $hargaBaru !== null ? self::rp($hargaBaru) : '—',
            $selisih,
            self::wib($a->created_at),
            $a->pengaju ?: '—',
            self::STATUS[$a->status] ?? $a->status,
        ];
    }

    public static function csv(Collection $rows): string
    {
        $baris = [implode(',', array_map([self::class, 'kutip'], self::JUDUL))];
        foreach ($rows as $i => $a) {
            $baris[] = implode(',', array_map([self::class, 'kutip'], self::baris($a, $i + 1)));
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $baris);
    }

    /**
     * Formulir lengkap. $meta: judul, periode, penyaring, sekolah.
     *
     * $tandaTangan dimatikan untuk ekspor .xls — blok tanda tangan di dalam
     * lembar kerja hanya menjadi sel-sel kosong yang mengganggu saat disaring
     * atau diurutkan. Yang butuh tanda tangan adalah versi cetaknya.
     */
    public static function html(Collection $rows, array $meta, bool $tandaTangan = true): string
    {
        $head = implode('', array_map(fn ($h) => '<th>' . e($h) . '</th>', self::JUDUL));
        $body = '';
        foreach ($rows as $i => $a) {
            $sel = '';
            foreach (self::baris($a, $i + 1) as $k => $c) {
                // Kolom nominal dan selisih dirata-kanan supaya mudah ditelusuri ke bawah.
                $sel .= in_array($k, [4, 6, 7], true)
                    ? '<td class="num">' . e($c) . '</td>'
                    : '<td>' . e($c) . '</td>';
            }
            $body .= "<tr>{$sel}</tr>\n";
        }
        if ($rows->isEmpty()) {
            $body = '<tr><td colspan="' . count(self::JUDUL) .
                '" style="text-align:center;padding:18px">Tidak ada usulan pada penyaring ini.</td></tr>';
        }

        $ringkas = self::ringkas($rows);
        $sekolah = e($meta['sekolah'] ?? 'IMBOS Cashless');
        $judul = e($meta['judul'] ?? 'Form Pengajuan Perubahan Menu');
        $periode = e($meta['periode'] ?? '—');
        $saring = e($meta['penyaring'] ?? 'Semua usulan');
        $dicetak = self::wib(WaktuIso::sekarang());

        $ttd = $tandaTangan ? self::blokTandaTangan($meta) : '';

        return <<<HTML
        <meta charset="utf-8">
        <div class="kop">
          <h2>{$sekolah}</h2>
          <h3>{$judul}</h3>
        </div>
        <table class="meta">
          <tr><td>Periode usulan</td><td>: {$periode}</td>
              <td>Jumlah usulan</td><td>: {$ringkas['n']}</td></tr>
          <tr><td>Penyaring</td><td>: {$saring}</td>
              <td>Menunggu keputusan</td><td>: {$ringkas['menunggu']}</td></tr>
          <tr><td>Dicetak</td><td>: {$dicetak} WIB</td>
              <td>Sudah diputuskan</td><td>: {$ringkas['diputus']}</td></tr>
        </table>
        <table class="isi">
          <thead><tr>{$head}</tr></thead>
          <tbody>
        {$body}
          </tbody>
        </table>
        {$ttd}
        HTML;
    }

    /**
     * Blok tanda tangan.
     *
     * Tiga kolom, dan urutannya mengikuti alur keputusan yang sebenarnya:
     * yang mengusulkan, yang mengetahui, yang memutuskan. Kolom keputusan
     * sengaja disediakan kosong — formulir ini dibawa ke rapat DALAM keadaan
     * belum diputuskan, dan itulah gunanya.
     */
    private static function blokTandaTangan(array $meta): string
    {
        $tgl = self::wib(WaktuIso::sekarang(), 'd F Y');

        return <<<HTML
        <div class="putusan">
          <b>Keputusan pimpinan:</b>
          <span class="kotak">&nbsp;</span> Disetujui seluruhnya &nbsp;&nbsp;
          <span class="kotak">&nbsp;</span> Disetujui sebagian (beri tanda pada baris) &nbsp;&nbsp;
          <span class="kotak">&nbsp;</span> Ditolak
          <div class="catatan">Catatan:
            <span class="garis"></span><span class="garis"></span></div>
        </div>
        <table class="ttd">
          <tr>
            <td>Diajukan oleh<br><span class="peran">Pengelola Kantin / Tenant</span></td>
            <td>Mengetahui<br><span class="peran">Bagian Sarana / Koordinator</span></td>
            <td>Menyetujui<br><span class="peran">Pimpinan</span></td>
          </tr>
          <tr class="ruang"><td>{$tgl}</td><td>&nbsp;</td><td>&nbsp;</td></tr>
          <tr class="nama"><td>(&nbsp;__________________&nbsp;)</td>
              <td>(&nbsp;__________________&nbsp;)</td>
              <td>(&nbsp;__________________&nbsp;)</td></tr>
        </table>
        HTML;
    }

    public static function ringkas(Collection $rows): array
    {
        return [
            'n' => $rows->count(),
            'menunggu' => $rows->where('status', 'menunggu')->count(),
            'diputus' => $rows->whereIn('status', ['disetujui', 'ditolak'])->count(),
        ];
    }

    public static function gaya(): string
    {
        return <<<'CSS'
        body { font: 12px/1.5 "Segoe UI", system-ui, sans-serif; margin: 24px; color: #111; }
        .kop { text-align: center; border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 14px; }
        .kop h2 { margin: 0; font-size: 17px; letter-spacing: .04em; text-transform: uppercase; }
        .kop h3 { margin: 4px 0 0; font-size: 14px; font-weight: 600; }
        table.meta { border-collapse: collapse; margin-bottom: 12px; font-size: 12px; }
        table.meta td { padding: 1px 6px 1px 0; vertical-align: top; }
        table.isi { border-collapse: collapse; width: 100%; }
        table.isi th, table.isi td { border: 1px solid #888; padding: 5px 7px; font-size: 11px; }
        table.isi th { background: #e8e8e8; text-align: left; }
        table.isi td.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .putusan { margin-top: 16px; font-size: 12px; }
        .kotak { display: inline-block; width: 13px; height: 13px; border: 1px solid #333;
          vertical-align: -2px; margin: 0 3px 0 10px; }
        .catatan { margin-top: 8px; }
        .garis { display: block; border-bottom: 1px dotted #666; height: 17px; }
        table.ttd { width: 100%; margin-top: 22px; text-align: center; border-collapse: collapse; }
        table.ttd td { width: 33.33%; font-size: 12px; padding: 0 6px; }
        table.ttd .peran { font-size: 11px; color: #555; }
        table.ttd tr.ruang td { height: 62px; vertical-align: top; padding-top: 4px; font-size: 11px; }
        table.ttd tr.nama td { white-space: nowrap; }
        @media print { .noprint { display: none } body { margin: 0 } }
        CSS;
    }

    // ------------------------------------------------------------- pembantu

    private static function rp(?int $n, bool $bertanda = false): string
    {
        if ($n === null) {
            return '—';
        }
        $tanda = $bertanda && $n > 0 ? '+' : ($n < 0 ? '-' : '');

        return $tanda . 'Rp' . number_format(abs($n), 0, ',', '.');
    }

    private static function wib(?string $iso, string $format = 'd/m/Y H:i'): string
    {
        if (! $iso) {
            return '—';
        }
        try {
            return (new \DateTime($iso, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format($format);
        } catch (\Throwable) {
            return '—';
        }
    }

    private static function kutip($v): string
    {
        return '"' . str_replace('"', '""', (string) $v) . '"';
    }
}
