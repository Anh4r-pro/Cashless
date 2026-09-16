'use strict';
/**
 * Primitif grafik — SVG murni, tanpa dependensi eksternal.
 * Palet kategorikal tervalidasi (CVD ΔE ≥ 8, normal-vision ΔE ≥ 15 pada
 * permukaan putih). Slot hijau di bawah 3:1 kontras → selalu didampingi label
 * langsung + tabel data (aturan relief).
 */
/**
 * Semua teks yang masuk ke innerHTML di berkas ini WAJIB lewat sini.
 *
 * Label grafik bukan teks tetap: nama menu dan nama tenant diketik sendiri oleh
 * tenant, nama kelas oleh admin. Dulu escaping-nya diserahkan ke pemanggil —
 * dan pemanggilnya sempat tidak konsisten: label batang di-escape, tetapi nama
 * deret pada grafik tren menu tidak, sehingga menu bernama `<img onerror=...>`
 * bisa menjalankan skrip di sesi superadmin yang membuka menu Analitik.
 * Escaping dipindah ke sini, satu-satunya lapisan yang benar-benar menulis
 * innerHTML, supaya pemanggil baru tidak bisa lupa.
 */
const vesc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
  ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const VIZ = {
  s1: '#2a78d6',  // biru   — belanja / deret utama
  s2: '#eb6834',  // oranye — top up
  s3: '#1baf7a',  // hijau  — deret ketiga
  grid: '#e6eaf2', axis: '#9aa3b6', ink: '#1a2340', muted: '#64708c',
};
VIZ.kategori = [VIZ.s1, VIZ.s2, VIZ.s3];

/**
 * Palet untuk grafik banyak deret (tren menu). Tiga warna pertama adalah palet
 * tervalidasi di atas; tiga berikutnya PERLUASAN — dipilih berjauhan dalam
 * rona dan terang, tetapi belum melewati pengujian ΔE yang sama.
 *
 * Karena itu jumlah deretnya dibatasi enam, dan aturan relief tetap berlaku
 * tanpa kecuali: setiap grafik yang memakainya wajib disertai legenda, tooltip
 * bernama, dan tabel angka di bawahnya — sehingga tidak ada satu pun informasi
 * yang hanya bisa dibaca lewat warna.
 */
VIZ.deret = [VIZ.s1, VIZ.s2, VIZ.s3, '#8a5cd6', '#c2410c', '#0f766e'];

// ---------------------------------------------------------------- format
function fmtRpShort(n) {
  const v = Math.abs(Number(n) || 0);
  const sign = n < 0 ? '-' : '';
  if (v >= 1e9) return sign + 'Rp' + (v / 1e9).toFixed(v >= 1e10 ? 0 : 1).replace('.', ',') + 'M';
  if (v >= 1e6) return sign + 'Rp' + (v / 1e6).toFixed(v >= 1e7 ? 0 : 1).replace('.', ',') + 'jt';
  if (v >= 1e3) return sign + 'Rp' + Math.round(v / 1e3) + 'rb';
  return sign + 'Rp' + v;
}
const fmtRpFull = (n) => 'Rp' + Number(n || 0).toLocaleString('id-ID');
const fmtNum = (n) => Number(n || 0).toLocaleString('id-ID');

// Skala sumbu-Y: pilih langkah "bulat" terkecil yang muat dalam target tick,
// lalu bulatkan maksimum ke kelipatannya — supaya batang/garis mengisi tinggi
// plot, bukan berhenti di separuh.
function niceScale(dataMax, ticks) {
  if (!(dataMax > 0)) return { max: 1, step: 1 };
  const mag = Math.pow(10, Math.floor(Math.log10(dataMax / ticks)));
  for (const m of [1, 1.5, 2, 2.5, 3, 4, 5, 7.5, 10, 15, 20]) {
    const step = m * mag;
    if (Math.ceil(dataMax / step) <= ticks) return { max: Math.ceil(dataMax / step) * step, step };
  }
  return { max: dataMax, step: dataMax / ticks };
}

// ---------------------------------------------------------------- tooltip
function vizTip() {
  let t = document.getElementById('viz-tip');
  if (!t) {
    t = document.createElement('div');
    t.id = 'viz-tip';
    document.body.appendChild(t);
  }
  return t;
}
function showTip(html, ev) {
  const t = vizTip();
  t.innerHTML = html;
  t.classList.add('show');
  const r = t.getBoundingClientRect();
  let x = ev.clientX + 14, y = ev.clientY - r.height - 10;
  if (x + r.width > innerWidth - 8) x = ev.clientX - r.width - 14;
  if (y < 8) y = ev.clientY + 18;
  t.style.left = x + 'px';
  t.style.top = y + 'px';
}
function hideTip() { vizTip().classList.remove('show'); }

// ---------------------------------------------------------------- helpers
const NS = 'http://www.w3.org/2000/svg';
function el(tag, attrs, text) {
  const n = document.createElementNS(NS, tag);
  for (const [k, v] of Object.entries(attrs || {})) n.setAttribute(k, v);
  if (text != null) n.textContent = text;
  return n;
}
// Batang dengan ujung data membulat 4px, menempel pada garis dasar
function topRoundedPath(x, y, w, h, r) {
  r = Math.min(r, w / 2, Math.max(h, 0));
  if (h <= 0.5) return `M${x} ${y + h} h${w}`;
  return `M${x} ${y + h} V${y + r} a${r} ${r} 0 0 1 ${r} ${-r} h${w - 2 * r} a${r} ${r} 0 0 1 ${r} ${r} V${y + h} Z`;
}

function drawGrid(svg, W, H, pad, sc, fmt) {
  const { max, step } = sc;
  for (let v = 0; v <= max + step / 2; v += step) {
    const i = Math.round(v / step);
    const y = H - pad.b - (v / max) * (H - pad.t - pad.b);
    svg.appendChild(el('line', {
      x1: pad.l, x2: W - pad.r, y1: y, y2: y,
      stroke: i === 0 ? VIZ.axis : VIZ.grid, 'stroke-width': 1,
    }));
    svg.appendChild(el('text', {
      x: pad.l - 8, y: y + 4, 'text-anchor': 'end',
      'font-size': 11, fill: VIZ.muted,
    }, fmt(v)));
  }
}

function attachResize(node, redraw) {
  node._redraw = redraw;
  if (!attachResize._bound) {
    attachResize._bound = true;
    let t;
    addEventListener('resize', () => {
      clearTimeout(t);
      t = setTimeout(() => {
        document.querySelectorAll('.viz').forEach((n) => n._redraw && n._redraw());
      }, 180);
    });
  }
}

// ================================================================ LINE
/**
 * opts: { data: [{label, tooltipLabel}], series: [{nama, warna, nilai:[…]}],
 *         tinggi, fmtY, fmtTip }
 */
function vizLine(node, opts) {
  const draw = () => {
    node.innerHTML = '';
    node.classList.add('viz');
    const W = Math.max(320, node.clientWidth);
    const H = opts.tinggi || 260;
    const pad = { l: 62, r: 16, t: 12, b: 30 };
    const fmtY = opts.fmtY || fmtRpShort;
    const n = opts.data.length;

    // legenda — wajib untuk ≥2 deret; identitas tidak pernah lewat warna saja
    if (opts.series.length > 1) {
      const lg = document.createElement('div');
      lg.className = 'viz-legend';
      lg.innerHTML = opts.series.map((s) =>
        `<span><i style="background:${s.warna}"></i>${vesc(s.nama)}</span>`).join('');
      node.appendChild(lg);
    }

    const svg = el('svg', { width: '100%', height: H, viewBox: `0 0 ${W} ${H}`, role: 'img' });
    if (!n) {
      svg.appendChild(el('text', { x: W / 2, y: H / 2, 'text-anchor': 'middle', 'font-size': 13, fill: VIZ.muted },
        'Tidak ada data pada rentang ini'));
      node.appendChild(svg);
      return;
    }

    const sc = niceScale(Math.max(1, ...opts.series.flatMap((s) => s.nilai)), 4);
    const plotW = W - pad.l - pad.r, plotH = H - pad.t - pad.b;
    const xAt = (i) => pad.l + (n === 1 ? plotW / 2 : (i / (n - 1)) * plotW);
    const yAt = (v) => H - pad.b - (v / sc.max) * plotH;

    drawGrid(svg, W, H, pad, sc, fmtY);

    // label sumbu-x diselang agar tidak bertabrakan
    const step = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(plotW / 62))));
    opts.data.forEach((d, i) => {
      if (i % step && i !== n - 1) return;
      svg.appendChild(el('text', {
        x: xAt(i), y: H - pad.b + 16, 'text-anchor': 'middle', 'font-size': 11, fill: VIZ.muted,
      }, d.label));
    });

    // area lembut hanya bila satu deret
    if (opts.series.length === 1) {
      const s = opts.series[0];
      const d = s.nilai.map((v, i) => `${i ? 'L' : 'M'}${xAt(i)} ${yAt(v)}`).join(' ');
      svg.appendChild(el('path', {
        d: `${d} L${xAt(n - 1)} ${H - pad.b} L${xAt(0)} ${H - pad.b} Z`,
        fill: s.warna, opacity: 0.12,
      }));
    }
    for (const s of opts.series) {
      svg.appendChild(el('path', {
        d: s.nilai.map((v, i) => `${i ? 'L' : 'M'}${xAt(i)} ${yAt(v)}`).join(' '),
        fill: 'none', stroke: s.warna, 'stroke-width': 2,
        'stroke-linejoin': 'round', 'stroke-linecap': 'round',
      }));
      if (n === 1) svg.appendChild(el('circle', { cx: xAt(0), cy: yAt(s.nilai[0]), r: 4, fill: s.warna }));
    }

    // lapisan hover: crosshair + penanda + tooltip
    const cross = el('line', { y1: pad.t, y2: H - pad.b, stroke: VIZ.axis, 'stroke-width': 1, 'stroke-dasharray': '3 3', opacity: 0 });
    svg.appendChild(cross);
    const dots = opts.series.map((s) => {
      const c = el('circle', { r: 5, fill: s.warna, stroke: '#fff', 'stroke-width': 2, opacity: 0 });
      svg.appendChild(c);
      return c;
    });
    const hit = el('rect', { x: pad.l, y: pad.t, width: plotW, height: plotH, fill: 'transparent' });
    svg.appendChild(hit);
    hit.addEventListener('mousemove', (ev) => {
      const box = svg.getBoundingClientRect();
      const px = (ev.clientX - box.left) * (W / box.width);
      const i = Math.max(0, Math.min(n - 1, Math.round(((px - pad.l) / plotW) * (n - 1))));
      cross.setAttribute('x1', xAt(i)); cross.setAttribute('x2', xAt(i)); cross.setAttribute('opacity', 1);
      opts.series.forEach((s, k) => {
        dots[k].setAttribute('cx', xAt(i)); dots[k].setAttribute('cy', yAt(s.nilai[i]));
        dots[k].setAttribute('opacity', 1);
      });
      const ft = opts.fmtTip || fmtRpFull;
      showTip(`<b>${vesc(opts.data[i].tooltipLabel || opts.data[i].label)}</b>` +
        opts.series.map((s) => `<span class="tl"><i style="background:${s.warna}"></i>${vesc(s.nama)}<b>${ft(s.nilai[i])}</b></span>`).join(''), ev);
    });
    hit.addEventListener('mouseleave', () => {
      cross.setAttribute('opacity', 0);
      dots.forEach((d) => d.setAttribute('opacity', 0));
      hideTip();
    });

    node.appendChild(svg);
  };
  attachResize(node, draw);
  draw();
}

// ================================================================ BAR
/** opts: { data: [{label, nilai, tooltipLabel, tooltipExtra}], warna, tinggi, fmtY, fmtTip } */
function vizBar(node, opts) {
  const draw = () => {
    node.innerHTML = '';
    node.classList.add('viz');
    const W = Math.max(320, node.clientWidth);
    const H = opts.tinggi || 220;
    const pad = { l: 62, r: 16, t: 12, b: 30 };
    const fmtY = opts.fmtY || fmtNum;
    const n = opts.data.length;
    const svg = el('svg', { width: '100%', height: H, viewBox: `0 0 ${W} ${H}`, role: 'img' });
    if (!n) {
      svg.appendChild(el('text', { x: W / 2, y: H / 2, 'text-anchor': 'middle', 'font-size': 13, fill: VIZ.muted },
        'Tidak ada data pada rentang ini'));
      node.appendChild(svg);
      return;
    }
    const sc = niceScale(Math.max(1, ...opts.data.map((d) => d.nilai)), 4);
    const plotW = W - pad.l - pad.r, plotH = H - pad.t - pad.b;
    drawGrid(svg, W, H, pad, sc, fmtY);

    const slot = plotW / n;
    const bw = Math.max(2, Math.min(38, slot - 2));   // jarak 2px antar batang
    const warna = opts.warna || VIZ.s1;
    const step = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(plotW / (opts.minLabelPx || 46)))));

    opts.data.forEach((d, i) => {
      const x = pad.l + slot * i + (slot - bw) / 2;
      const h = (d.nilai / sc.max) * plotH;
      const y = H - pad.b - h;
      svg.appendChild(el('path', { d: topRoundedPath(x, y, bw, h, 4), fill: warna }));
      if (i % step === 0 || i === n - 1) {
        svg.appendChild(el('text', {
          x: x + bw / 2, y: H - pad.b + 16, 'text-anchor': 'middle', 'font-size': 11, fill: VIZ.muted,
        }, d.label));
      }
      const hit = el('rect', { x: pad.l + slot * i, y: pad.t, width: slot, height: plotH, fill: 'transparent' });
      hit.addEventListener('mousemove', (ev) => showTip(
        `<b>${vesc(d.tooltipLabel || d.label)}</b><span class="tl"><i style="background:${warna}"></i>${vesc(opts.namaDeret || 'Nilai')}<b>${(opts.fmtTip || fmtNum)(d.nilai)}</b></span>` +
        (d.tooltipExtra ? `<span class="tl tl-extra">${vesc(d.tooltipExtra)}</span>` : ''), ev));
      hit.addEventListener('mouseleave', hideTip);
      svg.appendChild(hit);
    });
    node.appendChild(svg);
  };
  attachResize(node, draw);
  draw();
}

// ================================================================ HBAR
/**
 * Batang horizontal dengan label langsung — dipakai untuk perbandingan
 * kategori (tenant, kelas). Label nilai selalu tampil → memenuhi aturan relief.
 * opts: { data: [{label, nilai, sub}], warnaPer, fmtNilai }
 */
function vizHBar(node, opts) {
  const draw = () => {
    node.innerHTML = '';
    node.classList.add('viz');
    const max = Math.max(1, ...opts.data.map((d) => d.nilai));
    const fmt = opts.fmtNilai || fmtRpFull;
    node.innerHTML = opts.data.map((d, i) => {
      const w = (d.nilai / max) * 100;
      const c = opts.warnaPer ? VIZ.kategori[i % VIZ.kategori.length] : (opts.warna || VIZ.s1);
      return `<div class="hbar-row">
        <div class="hbar-head"><span>${vesc(d.label)}</span><b>${fmt(d.nilai)}</b></div>
        <div class="hbar-track"><div class="hbar-fill" style="width:${w}%; background:${c}"></div></div>
        ${d.sub ? `<div class="hbar-sub muted">${vesc(d.sub)}</div>` : ''}
      </div>`;
    }).join('') || '<p class="muted">Tidak ada data pada rentang ini</p>';
  };
  attachResize(node, draw);
  draw();
}
