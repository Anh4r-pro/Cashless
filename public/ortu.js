'use strict';
/**
 * SIMCASHTREN — portal di /ortu untuk wali santri DAN pegawai (halaman baca-saja).
 * Masuk dengan NIS/NIY + tanggal lahir (ddmmyy), plus 4 digit terakhir nomor HP
 * bila sekolah masih memintanya
 * bila sekolah masih memintanya (pengaturan `ortu_minta_hp` di panel admin).
 * Tidak memakai sesi staf: cookie sendiri (osid) dan hanya route /api/ortu/*.
 */

const $ = (s) => document.querySelector(s);
const rp = (n) => 'Rp' + Number(n || 0).toLocaleString('id-ID');
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const digit = (s) => String(s || '').replace(/\D/g, '');

/** Progres menuju target tabungan — kosong untuk jenis tanpa target (bebas). */
function tabunganProgresHtml(saldo, target) {
  if (!target) return '';
  const pct = Math.min(100, Math.floor((saldo / target) * 100));
  return `<div class="hbar-row" style="margin:4px 0 0">
    <div class="hbar-head" style="font-size:11.5px"><span class="muted">Target ${rp(target)}</span>
      <b style="color:var(--ok)">${pct}%</b></div>
    <div class="hbar-track" style="height:6px"><div class="hbar-fill" style="width:${pct}%; background:var(--ok)"></div></div>
  </div>`;
}

const state = { hari: 30, tipe: '', page: 1 };

// Palet grafik disetel ke warna portal (navy & emas redam). VIZ milik
// charts.js dipakai bersama layar admin, jadi hanya DIUBAH DI HALAMAN INI —
// berkas ini tidak pernah dimuat di layar admin.
Object.assign(VIZ, { s1: '#1A2856', s2: '#C9A227', s3: '#0F7A5F',
  grid: '#F0EDE5', axis: '#D9D3C3', ink: '#141B2D', muted: '#6E7489' });
VIZ.kategori = ['#1A2856', '#C9A227', '#0F7A5F', '#5B45B8', '#B4233F', '#26366B'];

/** Ikon garis kecil untuk daftar transaksi — satu jenis, satu bentuk. */
const IKON = {
  beli: '<path d="M4 9.5 5.5 4h13L20 9.5"/><path d="M4 9.5a2.7 2.7 0 0 0 5.3 0 2.7 2.7 0 0 0 5.4 0 2.7 2.7 0 0 0 5.3 0"/><path d="M5 12v8h14v-8"/>',
  masuk: '<path d="M12 5v14M5 12h14"/>',
  keluar: '<path d="M5 12h14"/>',
  donasi: '<path d="M12 20s-7.5-4.5-7.5-10A4.3 4.3 0 0 1 12 7.3 4.3 4.3 0 0 1 19.5 10c0 5.5-7.5 10-7.5 10z"/>',
  kirim: '<path d="M21 3 10 14M21 3l-7 18-4-7-7-4z"/>',
  admin: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
  // Sama persis dengan app.js — lihat komentarnya di sana.
  // Tangan + koin Rp dari rujukan pemilik sistem, divektorkan apa adanya.
  // svg bersarang (viewBox & fill sendiri) menembus fill:none milik svg pembungkus
  // — bukan rescaling tangan yang rawan keliru. Template literal (bukan string
  // kutip biasa) karena data jalurnya memuat baris baru apa adanya dari potrace.
  tabungan: `<svg viewBox="0 0 632 618" fill="currentColor"><g transform="translate(0,618) scale(0.1,-0.1)"><path d="M3805 5249 c-483 -65 -893 -412 -1039 -878 -80 -254 -73 -572 18
-814 137 -363 422 -643 776 -761 58 -19 145 -42 194 -51 114 -19 318 -19 432
0 440 77 815 396 968 823 84 235 93 521 24 767 -126 454 -491 796 -953 896
-106 23 -319 32 -420 18z m330 -233 c365 -66 664 -309 795 -647 52 -135 64
-209 64 -379 0 -130 -3 -169 -23 -244 -48 -186 -138 -349 -264 -478 -391 -400
-1025 -420 -1433 -44 -244 224 -363 533 -333 866 40 459 390 842 846 924 103
19 249 19 348 2z M3250 4100 l0 -450 80 0 80 0 0 185 0 185 113 0 114 0 53
-142 c29 -79 61 -162 71 -185 l18 -43 81 0 c44 0 80 4 80 9 0 8 -112 304 -139
367 -8 19 -3 26 39 52 30 19 58 48 76 78 26 44 29 58 29 135 0 78 -3 90 -30
135 -67 107 -125 124 -432 124 l-233 0 0 -450z m455 306 c50 -21 77 -69 72
-129 -8 -97 -81 -131 -269 -125 l-93 3 -3 133 -3 132 131 0 c91 0 141 -4 165
-14z M4355 4316 c-16 -7 -49 -33 -72 -57 l-43 -43 0 52 0 52 -70 0 -70 0 0
-445 0 -445 75 0 75 0 0 154 0 154 38 -38 c78 -78 208 -80 291 -4 109 98 138
348 59 504 -46 90 -105 130 -192 130 -33 -1 -74 -7 -91 -14z m117 -145 c35
-39 51 -98 51 -186 0 -134 -41 -211 -118 -221 -31 -5 -48 -1 -74 18 -54 36
-74 79 -79 169 -5 88 9 158 40 202 45 63 131 72 180 18z M478 2884 c-60 -32
-58 5 -58 -909 l0 -835 34 -38 34 -37 367 -3 367 -3 39 41 c33 34 39 48 39 83
0 23 3 58 6 77 l6 36 76 13 c295 49 263 51 522 -33 230 -75 1022 -333 1095
-357 194 -62 396 -77 534 -39 104 29 434 155 656 251 330 141 462 210 657 342
317 213 812 559 846 591 51 47 84 120 90 197 11 159 -73 274 -239 326 -67 21
-246 24 -302 4 -21 -7 -154 -69 -295 -138 -371 -181 -566 -274 -576 -276 -4
-1 -14 17 -21 40 -31 103 -113 196 -213 244 -71 35 -120 40 -557 60 -351 15
-391 26 -865 222 -290 120 -458 147 -695 112 -136 -20 -288 -59 -696 -181 -14
-4 -18 7 -23 63 -9 89 -22 119 -61 143 -31 19 -49 20 -386 20 -294 -1 -359 -3
-381 -16z m1949 -273 c100 -25 217 -70 406 -155 300 -135 370 -147 1002 -172
188 -8 214 -14 258 -63 46 -51 55 -97 31 -148 -47 -94 -124 -109 -514 -97
-448 14 -653 -3 -823 -66 -66 -25 -87 -54 -87 -120 0 -33 6 -48 29 -71 39 -39
77 -38 217 4 l109 32 467 -3 c414 -4 473 -2 524 13 69 20 135 52 224 110 36
23 99 56 140 73 41 17 248 114 459 216 210 102 406 193 434 201 105 31 218 14
253 -39 15 -25 15 -29 0 -69 -15 -38 -36 -56 -216 -182 -636 -445 -832 -566
-1150 -703 -208 -90 -618 -254 -670 -268 -83 -22 -209 -29 -295 -15 -44 7
-342 102 -718 227 -353 118 -669 221 -702 229 -97 23 -206 19 -485 -18 -13 -2
-15 54 -15 452 l0 455 305 88 c168 49 339 95 380 103 105 20 327 13 437 -14z
m-1476 -1057 c78 -75 54 -193 -48 -234 -85 -33 -183 40 -183 136 0 39 39 101
74 119 48 24 120 15 157 -21z"/></g></svg>`,
};
const ikon = (k) => `<svg class="i" viewBox="0 0 24 24">${IKON[k] || IKON.masuk}</svg>`;

// Apakah kunci ketiga (4 digit HP) sedang diminta sekolah. Dijawab server lewat
// /api/ortu/me, bukan ditanam di halaman: sekolah bisa mengubahnya kapan saja
// dan wali tidak perlu menunggu halaman ini dipasang ulang.
let MINTA_HP = false;

function pasangKunci(mintaHp) {
  MINTA_HP = !!mintaHp;
  $('#o-hp4-field').classList.toggle('hidden', !MINTA_HP);
  if (!MINTA_HP) $('#o-hp4').value = '';
}

function toast(msg, kind) {
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'show ' + (kind || '');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => { t.className = ''; }, 3000);
}

async function api(path, opts = {}) {
  const res = await fetch(path, {
    method: opts.method || 'GET',
    headers: opts.body ? { 'Content-Type': 'application/json' } : {},
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const e = new Error(data.error || 'Gagal memuat data');
    e.status = res.status;
    throw e;
  }
  return data;
}

/**
 * Gangguan jaringan sesaat tidak boleh melempar orang tua keluar dari halaman —
 * hanya 401 (sesi habis) yang mengembalikan ke form masuk.
 */
function tanganiGagal(e) {
  if (e.status === 401) tampilLogin('Sesi Anda berakhir. Silakan masuk lagi.');
  else toast(e.message, 'err');
}

// tanggal WIB yang enak dibaca orang tua: "Sen, 4 Agu · 09.41"
const HARI = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const BULAN = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
function waktuWib(iso) {
  const d = new Date(new Date(iso).getTime() + 7 * 3600 * 1000);
  return `${HARI[d.getUTCDay()]}, ${d.getUTCDate()} ${BULAN[d.getUTCMonth()]} · ` +
         `${String(d.getUTCHours()).padStart(2, '0')}.${String(d.getUTCMinutes()).padStart(2, '0')}`;
}
function tanggalPendek(ymd) {
  const [y, m, d] = ymd.split('-').map(Number);
  return `${d} ${BULAN[m - 1]}`;
}
function sejakKapan(iso) {
  if (!iso) return 'belum ada transaksi';
  const menit = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
  if (menit < 1) return 'baru saja';
  if (menit < 60) return `${menit} menit lalu`;
  if (menit < 1440) return `${Math.round(menit / 60)} jam lalu`;
  return `${Math.round(menit / 1440)} hari lalu`;
}

// ------------------------------------------------------------------ masuk
// Dua pintu di satu alamat. Peran bukan sekadar tulisan: server ikut
// mempersempit pencarian (santri saja / bukan-santri saja), jadi NIS santri
// dan NIY pegawai tidak bisa saling menabrak.
const PERAN = {
  wali: {
    judul: 'Masuk sebagai Wali Santri', sub: 'Memantau saldo & jajan anak',
    nis: 'NIS putra/putri', nisPh: 'nomor induk santri',
    nisHint: 'Nomor induk yang tercetak di kartu kantin anak',
    lahirPh: 'contoh: 170412', lahirHint: 'Lahir 17 April 2012 → ketik <b>170412</b>',
    hp: '4 digit terakhir nomor HP wali', tombol: 'Lihat Data Anak', kunciNis: 'ortu-nis-terakhir',
  },
  pegawai: {
    judul: 'Masuk sebagai Pegawai/Staf', sub: 'Cek saldo & transaksi sendiri',
    nis: 'NIY / Nomor Pegawai', nisPh: 'nomor induk pegawai',
    nisHint: 'Nomor yang tercetak di kartu kantin Anda',
    lahirPh: 'contoh: 170490', lahirHint: 'Lahir 17 April 1990 → ketik <b>170490</b>',
    hp: '4 digit terakhir nomor HP Anda', tombol: 'Lihat Akun Saya', kunciNis: 'ortu-nis-pegawai',
  },
};
const PERAN_KUNCI = 'ortu-peran-terakhir';
let peranAktif = null;

// NIS/NIY BUKAN kunci rahasia — teman sekelas pun tahu (kunci sebenarnya adalah
// tanggal lahir, kadang + 4 digit HP). Jadi kotaknya aman diingat otomatis di
// HP itu; yang TIDAK pernah diingat adalah tanggal lahir maupun sesi login.
function simpanLokal(k, v) { try { localStorage.setItem(k, v); } catch { /* abaikan */ } }
function bacaLokal(k) { try { return localStorage.getItem(k); } catch { return null; } }

function pilihPeran(kode) {
  const p = PERAN[kode];
  peranAktif = kode;
  $('#o-peran-pilih').classList.add('hidden');
  $('#o-form').classList.remove('hidden');
  $('#o-rk-ikon').innerHTML = document.querySelector(`.o-peran-opsi[data-peran="${kode}"] .ic`).innerHTML;
  $('#o-rk-judul').textContent = p.judul;
  $('#o-rk-sub').textContent = p.sub;
  $('#o-nis-label').textContent = p.nis;
  $('#o-nis').placeholder = p.nisPh;
  $('#o-nis-hint').textContent = p.nisHint;
  $('#o-lahir').placeholder = p.lahirPh;
  $('#o-lahir-hint').innerHTML = p.lahirHint;
  $('#o-hp4-label').textContent = p.hp;
  $('#o-masuk').textContent = p.tombol;
  $('#o-err').classList.add('hidden');
  // Tidak menimpa isian yang sedang diketik — hanya mengisi kalau kotaknya kosong.
  if (!$('#o-nis').value) {
    const terakhir = bacaLokal(p.kunciNis);
    if (terakhir) $('#o-nis').value = terakhir;
  }
  $('#o-nis').focus();
}

function tampilPilihanPeran() {
  peranAktif = null;
  $('#o-form').classList.add('hidden');
  $('#o-peran-pilih').classList.remove('hidden');
  $('#o-nis').value = ''; $('#o-lahir').value = ''; $('#o-hp4').value = '';
  $('#o-err').classList.add('hidden');
}

function tampilLogin(pesan) {
  $('#boot').classList.add('hidden');
  $('#ortu-app').classList.add('hidden');
  $('#ortu-login').classList.remove('hidden');
  // Peran yang dipakai terakhir dibuka langsung; belum pernah → tampilkan pilihan.
  if (!peranAktif) {
    const ingat = bacaLokal(PERAN_KUNCI);
    if (PERAN[ingat]) pilihPeran(ingat); else tampilPilihanPeran();
  }
  if (pesan) tampilError(pesan);
}

/**
 * Keterangan sesi di footer — hanya tampil kalau ADA sesuatu yang perlu
 * dijelaskan. Sesi biasa (2 jam) tidak lagi diumumkan sama sekali; baris ini
 * baru muncul saat "tetap masuk" sedang aktif, supaya wali tahu kenapa ia
 * tidak diminta login lagi.
 */
function gambarFooterSesi(ingat) {
  const wrap = $('#o-footer-sesi-wrap');
  const el = $('#o-footer-sesi');
  if (!wrap || !el) return;
  wrap.classList.toggle('hidden', !ingat);
  if (ingat) el.textContent = 'Tetap masuk di HP ini — otomatis keluar bila tidak dibuka 30 hari, atau setelah 6 bulan.';
}

function tampilError(pesan) {
  const e = $('#o-err');
  e.textContent = pesan;
  e.classList.remove('hidden');
}

async function masuk() {
  const btn = $('#o-masuk');
  const ingat = $('#o-ingat').checked;
  const body = {
    peran: peranAktif,
    nis: digit($('#o-nis').value),
    lahir: digit($('#o-lahir').value),
    hp4: MINTA_HP ? digit($('#o-hp4').value) : '',
    ingat,
  };
  if (!body.nis || !body.lahir || (MINTA_HP && !body.hp4))
    return tampilError('Semua kolom wajib diisi.');
  $('#o-err').classList.add('hidden');
  btn.disabled = true;
  btn.textContent = 'Memeriksa…';
  try {
    await api('/api/ortu/login', { method: 'POST', body });
    // Kegagalan menyimpan (mis. mode penyamaran) tidak boleh dianggap login-nya
    // gagal — wali sudah berhasil masuk, ini cuma kemudahan tambahan.
    simpanLokal(PERAN[peranAktif].kunciNis, body.nis);
    simpanLokal(PERAN_KUNCI, peranAktif);
    $('#o-lahir').value = ''; $('#o-hp4').value = ''; $('#o-ingat').checked = false;
    gambarFooterSesi(ingat);
    await mulaiApp();
  } catch (e) {
    tampilError(e.message);
  } finally {
    btn.disabled = false;
    if (peranAktif) btn.textContent = PERAN[peranAktif].tombol;
  }
}

async function keluar() {
  await api('/api/ortu/logout', { method: 'POST' }).catch(() => {});
  location.reload();
}

// ------------------------------------------------------------------ isi
async function mulaiApp() {
  $('#boot').classList.add('hidden');
  $('#ortu-login').classList.add('hidden');
  $('#ortu-app').classList.remove('hidden');
  /*
   * Empat permintaan ini tidak saling bergantung, jadi dikirim BERSAMAAN.
   * Dulu berurutan: di jaringan seluler tiap permintaan menunggu yang
   * sebelumnya selesai, dan log server mencatat jeda 5, 4, lalu 16 detik
   * sebelum halaman lengkap. Sekarang lamanya = permintaan terlama saja.
   * Masing-masing menangani gagalnya sendiri, jadi satu yang gagal tidak
   * menahan tiga lainnya tampil. Selama menunggu, kerangka (skeleton) di
   * ortu.html yang terlihat — bukan layar kosong.
   */
  await Promise.all([muatRingkasan(), muatTransaksi(), muatPengajuan(), siapkanTombolTopup()]);
}

/**
 * Inisial dulu, foto menyusul kalau benar-benar berhasil dimuat — supaya foto
 * yang hilang/gagal tidak menyisakan kotak abu-abu kosong di layar orang tua.
 */
function pasangAvatar(wadah, s) {
  const ini = String(s.nama || '?').split(/\s+/).slice(0, 2).map((w) => w[0] || '').join('').toUpperCase();
  wadah.innerHTML = `<div class="avatar">${esc(ini)}</div>`;
  if (!s.foto) return;
  const img = new Image();
  img.onload = () => { img.className = 'avatar'; img.alt = ''; wadah.replaceChildren(img); };
  img.src = '/uploads/' + encodeURIComponent(s.foto);
}

async function muatRingkasan() {
  let d;
  try {
    d = await api('/api/ortu/ringkasan?hari=' + state.hari);
  } catch (e) {
    return tanganiGagal(e);
  }
  const s = d.siswa;
  $('#o-hd-nama').textContent = s.nama;
  pasangAvatar($('#o-avatar'), s);
  $('#o-nama').textContent = s.nama;
  $('#o-meta').innerHTML = (s.peran === 'pegawai'
    ? `${esc(s.golongan || 'Pegawai')}${s.kelas ? ' · ' + esc(s.kelas) : ''} · NIY ${esc(s.nis || '—')}`
    : `Kelas ${esc(s.kelas || '—')} · NIS ${esc(s.nis || '—')}`) +
    (s.status === 'blokir' ? ' · <span class="badge blokir">kartu diblokir</span>' : '') +
    (s.punya_kartu ? '' : ' · <span class="muted">kartu belum dihubungkan</span>');
  $('#o-hp').textContent = (s.peran === 'pegawai' ? 'HP terdaftar: ' : 'HP wali terdaftar: ') + s.hp_ortu_samar;
  $('#o-hp').classList.toggle('hidden', !MINTA_HP && s.hp_ortu_samar === '—');
  // Tabungan/donasi/ganti PIN lewat perangkat sendiri hanya untuk pintu
  // pegawai, dan hanya kalau kartunya sudah terhubung — tanpa kartu, ketiga
  // aksi itu dijamin ditolak server (PIN bawaannya berasal dari NIS kartu).
  $('#o-layanan').classList.toggle('hidden', !(s.peran === 'pegawai' && s.punya_kartu));
  $('#o-saldo').textContent = rp(s.saldo);
  // Saldo titipan: uang yang sudah diterima sekolah tetapi sengaja belum ditaruh
  // di kartu. Ditampilkan agar wali yang menyetor sekaligus banyak bisa melihat
  // sisanya masih tercatat — bukan hilang, dan bukan pula bisa dibelanjakan.
  const titipan = $('#o-titipan');
  titipan.classList.toggle('hidden', !(s.saldo_titipan > 0));
  if (s.saldo_titipan > 0) {
    titipan.innerHTML = `+ ${rp(s.saldo_titipan)} saldo titipan — otomatis mengisi kartu saat saldo menipis
      · <button class="linkbtn" id="o-titipan-lihat">lihat rinciannya</button>`;
    $('#o-titipan-lihat').onclick = bukaTitipan;
  }
  $('#o-terakhir').textContent = 'Transaksi terakhir: ' + sejakKapan(d.terakhir);

  const tabungan = s.tabungan || [];
  $('#o-tabungan-panel').classList.toggle('hidden', tabungan.length === 0);
  if (tabungan.length) {
    $('#o-tabungan-list').innerHTML = tabungan.map((t) => `
      <div class="o-tp">
        <div class="o-tp-isi">
          <b>${esc(t.nama || 'Tabungan')}</b>
          ${t.target ? tabunganProgresHtml(t.saldo, t.target) : '<span class="muted">Tanpa target</span>'}
        </div>
        <b>${rp(t.saldo)}</b>
      </div>`).join('');
  }

  const kartu = [['Jajan hari ini', rp(d.belanja_hari_ini), `${d.n_hari_ini}× transaksi`]];
  // Saat rentang yang dipilih memang 7 hari, kartu "7 hari terakhir" akan sama
  // persis dengan kartu rentang — tampilkan satu saja.
  if (d.hari !== 7) kartu.push(['7 hari terakhir', rp(d.belanja_7hari), '']);
  kartu.push(
    [`${d.hari} hari terakhir`, rp(d.belanja_periode), `${d.n_periode}× transaksi`],
    ['Rata-rata per hari jajan', rp(d.rata2_per_hari_aktif), `${d.hari_jajan} hari ada jajan`],
    [`Saldo masuk (${d.hari} hari)`, rp(d.topup_periode), ''],
  );
  $('#o-stat').innerHTML = kartu.map(([judul, nilai, sub]) => `<div class="statcard">
      <div class="muted">${esc(judul)}</div><b>${esc(nilai)}</b>
      ${sub ? `<div class="muted">${esc(sub)}</div>` : ''}
    </div>`).join('');

  vizBar($('#o-grafik'), {
    data: d.harian.map((h) => ({
      label: tanggalPendek(h.tgl),
      nilai: h.belanja,
      tooltipLabel: tanggalPendek(h.tgl),
      tooltipExtra: h.n ? `${h.n}× transaksi` : 'tidak jajan',
    })),
    namaDeret: 'Jajan',
    fmtY: (n) => 'Rp' + (n >= 1000 ? Math.round(n / 1000) + 'rb' : n),
    fmtTip: rp,
    tinggi: 220,
  });

  const totalTenant = d.per_tenant.reduce((a, t) => a + t.total, 0) || 1;
  vizHBar($('#o-tenant'), {
    data: d.per_tenant.map((t) => ({
      label: esc(t.nama), nilai: t.total,
      sub: `${t.n}× · ${Math.round((t.total / totalTenant) * 100)}% dari total jajan`,
    })),
    warnaPer: true,
  });

  $('#o-menu').innerHTML = d.menu_favorit.length
    ? `<div class="o-chips">${d.menu_favorit.map((m) =>
        `<span class="o-chip">${esc(m.nama)}<b>${m.n}×</b></span>`).join('')}</div>`
    : '<p class="muted">Belum ada pembelian pada rentang ini.</p>';
}

async function muatTransaksi() {
  const p = new URLSearchParams({ page: state.page, limit: 20 });
  if (state.tipe) p.set('tipe', state.tipe);
  let d;
  try {
    d = await api('/api/ortu/transaksi?' + p);
  } catch (e) {
    return tanganiGagal(e);
  }
  $('#o-tx-ringkas').textContent =
    `${d.total.toLocaleString('id-ID')} transaksi · masuk ${rp(d.total_masuk)} · keluar ${rp(d.total_keluar)}`;

  const LABEL = { beli: 'Jajan', topup: 'Isi saldo', koreksi: 'Penyesuaian', donasi: 'Donasi',
    transfer_keluar: 'Kirim ke teman', transfer_masuk: 'Terima dari teman', biaya_admin: 'Biaya admin',
    iuran_keluar: 'Bayar iuran', iuran_masuk: 'Terima iuran', tabungan: 'Tabungan' };
  // [kelas warna, ikon] per jenis. Warna sendiri tidak pernah jadi satu-satunya
  // penanda: tanda +/− dan labelnya tetap tertulis di sebelahnya.
  const RUPA = { beli: ['beli', 'beli'], donasi: ['donasi', 'donasi'], transfer_keluar: ['keluar', 'kirim'],
    transfer_masuk: ['masuk', 'kirim'], biaya_admin: ['keluar', 'admin'],
    iuran_keluar: ['keluar', 'kirim'], iuran_masuk: ['masuk', 'kirim'],
    // Uang Tabungan tetap milik santri sendiri (cuma pindah kantong), bukan
    // dibelanjakan — ikonnya sengaja beda dari "keluar" (merah, minus) supaya
    // wali tidak salah kira itu pengeluaran. Tanda +/− pada nominalnya tetap
    // apa adanya (setor keluar dari kartu, cair masuk ke kartu).
    tabungan: ['tabungan', 'tabungan'] };
  $('#o-tx').innerHTML = d.transaksi.map((t) => {
    const masuk = t.jumlah > 0;
    const [kelas, bentuk] = RUPA[t.tipe] || (masuk ? ['masuk', 'masuk'] : ['keluar', 'keluar']);
    return `<div class="o-tx">
      <div class="o-tx-ikon ${kelas}">${ikon(bentuk)}</div>
      <div class="o-tx-isi">
        <b>${esc(LABEL[t.tipe] || t.tipe)}${t.tenant_nama ? ' · ' + esc(t.tenant_nama) : ''}</b>
        <span class="muted">${esc(waktuWib(t.created_at))}</span>
        ${t.keterangan ? `<span class="o-tx-ket">${esc(t.keterangan)}</span>` : ''}
      </div>
      <div class="o-tx-nilai">
        <b class="${masuk ? 'plus' : 'minus'}">${masuk ? '+' : '−'}${rp(Math.abs(t.jumlah))}</b>
        <span class="muted">sisa ${rp(t.saldo_sesudah)}</span>
      </div>
    </div>`;
  }).join('') || '<p class="muted">Belum ada transaksi.</p>';

  const maxPage = Math.max(1, Math.ceil(d.total / d.limit));
  $('#o-tx-info').textContent = `hal ${d.page} / ${maxPage}`;
  $('#o-tx-prev').disabled = d.page <= 1;
  $('#o-tx-next').disabled = d.page >= maxPage;
}

// ------------------------------------------------------------ isi saldo
/**
 * Pengajuan isi saldo — tiga langkah dalam satu modal:
 *   1. pilih metode (QRIS statis / transfer bank)
 *   2. baca instruksi, bayar sendiri di luar sistem
 *   3. isi konfirmasi (nominal, tanggal, opsional pengirim & bukti)
 *
 * Halaman ini tidak pernah menambah saldo. Yang terkirim hanyalah klaim yang
 * menunggu diperiksa admin — karena itu seluruh teks di sini sengaja memakai
 * kata "menunggu/diperiksa", bukan "berhasil ditambahkan".
 */
let topupInfo = null;       // hasil /api/ortu/topup/info
let topupPilihan = null;    // metode yang sedang dipilih
let topupBukti = null;      // data URL bukti bayar (opsional)

function bukaModal(isi, tombol) {
  const root = $('#o-modal-root');
  root.innerHTML = `<div class="modal-bg"><div class="modal">${isi}
    <div class="actions">${tombol}</div></div></div>`;
  root.querySelector('.modal-bg').addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-bg')) tutupModal();
  });
  const batal = $('#om-batal');
  if (batal) batal.onclick = tutupModal;
}
function tutupModal() { $('#o-modal-root').innerHTML = ''; }

// --------------------------------------------- layanan pegawai (tabungan,
// donasi, ganti PIN dari perangkat sendiri). Pola modalnya meniru alur isi
// saldo di atas (menu → pilihan → konfirmasi), tapi eksekusinya langsung,
// bukan pengajuan yang menunggu admin — sama seperti di kios, hanya pintu
// masuknya yang beda (sesi login /ortu, bukan tap kartu).
let pegawaiOpsi = null;
// NIS yang baru dicari (langkah 1 Transfer) — Transfer::penerimaUntukKios()
// sengaja TIDAK membawa NIS penerima dalam hasil pencarian (tidak membocorkan
// NIS orang lain selain yang sudah diketik sendiri), jadi disimpan di sini
// untuk dipakai ulang saat benar-benar mengirim di langkah 2.
let layananTransferNisAsal = '';

async function mulaiLayanan() {
  let d;
  try { d = await api('/api/ortu/pegawai/opsi'); }
  catch (e) { return tanganiGagal(e); }
  pegawaiOpsi = d;
  layananMenu();
}

function layananMenu() {
  const d = pegawaiOpsi;
  bukaModal(`
    <h2>Layanan Pegawai</h2>
    <p class="muted">Saldo kartu saat ini <b>${rp(d.saldo)}</b>.</p>
    <div class="o-metode-grid">
      <button class="o-metode" id="o-pg-pilih-tabungan" ${d.tabungan_jenis.length ? '' : 'disabled'}>
        <span class="o-metode-lg o-pg-ikon ok">💰</span>
        <span><b>Tabungan</b><span class="muted">${d.tabungan_jenis.length
          ? `Sisa jatah hari ini ${rp(d.sisa_tabungan_harian)}` : 'Belum ada jenis tabungan dibuka sekolah'}</span></span>
      </button>
      <button class="o-metode" id="o-pg-pilih-donasi" ${d.donasi_program.length ? '' : 'disabled'}>
        <span class="o-metode-lg o-pg-ikon err">❤</span>
        <span><b>Donasi</b><span class="muted">${d.donasi_program.length
          ? `Sisa jatah hari ini ${rp(d.sisa_donasi)}` : 'Belum ada program donasi dibuka sekolah'}</span></span>
      </button>
      <button class="o-metode" id="o-pg-pilih-transfer" ${d.transfer_bisa ? '' : 'disabled'}>
        <span class="o-metode-lg o-pg-ikon teal">➤</span>
        <span><b>Transfer</b><span class="muted">${d.transfer_bisa
          ? `Sisa jatah hari ini ${rp(d.sisa_transfer_harian)} · ${d.sisa_transfer_kali}x` : 'Golongan ini tidak bisa mengirim saldo'}</span></span>
      </button>
      <button class="o-metode" id="o-pg-pilih-pin">
        <span class="o-metode-lg o-pg-ikon vio">🔑</span>
        <span><b>Ganti PIN</b><span class="muted">Dipakai untuk semua layanan ini & di kios</span></span>
      </button>
    </div>`,
    '<button id="om-batal">Tutup</button>');

  $('#o-pg-pilih-tabungan').onclick = layananTabunganForm;
  $('#o-pg-pilih-donasi').onclick = layananDonasiProgram;
  $('#o-pg-pilih-transfer').onclick = layananTransferNis;
  $('#o-pg-pilih-pin').onclick = layananPinForm;
}

function layananTabunganForm() {
  const d = pegawaiOpsi;
  const opsiJenis = d.tabungan_jenis.map((j) => `<option value="${j.id}">${esc(j.nama)}</option>`).join('');
  bukaModal(`
    <h2>Tabungan</h2>
    <div class="field"><label>Jenis tabungan</label>
      <select id="o-pg-jenis">${opsiJenis}</select>
      <div id="o-pg-progres"></div></div>
    <div class="field"><label>Nominal</label>
      ${inputUang({ id: 'o-pg-jumlah', placeholder: 'contoh: 50.000' })}
      <span class="muted hint">Minimal ${rp(d.tabungan_min)}, sisa jatah hari ini ${rp(d.sisa_tabungan_harian)}</span></div>
    <div class="field"><label>PIN</label>
      <input id="o-pg-pin" type="password" inputmode="numeric" maxlength="20" autocomplete="off" placeholder="PIN kartu Anda"></div>
    <p id="o-pg-err" class="o-err hidden"></p>`,
    `<button id="om-batal">Batal</button>
     <button id="om-kembali">‹ Layanan lain</button>
     <button id="om-kirim" class="primary">Setor</button>`);
  $('#om-kembali').onclick = layananMenu;
  $('#om-kirim').onclick = layananTabunganKirim;

  const sinkronProgres = () => {
    const jenisId = Number($('#o-pg-jenis').value);
    const jenis = d.tabungan_jenis.find((j) => j.id === jenisId);
    const saldo = d.tabungan_saldo.find((t) => t.jenis_id === jenisId)?.saldo || 0;
    $('#o-pg-progres').innerHTML = tabunganProgresHtml(saldo, jenis?.target);
  };
  $('#o-pg-jenis').addEventListener('change', sinkronProgres);
  sinkronProgres();
}

function pegawaiTxId(label) {
  return `pg-${label}-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
}

async function layananTabunganKirim() {
  const btn = $('#om-kirim');
  const err = $('#o-pg-err');
  err.classList.add('hidden');
  const jenisId = Number($('#o-pg-jenis').value);
  const jumlah = nilaiUang('#o-pg-jumlah') ?? 0;
  const pin = $('#o-pg-pin').value;
  if (!jumlah) { err.textContent = 'Isi nominal.'; err.classList.remove('hidden'); return; }
  if (!pin) { err.textContent = 'Isi PIN.'; err.classList.remove('hidden'); return; }
  btn.disabled = true; btn.textContent = 'Memproses…';
  try {
    const d = await api('/api/ortu/pegawai/tabungan', { method: 'POST',
      body: { jenis_id: jenisId, jumlah, pin, tx_id: pegawaiTxId('tab') } });
    tutupModal();
    toast(`Tabungan ${d.jenis} ${rp(d.jumlah)} berhasil.`, 'ok');
    muatRingkasan();
    muatTransaksi();
  } catch (e) {
    err.textContent = e.message; err.classList.remove('hidden');
    btn.disabled = false; btn.textContent = 'Setor';
  }
}

function layananDonasiProgram() {
  const d = pegawaiOpsi;
  const pilihan = d.donasi_program.map((p) => `
    <button class="o-metode" data-program="${p.id}">
      <span class="o-metode-lg">❤</span>
      <span><b>${esc(p.nama)}</b><span class="muted">${p.nominal.map((n) => rp(n)).join(' · ')}</span></span>
    </button>`).join('');
  bukaModal(`
    <h2>Pilih Program Donasi</h2>
    <p class="muted">Sisa jatah donasi hari ini <b>${rp(d.sisa_donasi)}</b>.</p>
    <div class="o-metode-grid">${pilihan}</div>`,
    `<button id="om-batal">Batal</button>
     <button id="om-kembali">‹ Layanan lain</button>`);
  $('#om-kembali').onclick = layananMenu;
  document.querySelectorAll('#o-modal-root [data-program]').forEach((b) => {
    b.onclick = () => layananDonasiNominal(d.donasi_program.find((p) => p.id === Number(b.dataset.program)));
  });
}

function layananDonasiNominal(program) {
  const d = pegawaiOpsi;
  const tombol = program.nominal.map((n) => {
    const cukup = n <= d.saldo && n <= d.sisa_donasi;
    return `<button class="o-metode" data-jumlah="${n}" ${cukup ? '' : 'disabled'}>
      <span><b>${rp(n)}</b>${cukup ? '' : '<span class="muted">tidak cukup</span>'}</span></button>`;
  }).join('');
  bukaModal(`
    <h2>${esc(program.nama)}</h2>
    <p class="muted">Pilih nominal donasi.</p>
    <div class="o-metode-grid">${tombol}</div>
    <div class="field"><label>PIN</label>
      <input id="o-pg-pin" type="password" inputmode="numeric" maxlength="20" autocomplete="off" placeholder="PIN kartu Anda"></div>
    <p id="o-pg-err" class="o-err hidden"></p>`,
    `<button id="om-batal">Batal</button>
     <button id="om-kembali">‹ Program lain</button>`);
  $('#om-kembali').onclick = layananDonasiProgram;
  document.querySelectorAll('#o-modal-root [data-jumlah]').forEach((b) => {
    b.onclick = () => layananDonasiKirim(program, Number(b.dataset.jumlah));
  });
}

async function layananDonasiKirim(program, jumlah) {
  const err = $('#o-pg-err');
  err.classList.add('hidden');
  const pin = $('#o-pg-pin').value;
  if (!pin) { err.textContent = 'Isi PIN.'; err.classList.remove('hidden'); return; }
  try {
    const d = await api('/api/ortu/pegawai/donasi', { method: 'POST',
      body: { program_id: program.id, jumlah, pin, tx_id: pegawaiTxId('don') } });
    tutupModal();
    toast(`Donasi ${d.program} ${rp(d.jumlah)} berhasil.`, 'ok');
    muatRingkasan();
    muatTransaksi();
  } catch (e) {
    err.textContent = e.message; err.classList.remove('hidden');
  }
}

/** Langkah 1: masukkan NIS penerima, lalu cari dulu (nama dikonfirmasi
 *  sebelum nominal diketik — salah ketik NIS tidak boleh terlihat baru
 *  setelah uang terkirim). */
function layananTransferNis() {
  const d = pegawaiOpsi;
  bukaModal(`
    <h2>Transfer Saldo</h2>
    <p class="muted">Sisa jatah kirim hari ini <b>${rp(d.sisa_transfer_harian)}</b>
      (${d.sisa_transfer_kali}x lagi).</p>
    <div class="field"><label>NIS penerima</label>
      <input id="o-pg-nis" inputmode="numeric" autocomplete="off" placeholder="NIS teman/santri tujuan"></div>
    <p id="o-pg-err" class="o-err hidden"></p>`,
    `<button id="om-batal">Batal</button>
     <button id="om-kembali">‹ Layanan lain</button>
     <button id="om-kirim" class="primary">Cari</button>`);
  $('#om-kembali').onclick = layananMenu;
  $('#om-kirim').onclick = layananTransferCari;
  $('#o-pg-nis').addEventListener('keydown', (e) => { if (e.key === 'Enter') layananTransferCari(); });
}

async function layananTransferCari() {
  const btn = $('#om-kirim');
  const err = $('#o-pg-err');
  err.classList.add('hidden');
  const nis = $('#o-pg-nis').value.trim();
  if (!nis) { err.textContent = 'Isi NIS penerima.'; err.classList.remove('hidden'); return; }
  btn.disabled = true; btn.textContent = 'Mencari…';
  try {
    const d = await api('/api/ortu/pegawai/cari-penerima', { method: 'POST', body: { nis } });
    layananTransferNisAsal = nis;
    layananTransferNominal(d);
  } catch (e) {
    err.textContent = e.message; err.classList.remove('hidden');
    btn.disabled = false; btn.textContent = 'Cari';
  }
}

/** Langkah 2: nama penerima sudah dikonfirmasi — tinggal nominal + PIN. */
function layananTransferNominal(hasil) {
  const p = hasil.penerima;
  bukaModal(`
    <h2>Kirim ke ${esc(p.nama)}</h2>
    <p class="muted">${esc(p.kelas || p.golongan || '')}</p>
    <div class="field"><label>Nominal</label>
      ${inputUang({ id: 'o-pg-jumlah', placeholder: 'contoh: 10.000' })}
      <span class="muted hint">Minimal ${rp(hasil.min)}, maksimal ${rp(hasil.maks)} kali ini</span></div>
    <div class="field"><label>Catatan (opsional)</label>
      <input id="o-pg-catatan" maxlength="60" placeholder="mis. buat jajan besok"></div>
    <div class="field"><label>PIN</label>
      <input id="o-pg-pin" type="password" inputmode="numeric" maxlength="20" autocomplete="off" placeholder="PIN kartu Anda"></div>
    <p id="o-pg-err" class="o-err hidden"></p>`,
    `<button id="om-batal">Batal</button>
     <button id="om-kembali">‹ Cari NIS lain</button>
     <button id="om-kirim" class="primary">Kirim</button>`);
  $('#om-kembali').onclick = layananTransferNis;
  // layananTransferNisAsal diisi oleh layananTransferCari() SEBELUM modal ini
  // dibuka — elemen #o-pg-nis milik langkah 1 sudah tidak ada lagi di DOM
  // begitu bukaModal() di atas mengganti isinya.
  $('#om-kirim').onclick = () => layananTransferKirim(layananTransferNisAsal);
}

async function layananTransferKirim(nis) {
  const btn = $('#om-kirim');
  const err = $('#o-pg-err');
  err.classList.add('hidden');
  const jumlah = nilaiUang('#o-pg-jumlah') ?? 0;
  const catatan = $('#o-pg-catatan').value.trim();
  const pin = $('#o-pg-pin').value;
  if (!jumlah) { err.textContent = 'Isi nominal.'; err.classList.remove('hidden'); return; }
  if (!pin) { err.textContent = 'Isi PIN.'; err.classList.remove('hidden'); return; }
  btn.disabled = true; btn.textContent = 'Memproses…';
  try {
    const d = await api('/api/ortu/pegawai/transfer', { method: 'POST',
      body: { nis, jumlah, catatan, pin, tx_id: pegawaiTxId('trf') } });
    tutupModal();
    toast(`Transfer ${rp(d.jumlah)} ke ${d.penerima.nama} berhasil.`, 'ok');
    muatRingkasan();
    muatTransaksi();
  } catch (e) {
    err.textContent = e.message; err.classList.remove('hidden');
    btn.disabled = false; btn.textContent = 'Kirim';
  }
}

function layananPinForm() {
  bukaModal(`
    <h2>Ganti PIN</h2>
    <div class="field"><label>PIN lama</label>
      <input id="o-pg-pin-lama" type="password" inputmode="numeric" maxlength="20" autocomplete="off"></div>
    <div class="field"><label>PIN baru (4–8 angka)</label>
      <input id="o-pg-pin-baru" type="password" inputmode="numeric" maxlength="8" autocomplete="off"></div>
    <div class="field"><label>Ulangi PIN baru</label>
      <input id="o-pg-pin-baru2" type="password" inputmode="numeric" maxlength="8" autocomplete="off"></div>
    <p id="o-pg-err" class="o-err hidden"></p>`,
    `<button id="om-batal">Batal</button>
     <button id="om-kembali">‹ Layanan lain</button>
     <button id="om-kirim" class="primary">Ganti PIN</button>`);
  $('#om-kembali').onclick = layananMenu;
  $('#om-kirim').onclick = layananPinKirim;
}

async function layananPinKirim() {
  const btn = $('#om-kirim');
  const err = $('#o-pg-err');
  err.classList.add('hidden');
  const lama = $('#o-pg-pin-lama').value;
  const baru = $('#o-pg-pin-baru').value;
  const baru2 = $('#o-pg-pin-baru2').value;
  if (!lama || !baru) { err.textContent = 'Isi PIN lama dan PIN baru.'; err.classList.remove('hidden'); return; }
  if (baru !== baru2) { err.textContent = 'PIN baru dan pengulangannya tidak sama.'; err.classList.remove('hidden'); return; }
  btn.disabled = true; btn.textContent = 'Memproses…';
  try {
    await api('/api/ortu/pegawai/ganti-pin', { method: 'POST', body: { pin_lama: lama, pin_baru: baru } });
    tutupModal();
    toast('PIN berhasil diganti.', 'ok');
  } catch (e) {
    err.textContent = e.message; err.classList.remove('hidden');
    btn.disabled = false; btn.textContent = 'Ganti PIN';
  }
}

/** Tanggal WIB n hari ke belakang, format YYYY-MM-DD untuk atribut min/max. */
function tglMundur(n) {
  return new Date(Date.now() + 7 * 3600 * 1000 - n * 86400000).toISOString().slice(0, 10);
}

/**
 * Tombol "Isi Saldo" hanya muncul kalau layanannya memang bisa dipakai —
 * lebih jujur daripada tombol yang selalu ada tapi selalu menolak.
 */
async function siapkanTombolTopup() {
  try {
    topupInfo = await api('/api/ortu/topup/info');
  } catch {
    return;                                   // gagal memuat → tombol tetap tersembunyi
  }
  $('#o-topup').classList.toggle('hidden', !topupInfo.aktif);
  $('#o-tab-topup').classList.toggle('hidden', !topupInfo.aktif);
}

async function mulaiTopup() {
  try {
    topupInfo = await api('/api/ortu/topup/info');   // muat ulang: saldo & antrean bisa berubah
  } catch (e) {
    return tanganiGagal(e);
  }
  if (!topupInfo.aktif) {
    $('#o-topup').classList.add('hidden');
    $('#o-tab-topup').classList.add('hidden');
    return toast(topupInfo.ditutup
      ? 'Pengajuan isi saldo sedang ditutup sekolah.'
      : 'Sekolah belum mengisi data pembayaran. Hubungi admin kantin.', 'err');
  }
  if (topupInfo.antre_n >= topupInfo.antre_maks)
    return toast(`Masih ada ${topupInfo.antre_n} pengajuan menunggu diperiksa admin.`, 'err');
  langkahMetode();
}

function langkahMetode() {
  topupBukti = null;
  const pilihan = topupInfo.metode.map((m) => `
    <button class="o-metode" data-kode="${esc(m.kode)}">
      <span class="o-metode-lg">${m.kode === 'qris' ? 'QRIS' : 'BANK'}</span>
      <span><b>${esc(m.nama)}</b>
      <span class="muted">${m.kode === 'qris'
        ? 'Scan kode QRIS sekolah dari aplikasi bank atau e-wallet'
        : `Transfer ke rekening ${esc(m.bank)} milik sekolah`}</span></span>
    </button>`).join('');

  bukaModal(`
    <h2>Isi Saldo</h2>
    <p class="muted">Pembayaran dilakukan langsung ke rekening sekolah, lalu Anda mengisi
      formulir konfirmasi di langkah berikutnya. Saldo bertambah setelah admin
      memeriksa dan menyetujui.</p>
    <div class="o-metode-grid">${pilihan}</div>`,
    '<button id="om-batal">Tutup</button>');

  document.querySelectorAll('.o-metode').forEach((b) => {
    b.onclick = () => langkahInstruksi(b.dataset.kode);
  });
}

function langkahInstruksi(kode) {
  const m = topupInfo.metode.find((x) => x.kode === kode);
  if (!m) return langkahMetode();
  topupPilihan = m;

  /*
   * QRIS bisa diunduh, dan itu bukan kemudahan tambahan melainkan keharusan:
   * wali membuka portal ini DI PONSEL yang sama dengan aplikasi banknya, dan
   * satu ponsel tidak bisa memindai layarnya sendiri. Tanpa tombol ini,
   * satu-satunya jalan adalah meminjam ponsel kedua.
   */
  const rincian = kode === 'qris'
    ? `<img class="o-qris" src="/uploads/${encodeURIComponent(m.gambar)}" alt="Kode QRIS sekolah">
       <p class="o-qris-unduh">
         <a class="linkbtn" id="o-qris-unduh"
            href="/uploads/${encodeURIComponent(m.gambar)}?unduh=1"
            download>⬇ Simpan gambar QRIS ke ponsel</a>
         <span class="muted hint">Simpan dulu, lalu buka aplikasi bank/e-wallet Anda dan
           pilih <b>scan dari galeri</b>.</span></p>
       ${m.penerima ? `<p class="o-bayar-baris"><span class="muted">Penerima</span><b>${esc(m.penerima)}</b></p>` : ''}`
    : `<p class="o-bayar-baris"><span class="muted">Bank</span><b>${esc(m.bank)}</b></p>
       <p class="o-bayar-baris"><span class="muted">Nomor rekening</span>
         <b>${esc(m.rekening)} <button class="linkbtn" id="o-salin">salin</button></b></p>
       ${m.atasnama ? `<p class="o-bayar-baris"><span class="muted">Atas nama</span><b>${esc(m.atasnama)}</b></p>` : ''}`;

  bukaModal(`
    <h2>${esc(m.nama)}</h2>
    ${rincian}
    ${m.instruksi ? `<p class="muted o-instruksi">${esc(m.instruksi)}</p>` : ''}
    <p class="o-catat">Nominal bebas antara <b>${rp(topupInfo.min)}</b> dan <b>${rp(topupInfo.maks)}</b>.
      Setelah benar-benar membayar, tekan tombol di bawah untuk mengisi konfirmasi.</p>`,
    `<button id="om-batal">Batal</button>
     <button id="om-kembali">‹ Metode lain</button>
     <button id="om-lanjut" class="primary">Saya sudah bayar ›</button>`);

  $('#om-kembali').onclick = langkahMetode;
  $('#om-lanjut').onclick = langkahKonfirmasi;
  const salin = $('#o-salin');
  if (salin) salin.onclick = async () => {
    try {
      await navigator.clipboard.writeText(m.rekening);
      toast('Nomor rekening disalin', 'ok');
    } catch {
      toast('Salin manual: ' + m.rekening);
    }
  };
}

function langkahKonfirmasi() {
  const m = topupPilihan;
  bukaModal(`
    <h2>Konfirmasi Pembayaran</h2>
    <p class="muted">Isi sesuai yang benar-benar Anda bayarkan. Admin mencocokkannya dengan
      mutasi rekening sekolah sebelum menyetujui.</p>
    <div class="field"><label>Nominal yang dibayar</label>
      ${inputUang({ id: 'o-tp-jumlah', placeholder: 'contoh: 50.000' })}
      <span class="muted hint">Antara ${rp(topupInfo.min)} dan ${rp(topupInfo.maks)}</span></div>
    ${topupInfo.biaya_admin > 0 ? '<div class="o-rincian" id="o-tp-rincian"></div>' : ''}
    <div class="field"><label>Metode pembayaran</label>
      <input value="${esc(m.nama)}" disabled></div>
    <div class="field"><label>Tanggal pembayaran</label>
      <input id="o-tp-tgl" type="date" value="${esc(topupInfo.hari_ini)}"
        min="${esc(tglMundur(topupInfo.mundur_maks_hari))}" max="${esc(topupInfo.hari_ini)}"></div>
    <div class="field"><label>Nama pengirim <span class="o-wajib">wajib</span></label>
      <input id="o-tp-pengirim" maxlength="80" placeholder="nama pemilik rekening pengirim">
      <span class="muted hint">Sesuai nama di rekening/aplikasi yang Anda pakai membayar —
        ini yang dicari admin di mutasi rekening sekolah.</span></div>
    <div class="field"><label>Bukti pembayaran <span class="o-wajib">wajib</span></label>
      <input id="o-tp-bukti" type="file" accept="image/*">
      <span class="muted hint" id="o-tp-bukti-hint">Foto atau tangkapan layar bukti bayar —
        ukurannya dikecilkan otomatis. Pastikan gambarnya sudah selesai terunduh di ponsel Anda.</span></div>
    <p id="o-tp-err" class="o-err hidden"></p>`,
    `<button id="om-batal">Batal</button>
     <button id="om-kembali">‹ Instruksi</button>
     <button id="om-kirim" class="primary">Kirim konfirmasi</button>`);

  $('#om-kembali').onclick = () => langkahInstruksi(m.kode);
  const hintBukti = $('#o-tp-bukti-hint');
  const bakuHint = hintBukti.textContent;
  $('#o-tp-bukti').onchange = async (e) => {
    const f = e.target.files[0];
    topupBukti = null;
    segarkanTombolKirim();
    if (!f) { hintBukti.textContent = bakuHint; return; }
    /*
     * Berkas yang terlalu kecil hampir pasti belum selesai terunduh — kejadian
     * biasa saat gambar dipilih dari galeri di sinyal yang seadanya. Ditahan di
     * sini selagi walinya masih di depan layar, bukan dibiarkan lolos lalu
     * ketahuan berhari-hari kemudian ketika admin tidak bisa membuka gambarnya.
     * Dicek pada berkas ASLI, sebelum dikompres: berkas yang memang terpotong
     * biasanya gagal dibaca kanvas juga, tapi pesan di sini jauh lebih jelas
     * sebabnya daripada galat kompresi yang generik.
     */
    if (f.size < 512) {
      e.target.value = ''; hintBukti.textContent = bakuHint;
      return toast('Gambar terbaca tidak lengkap. Tunggu unduhannya selesai, lalu pilih lagi.', 'err');
    }
    // Foto langsung dari kamera biasanya 2-5MB — DIKOMPRES di sini, bukan
    // ditolak. Wali yang tidak paham teknologi tidak punya cara mengecilkan
    // gambar sendiri, jadi menolak mentah-mentah di atas suatu batas cuma
    // memindahkan masalahnya ke orang yang paling tidak siap menyelesaikannya.
    hintBukti.textContent = 'Memproses gambar…';
    try {
      const hasil = await kompresGambar(f);
      // Jaring pengaman terakhir, bukan jalur yang diharapkan tersentuh:
      // kompresi menyasar sisi 1600px/kualitas 0.85, hasilnya nyaris selalu
      // jauh di bawah ini. Kalau tetap kena (mis. kanvas gagal lalu jatuh ke
      // berkas asli yang kebetulan besar), wali tetap diberi tahu dengan
      // jelas, bukan diam-diam gagal terkirim nanti di server.
      if (hasil.byteAkhir > 3 * 1024 * 1024) {
        e.target.value = ''; hintBukti.textContent = bakuHint;
        return toast('Gambar masih terlalu besar setelah dikecilkan. Coba foto ulang ' +
          'dari jarak lebih dekat, atau kirim tangkapan layar saja.', 'err');
      }
      topupBukti = hasil.dataUrl;
      const kb = Math.round(hasil.byteAkhir / 1024);
      hintBukti.textContent = hasil.dikompres
        ? `✔ ${f.name} — dikecilkan ke ${kb} KB, siap dikirim`
        : `✔ ${f.name} (${kb} KB) siap dikirim`;
    } catch {
      e.target.value = ''; hintBukti.textContent = bakuHint;
      toast('Bukti gagal dibaca', 'err');
    }
    segarkanTombolKirim();
  };

  /*
   * Tombol kirim baru hidup kalau keduanya sudah lengkap. Menolak SESUDAH
   * ditekan juga benar dan tetap dilakukan server, tapi tombol yang mati lebih
   * jujur: wali tahu apa yang kurang sebelum mengira pengajuannya sudah masuk.
   */
  function segarkanTombolKirim() {
    const btn = $('#om-kirim');
    if (!btn) return;
    const adaPengirim = ($('#o-tp-pengirim')?.value || '').trim().length >= 3;
    btn.disabled = !(adaPengirim && topupBukti);
    btn.title = btn.disabled ? 'Lengkapi nama pengirim dan bukti pembayaran dulu' : '';
  }
  $('#o-tp-pengirim').addEventListener('input', segarkanTombolKirim);
  segarkanTombolKirim();

  $('#om-kirim').onclick = kirimTopup;

  /*
   * Rincian biaya dihitung ulang setiap kali nominalnya diketik, bukan ditulis
   * sekali sebagai catatan kaki. Wali perlu melihat angka untuk nominal YANG
   * IA PILIH — "biaya Rp2.500" mudah terlewat, "masuk ke kartu Rp47.500"
   * tidak. Menemukan potongan itu baru setelah saldo anaknya ternyata kurang
   * adalah cara tercepat membuat orang berhenti percaya pada sistem pembayaran
   * sekolahnya.
   */
  if (topupInfo.biaya_admin > 0) {
    const gambarRincian = () => {
      const el = $('#o-tp-rincian');
      if (!el) return;
      const bayar = nilaiUang('#o-tp-jumlah') ?? 0;
      const masuk = Math.max(0, bayar - topupInfo.biaya_admin);
      el.innerHTML = `
        <div><span>Nominal dibayar</span><b>${bayar ? rp(bayar) : '—'}</b></div>
        <div><span>Biaya admin</span><span>${rp(topupInfo.biaya_admin)}</span></div>
        <div><span>Masuk ke kartu</span><b class="plus">${bayar ? rp(masuk) : '—'}</b></div>`;
    };
    $('#o-tp-jumlah').addEventListener('input', gambarRincian);
    gambarRincian();
  }

  $('#o-tp-jumlah').focus();
}

async function kirimTopup() {
  const btn = $('#om-kirim');
  const kotak = $('#o-tp-err');
  const tunjukkan = (pesan) => { kotak.textContent = pesan; kotak.classList.remove('hidden'); };

  const jumlah = nilaiUang('#o-tp-jumlah') ?? 0;
  const tgl = $('#o-tp-tgl').value;
  if (!jumlah) return tunjukkan('Nominal belum diisi.');
  if (jumlah < topupInfo.min) return tunjukkan(`Nominal minimal ${rp(topupInfo.min)}.`);
  if (jumlah > topupInfo.maks) return tunjukkan(`Nominal maksimal ${rp(topupInfo.maks)}.`);
  if (!tgl) return tunjukkan('Tanggal pembayaran belum diisi.');

  kotak.classList.add('hidden');
  btn.disabled = true;
  btn.textContent = 'Mengirim…';
  try {
    const d = await api('/api/ortu/topup', { method: 'POST', body: {
      jumlah, metode: topupPilihan.kode, tgl_bayar: tgl,
      pengirim: $('#o-tp-pengirim').value,
      bukti: topupBukti || undefined,
    } });
    tutupModal();
    await muatPengajuan();
    /*
     * Melebihi batas saldo TIDAK LAGI ditolak (lihat server) — wali tetap
     * boleh mengirim. Pesannya cukup panjang untuk menjelaskan ke mana
     * kelebihannya pergi, jadi dipakaikan modal yang harus ditutup sendiri,
     * bukan toast yang lewat begitu saja dalam tiga detik.
     */
    if (d.peringatan) {
      bukaModal(`<h3>Konfirmasi terkirim</h3><p>${esc(d.peringatan)}</p>`,
        '<button id="om-batal">Oke, mengerti</button>');
    } else {
      toast('Konfirmasi terkirim — menunggu diperiksa admin', 'ok');
    }
  } catch (e) {
    if (e.status === 401) { tutupModal(); return tanganiGagal(e); }
    tunjukkan(e.message);
    btn.disabled = false;
    btn.textContent = 'Kirim konfirmasi';
  }
}

const TP_STATUS = { pending: 'Menunggu diperiksa', disetujui: 'Disetujui', ditolak: 'Ditolak' };
const TP_METODE = { qris: 'QRIS', transfer: 'Transfer bank' };

async function muatPengajuan() {
  let d;
  try {
    d = await api('/api/ortu/topup');
  } catch (e) {
    return tanganiGagal(e);
  }
  const panel = $('#o-tp-panel');
  panel.classList.toggle('hidden', d.pengajuan.length === 0);
  if (!d.pengajuan.length) return;

  $('#o-tp-ringkas').textContent = d.menunggu
    ? `${d.menunggu} menunggu diperiksa admin`
    : 'tidak ada yang menunggu';

  $('#o-tp-list').innerHTML = d.pengajuan.map((p) => `
    <div class="o-tp">
      <div class="o-tp-isi">
        <b>${rp(p.jumlah)} · ${esc(TP_METODE[p.metode] || p.metode)}</b>
        <span class="muted">diajukan ${esc(waktuWib(p.created_at))} ·
          dibayar ${esc(tanggalPendek(p.tgl_bayar))}</span>
        ${p.status === 'ditolak' && p.alasan
          ? `<span class="o-tp-alasan">Alasan ditolak: ${esc(p.alasan)}</span>` : ''}
        ${p.bukti ? `<a class="o-tp-bukti" href="/uploads/${encodeURIComponent(p.bukti)}"
            target="_blank" rel="noopener">lihat bukti yang dikirim</a>` : ''}
      </div>
      <span class="badge tp-${esc(p.status)}">${esc(TP_STATUS[p.status] || p.status)}</span>
    </div>`).join('');
}

// ------------------------------------------------------------------ ikat
function pilihGrup(wadah, tombol, jalankan) {
  wadah.querySelectorAll('button').forEach((b) => b.classList.toggle('sel', b === tombol));
  jalankan();
}

/*
 * Aksi cepat dan menu bawah hanya MENGGULIR ke bagian yang sudah ada di
 * halaman — tidak ada layar kedua yang harus dimuat. Sorotan menu bawah
 * mengikuti bagian yang sedang terlihat, supaya wali tahu posisinya.
 */
document.querySelectorAll('[data-ke]').forEach((b) => {
  b.onclick = () => {
    const tujuan = document.getElementById(b.dataset.ke);
    if (tujuan) tujuan.scrollIntoView({ block: 'start' });
  };
});
$('#o-tab-topup').onclick = () => mulaiTopup();
if ('IntersectionObserver' in window) {
  const tab = [...document.querySelectorAll('.o-tabbar [data-ke]')];
  const tampak = new Map();
  const io = new IntersectionObserver((isi) => {
    isi.forEach((x) => tampak.set(x.target.id, x.isIntersecting));
    const aktif = tab.map((b) => b.dataset.ke).reverse().find((id) => tampak.get(id)) || 'o-atas';
    tab.forEach((b) => b.classList.toggle('on', b.dataset.ke === aktif));
  }, { rootMargin: '-35% 0px -55% 0px' });
  tab.forEach((b) => { const t = document.getElementById(b.dataset.ke); if (t) io.observe(t); });
}

$('#o-masuk').onclick = masuk;
$('#o-ganti').onclick = tampilPilihanPeran;
document.querySelectorAll('.o-peran-opsi').forEach((b) => { b.onclick = () => pilihPeran(b.dataset.peran); });
$('#o-keluar').onclick = keluar;
$('#o-topup').onclick = mulaiTopup;
$('#o-layanan').onclick = mulaiLayanan;
addEventListener('keydown', (e) => { if (e.key === 'Escape') tutupModal(); });
// :not([type=checkbox]) — "tetap masuk" bukan kolom angka, dan nilai bawaan
// checkbox ("on") tidak boleh ikut dilucuti seolah-olah isian NIS/tanggal.
document.querySelectorAll('#ortu-login input:not([type=checkbox])').forEach((i) => {
  i.addEventListener('keydown', (e) => { if (e.key === 'Enter') masuk(); });
  // kolom angka: buang karakter non-digit sambil diketik
  i.addEventListener('input', () => { i.value = digit(i.value); });
});

$('#o-rentang').onclick = (e) => {
  const b = e.target.closest('button');
  if (!b) return;
  state.hari = Number(b.dataset.hari);
  pilihGrup($('#o-rentang'), b, muatRingkasan);
};

$('#o-tipe').onclick = (e) => {
  const b = e.target.closest('button');
  if (!b) return;
  state.tipe = b.dataset.tipe;
  state.page = 1;
  pilihGrup($('#o-tipe'), b, muatTransaksi);
};

$('#o-tx-prev').onclick = () => { if (state.page > 1) { state.page--; muatTransaksi(); } };
$('#o-tx-next').onclick = () => { state.page++; muatTransaksi(); };

// sesi masih hidup? langsung tampilkan data, jangan kedipkan form masuk
api('/api/ortu/me')
  .then((d) => {
    pasangKunci(d.minta_hp);
    if (!d.authed) return tampilLogin();
    // Sesi lama (bukan login barusan) — footer perlu tahu dari server apakah
    // "tetap masuk"-nya sedang aktif, karena kotak centangnya sendiri sudah
    // lama tidak ada di layar.
    gambarFooterSesi(d.ingat);
    return mulaiApp();
  })
  // Server tak terjangkau: tampilkan form dengan kunci terlengkap yang mungkin,
  // supaya wali tidak tertahan oleh kolom yang hilang saat sambungan pulih.
  .catch(() => { pasangKunci(true); tampilLogin(); });

/*
 * Rincian titipan.
 *
 * Ada karena titipan kini bisa ikut terpotong oleh sekolah, dan angka yang
 * dilihat wali tidak boleh berubah oleh sebab yang tidak bisa mereka telusuri
 * sendiri. Halaman riwayat biasa hanya memuat mutasi kartu; titipan tinggal di
 * buku terpisah dan sampai sekarang tidak pernah terlihat dari sini.
 *
 * Sekolah boleh mematikannya. Saat mati, yang ditampilkan adalah KEADAAN itu
 * apa adanya — bukan daftar kosong yang membuat orang menyangka tidak pernah
 * ada mutasi apa pun.
 */
const TITIPAN_JENIS = {
  setor: ['＋', 'Setoran titipan'],
  cair: ['⇢', 'Dicairkan ke kartu'],
  smart: ['⇢', 'Isi ulang otomatis ke kartu'],
  tarik: ['−', 'Ditarik sekolah'],
};

async function bukaTitipan() {
  let d;
  try { d = await api('/api/ortu/titipan'); }
  catch (e) { toast(e.message, 'err'); return; }

  if (!d.aktif) {
    bukaModal(`<h2>Saldo Titipan</h2>
      <p style="font-size:26px; font-weight:800; margin:4px 0">${rp(d.saldo_titipan)}</p>
      <p class="muted">${esc(d.pesan)}</p>`,
      '<button id="om-batal">Tutup</button>');
    return;
  }

  bukaModal(`<h2>Saldo Titipan</h2>
    <p style="font-size:26px; font-weight:800; margin:4px 0">${rp(d.saldo_titipan)}</p>
    <p class="muted">Uang yang sudah diterima sekolah tetapi sengaja belum ditaruh di kartu.
      Ia mengisi kartu sedikit demi sedikit saat saldonya menipis.</p>
    ${d.mutasi.length ? d.mutasi.map((m) => {
      const [tanda, label] = TITIPAN_JENIS[m.jenis] || ['·', m.jenis];
      const masuk = m.jumlah > 0;
      return `<div class="o-tx">
        <div class="o-tx-ikon ${masuk ? 'masuk' : 'keluar'}">${ikon(masuk ? 'masuk' : 'keluar')}</div>
        <div class="o-tx-isi">
          <b>${tanda} ${esc(label)}</b>
          <span class="muted">${esc(waktuWib(m.waktu))}</span>
          ${m.keterangan ? `<span class="o-tx-ket">${esc(m.keterangan)}</span>` : ''}
        </div>
        <div class="o-tx-nilai">
          <b class="${masuk ? 'plus' : 'minus'}">${masuk ? '+' : '−'}${rp(Math.abs(m.jumlah))}</b>
          <span class="muted">sisa ${rp(m.saldo_sesudah)}</span>
        </div>
      </div>`;
    }).join('')
      : '<p class="muted">Belum ada mutasi titipan.</p>'}`,
    '<button id="om-batal">Tutup</button>');
}
