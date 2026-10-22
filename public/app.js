'use strict';
/* Kantin Sekolah v2 — frontend role-based (vanilla JS, tanpa dependensi). */

// ========================================================== util
const $ = (s) => document.querySelector(s);
const rp = (n) => 'Rp' + Number(n || 0).toLocaleString('id-ID');
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const normUid = (s) => String(s || '').replace(/[^0-9a-zA-Z]/g, '').toUpperCase();
const initials = (nama) => String(nama || '?').split(/\s+/).slice(0, 2).map((w) => w[0] || '').join('').toUpperCase();
const waktuSingkat = (iso) => new Date(iso).toLocaleString('id-ID',
  { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });

function avatarHtml(s, small) {
  const cls = 'avatar' + (small ? ' small' : '');
  if (s.foto) return `<img class="${cls}" src="/uploads/${esc(s.foto)}" alt="">`;
  return `<div class="${cls}">${esc(initials(s.nama))}</div>`;
}

let toastTimer;
function toast(msg, kind) {
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'show' + (kind ? ' ' + kind : '');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (t.className = ''), 3000);
}

async function api(path, opts = {}) {
  const res = await fetch(path, {
    headers: { 'Content-Type': 'application/json' },
    ...opts,
    body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && path !== '/api/login') { showLogin(); throw new Error(data.error || 'Sesi habis — login lagi'); }
  if (!res.ok) throw new Error(data.error || 'Gagal');
  return data;
}

function fileToDataUrl(file) {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onload = () => resolve(r.result);
    r.onerror = reject;
    r.readAsDataURL(file);
  });
}

function debounce(fn, ms) {
  let t;
  return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}

// ========================================================== modal
let modalKeydown = null;

function openModal(html, onSave, saveLabel, opsi) {
  // `statis`: popup yang isinya mahal untuk diketik ulang (nota pembelian
  // belasan baris) tidak boleh hilang karena klik meleset atau Esc refleks.
  const statis = opsi?.statis === true;
  const root = $('#modal-root');
  root.innerHTML = `<div class="modal-bg"><div class="modal">${html}
    <div class="actions">
      <button id="m-cancel">Batal</button>
      ${onSave ? `<button id="m-save" class="primary">${saveLabel || 'Simpan'}</button>` : ''}
    </div></div></div>`;
  $('#m-cancel').onclick = closeModal;
  if (!statis) root.querySelector('.modal-bg').addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-bg')) closeModal();
  });
  if (onSave) $('#m-save').onclick = async () => {
    const btn = $('#m-save');
    btn.disabled = true;
    try { await onSave(); closeModal(); }
    catch (e) { toast(e.message, 'err'); btn.disabled = false; }
  };
  // Enter = tombol utama (OK/Lanjut/Setuju/dst), Esc = Batal — supaya popup
  // bisa diputuskan dari keyboard eksternal, tanpa harus menyentuh mouse.
  // Dikecualikan kalau fokus sedang di TEXTAREA (Enter tetap ganti baris,
  // mis. alasan penolakan), di tombol modal sendiri (biarkan klik bawaan
  // tombol itu bekerja, supaya tidak terpicu dua kali), atau di kotak
  // target pindai (data-scan, mis. UID kartu/barcode): reader keyboard-
  // wedge kerap mengirim Enter susulan yang tidak sempat "ditelan" oleh
  // buffer tap kartu (lihat listener HID di atas) — kalau tidak dikecualikan,
  // Enter nyasar itu langsung menutup modal lewat tombol simpan sebelum
  // penggunanya sempat memeriksa hasil pindaiannya.
  // Pada modal statis keduanya dimatikan: satu-satunya jalan keluar adalah
  // tombol Batal atau tombol simpan — persis yang diminta tim kasir, supaya
  // Enter nyasar di kotak Qty tidak menyimpan nota yang belum selesai.
  modalKeydown = (e) => {
    if (e.key === 'Escape') { if (statis) return; e.preventDefault(); closeModal(); return; }
    if (e.key !== 'Enter' || statis) return;
    const aktif = document.activeElement;
    if (root.contains(aktif) &&
      (aktif.tagName === 'TEXTAREA' || aktif.tagName === 'BUTTON' || aktif.dataset.scan === '1')) return;
    const simpan = $('#m-save');
    if (simpan) {
      // Sedang disabled = permintaan sebelumnya masih berjalan — jangan
      // ditutup lewat Batal, nanti hasilnya (berhasil/gagal) tidak kelihatan.
      if (!simpan.disabled) { e.preventDefault(); simpan.click(); }
      return;
    }
    const batal = $('#m-cancel');
    if (batal) { e.preventDefault(); batal.click(); }
  };
  document.addEventListener('keydown', modalKeydown);
}
function closeModal() {
  $('#modal-root').innerHTML = '';
  cardHandler = null;
  if (modalKeydown) { document.removeEventListener('keydown', modalKeydown); modalKeydown = null; }
}

// Palet grafik disetel ke warna tampilan admin (navy, emas redam, hijau).
// VIZ milik charts.js; diubah di sini karena berkas ini hanya dimuat halaman
// admin — kios dan portal wali punya pengaturannya sendiri.
Object.assign(VIZ, { s1: '#1A2856', s2: '#C9A227', s3: '#0F7A5F',
  grid: '#EFEBE2', axis: '#D6D0C2', ink: '#141B2D', muted: '#676D82' });
VIZ.kategori = [VIZ.s1, VIZ.s2, VIZ.s3];
VIZ.deret = [VIZ.s1, VIZ.s2, VIZ.s3, '#5B45B8', '#B4532A', '#2A7F9E'];

// ========================================================== state + boot
let USER = null;
let cardHandler = null;   // handler tap khusus (modal, dsb.)

/**
 * Menu bar. Dua bentuk baris yang boleh dipakai:
 *   ['id', 'Label']                     → tombol tunggal
 *   { grup: 'Label', anak: [ … ] }      → induk dengan submenu (buka saat disorot)
 *
 * Superadmin sempat punya sebelas tombol sejajar — melimpah ke baris kedua dan
 * mendorong tinggi header, dan sebelas pilihan setara juga tidak menolong siapa
 * pun mencari. Dikelompokkan menurut objek yang diurus: uang, orang, dagangan,
 * lalu laporan. Yang dipakai paling sering (Dashboard) tetap satu klik.
 *
 * Seluruh tombol anak TETAP ada di DOM walau submenunya tertutup — hanya
 * disembunyikan lewat CSS. Lencana jumlah pengajuan, penandaan tab aktif, dan
 * lompatan antar-tab semuanya mencari `#nav button[data-v=…]`, dan uji asap
 * antarmuka menekan tombol yang submenunya belum dibuka.
 */
const VIEWS = {
  superadmin: [
    ['dashboard', 'Dashboard'],
    { grup: 'Saldo & Top Up', anak: [['saldo', 'Saldo Santri'], ['massal', 'Topup Massal'],
                                     ['topup', 'Topup Ortu'], ['tabungan', 'Tabungan']] },
    { grup: 'Pemegang Kartu', anak: [['santri', 'Santri'], ['sdm', 'SDM & Personil']] },
    { grup: 'Kantin', anak: [['tenant', 'Tenant'], ['menu-ajuan', 'Menu Tenant'],
                             ['donasi', 'Donasi']] },
    { grup: 'Laporan', anak: [['analitik', 'Analitik'], ['riwayat', 'Riwayat']] },
    ['akun', 'Kelola Akun'],
  ],
  tenant: [
    ['kasir', 'Kasir'], ['menu', 'Menu'], ['dashboard', 'Dashboard'],
    { grup: 'Laporan', anak: [['analitik', 'Analitik'], ['riwayat', 'Riwayat']] },
  ],
  // IMBOS Mart memakai menu sendiri: ia menjual barang bersatuan dan berstok,
  // bukan menu masakan, jadi "Menu" diganti "Barang & Stok" dan ditambah
  // "Stok Opname". Pengajuan menu tidak ada di sini — Mart unit yayasan sendiri.
  tenant_mart: [
    ['kasir', 'Kasir'], ['mart-riwayat', 'Riwayat'], ['mart-piutang', 'Member & Piutang'],
    ['mart-beli', 'Sales & Pembelian'],
    ['mart-stok', 'Barang & Stok'], ['mart-opname', 'Stok Opname'],
    ['mart-spd', 'Closing Harian'],
    ['dashboard', 'Dashboard'],
  ],
  // Akun kasir serve-only: cuma melayani (jual, tutup kas, tahan keranjang,
  // koreksi riwayat, layani piutang) dan lihat Closing Harian — tidak
  // mengolah data (stok, pembelian, kelola member, dashboard). Pagar
  // sebenarnya di server (Controller::wajibAdminToko), ini cuma supaya
  // menunya tidak menampilkan tombol yang akan ditolak server.
  tenant_mart_kasir: [
    ['kasir', 'Kasir'], ['mart-riwayat', 'Riwayat'], ['mart-piutang', 'Member & Piutang'],
    ['mart-spd', 'Closing Harian'],
  ],
};

/**
 * Fitur minimal untuk BOLEH MEMBUKA tiap tab — cukup salah satu dimiliki.
 *
 * Ini semata supaya menu yang tidak berhak tidak usah digambar. Pagar yang
 * sebenarnya ada di server (middleware `izin:`): daftar di bawah hidup di
 * peramban, jadi siapa pun bisa mengubahnya, dan menyembunyikan tombol tidak
 * pernah menjadi pengamanan.
 */
const IZIN_TAB = {
  dashboard: ['dashboard.lihat', 'neraca.lihat', 'settings.lihat'],
  saldo: ['saldo.lihat'],
  massal: ['massal.lihat'],
  topup: ['topup_ortu.lihat'],
  tabungan: ['tabungan.lihat'],
  santri: ['santri.lihat'],
  sdm: ['sdm.lihat'],
  tenant: ['tenant.lihat'],
  'menu-ajuan': ['ajuan.lihat'],
  donasi: ['donasi.lihat'],
  analitik: ['analitik.lihat'],
  riwayat: ['riwayat.lihat'],
  akun: ['akun.lihat', 'akses.kelola'],
};

/** Apakah akun yang sedang masuk memegang fitur ini? */
const boleh = (kode) => !!USER?.izin?.includes(kode);

/**
 * Akun kasir Mart serve-only — cuma melayani, tidak mengolah data.
 * Tombol kelola yang disembunyikan lewat ini murni kenyamanan tampilan;
 * pagar sebenarnya di server (Controller::wajibAdminToko).
 */
const martKasirSajaMelayani = () => USER?.role === 'tenant' && USER?.peran_tenant === 'kasir';

/**
 * Apakah tab ini boleh digambar? Tab tanpa aturan (milik kasir) selalu boleh.
 *
 * Penyaringan izin HANYA berlaku untuk superadmin: kategori akses adalah alat
 * membagi pekerjaan antar staf admin, dan `Izin::milik()` di server memang
 * mengembalikan daftar kosong untuk peran lain. Tanpa pengecualian ini, daftar
 * kosong itu terbaca sebagai "tidak berhak apa pun" dan kasir tenant kehilangan
 * Dashboard serta Riwayat miliknya sendiri.
 */
const bolehTab = (id) => USER?.role !== 'superadmin'
  || !IZIN_TAB[id] || IZIN_TAB[id].some(boleh);

/** Kunci menu: Mart punya susunan tab sendiri walau perannya tetap `tenant`. */
const kunciMenu = (role) => role === 'tenant' && USER?.tenant_mode === 'mart'
  ? (USER?.peran_tenant === 'kasir' ? 'tenant_mart_kasir' : 'tenant_mart') : role;

/**
 * Menu bar setelah disaring izin. Grup yang seluruh anaknya tertutup ikut
 * hilang — induk kosong yang tetap tergambar hanya akan terbuka ke ruang hampa.
 */
function tabTampil(role) {
  return (VIEWS[kunciMenu(role)] || []).map((t) => {
    if (!t.grup) return bolehTab(t[0]) ? t : null;
    const anak = t.anak.filter(([id]) => bolehTab(id));
    return anak.length ? { grup: t.grup, anak } : null;
  }).filter(Boolean);
}

/** Semua tab sebagai daftar rata, tanpa peduli ia di dalam grup atau tidak. */
const tabDatar = (role) => tabTampil(role).flatMap((t) => (t.grup ? t.anak : [t]));

/**
 * Tandai tab yang sedang terbuka — termasuk menyalakan induknya supaya, saat
 * submenu tertutup, masih terlihat kelompok mana yang sedang dibuka.
 */
function tandaiAktif(view) {
  document.querySelectorAll('#nav button[data-v]').forEach((b) =>
    b.classList.toggle('active', b.dataset.v === view));
  document.querySelectorAll('#nav .nav-grup').forEach((g) =>
    g.classList.toggle('anak-aktif',
      [...g.querySelectorAll('button[data-v]')].some((b) => b.dataset.v === view)));
}

const KATEGORI_LABEL = { makanan: 'Makanan', non_makanan: 'Non-makanan' };
const lencanaKategori = (k) => k === 'non_makanan'
  ? '<span class="badge kat-nm">non-makanan</span>' : '<span class="badge kat-mk">makanan</span>';
let activeView = null;

function hideBoot() {
  const b = $('#boot');
  if (b) b.classList.add('hidden');
}

function showLogin() {
  hideBoot();
  $('#login').classList.remove('hidden');
  $('#app').classList.add('hidden');
  USER = null;
}

// Tab aktif disimpan di hash URL → refresh kembali ke tab yang sama,
// dan tombol back/forward peramban ikut bekerja.
function tabDariHash() {
  const h = (location.hash || '').replace(/^#/, '');
  const tabs = tabDatar(USER.role);
  // Kategori akses bisa saja tidak menyisakan satu tab pun. Lebih baik layar
  // kosong yang menjelaskan diri daripada galat yang tidak terbaca siapa pun.
  if (!tabs.length) return '';
  return tabs.some(([id]) => id === h) ? h : tabs[0][0];
}

function showApp() {
  hideBoot();
  // Daftar golongan dimuat sekali di sini, bukan hanya saat tabelnya digambar:
  // tombol "+ Tambah" bisa ditekan sebelum daftar pertama selesai dimuat, dan
  // formulir dengan pilihan golongan kosong akan ditolak server tanpa sebab
  // yang bisa dimengerti admin.
  if (USER.role === 'superadmin') {
    api('/api/golongan').then((d) => { GOLONGAN = d.golongan; }).catch(() => {});
  }
  $('#login').classList.add('hidden');
  $('#app').classList.remove('hidden');
  $('#hd-title').textContent = USER.role === 'tenant' ? `IMBOS Cashless — ${USER.tenant_nama}` : 'IMBOS Cashless';
  // Label peran. "(kasir)" dulu dipakai untuk SEMUA akun tenant, sejak
  // sebelum ada akun kasir serve-only. Di unit Mart itu jadi menyesatkan:
  // admin toko dan kasir serve-only terbaca sama persis padahal haknya jauh
  // berbeda, dan justru di layar itulah bedanya perlu kelihatan.
  $('#hd-user').textContent = USER.role === 'tenant'
    ? `${USER.nama} (${USER.tenant_mode === 'mart'
        ? (USER.peran_tenant === 'kasir' ? 'kasir' : 'admin toko') : 'kasir'})`
    : `${USER.nama} · ${USER.kategori_nama || 'Akses Penuh'}`;
  const awal = tabDariHash();
  gambarNav(tabTampil(USER.role), awal);
  location.hash = awal;
  renderView(awal);

  // Pemberitahuan hanya untuk admin: kasir tenant tidak memutuskan apa pun
  // di antrean ini, dan layar kios tidak pernah ditunggui orang.
  if (USER.role === 'superadmin') {
    const tombol = $('#hd-suara');
    tombol.classList.remove('hidden');
    tombol.onclick = () => bukaPengaturanNotif();
    gambarTombolSuara();
    mulaiNotif();
  }
}

/** Satu tombol tab. Label di-escape: nama grup & tab berasal dari kode, tapi
 *  aturannya sama di seluruh berkas ini supaya tidak jadi celah kalau kelak
 *  labelnya datang dari pengaturan. */
/** Ikon garis per tab — murni tampilan; tab tanpa ikon tetap berfungsi. */
const IKON_TAB = {
  dashboard: '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
  saldo: '<path d="M3 7a2 2 0 0 1 2-2h13v4"/><path d="M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2"/><circle cx="16" cy="14.5" r="1.2"/>',
  massal: '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
  topup: '<rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18h2"/>',
  // Tangan + koin Rp dari rujukan pemilik sistem, divektorkan apa adanya.
  // svg bersarang (viewBox &amp; fill sendiri) menembus fill:none milik svg.ik
  // pembungkus — bukan rescaling tangan yang rawan keliru.
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
  santri: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.5 3.3-5.5 6.5-5.5s5.7 2 6.5 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.8c1.9.7 3.1 2.4 3.5 5.2"/>',
  sdm: '<rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="9" cy="11" r="2"/><path d="M6 16c.5-1.5 1.6-2.2 3-2.2s2.5.7 3 2.2M15 10h3M15 13.5h3"/>',
  tenant: '<path d="M4 9.5 5.5 4h13L20 9.5"/><path d="M4 9.5a2.7 2.7 0 0 0 5.3 0 2.7 2.7 0 0 0 5.4 0 2.7 2.7 0 0 0 5.3 0"/><path d="M5 12v8h14v-8M10 20v-5h4v5"/>',
  'menu-ajuan': '<path d="M3 12V4h8l9.5 9.5-8 8z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
  donasi: '<path d="M12 20s-7.5-4.5-7.5-10A4.3 4.3 0 0 1 12 7.3 4.3 4.3 0 0 1 19.5 10c0 5.5-7.5 10-7.5 10z"/>',
  analitik: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
  riwayat: '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
  akun: '<path d="M12 3 4.5 6v5.5c0 4.5 3.2 8 7.5 9.5 4.3-1.5 7.5-5 7.5-9.5V6z"/><path d="m9 12 2 2 4-4"/>',
  kasir: '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6.5 15h4"/>',
  menu: '<path d="M4 6h16M4 12h16M4 18h10"/>',
};
const tombolNav = ([id, label], aktif) =>
  `<button data-v="${id}" class="${id === aktif ? 'active' : ''}">${IKON_TAB[id]
    ? `<svg class="ik" viewBox="0 0 24 24" aria-hidden="true">${IKON_TAB[id]}</svg>` : ''}${esc(label)}</button>`;

function gambarNav(tabs, aktif) {
  $('#nav').innerHTML = tabs.map((t) => {
    if (!t.grup) return tombolNav(t, aktif);
    const anakAktif = t.anak.some(([id]) => id === aktif);
    return `<div class="nav-grup${anakAktif ? ' anak-aktif' : ''}">
      <button type="button" class="nav-induk" aria-haspopup="true" aria-expanded="false">
        ${esc(t.grup)}<span class="nav-panah" aria-hidden="true">▾</span></button>
      <div class="nav-anak">${t.anak.map((a) => tombolNav(a, aktif)).join('')}</div>
    </div>`;
  }).join('');

  document.querySelectorAll('#nav button[data-v]').forEach((b) => {
    b.onclick = () => {
      tandaiAktif(b.dataset.v);
      tutupSemuaGrup();
      tutupMenuPonsel();
      location.hash = b.dataset.v;      // hashchange di bawah berhenti sendiri (sudah sama)
      renderView(b.dataset.v);
    };
  });

  // Menyorot sudah cukup untuk membuka (diurus CSS). Klik tetap dipasang karena
  // di layar sentuh tidak ada "sorot" sama sekali — tanpa ini submenu mustahil
  // dibuka dari tablet.
  document.querySelectorAll('#nav .nav-induk').forEach((ind) => {
    ind.onclick = (e) => {
      e.stopPropagation();
      const grup = ind.parentElement;
      const buka = !grup.classList.contains('buka');
      tutupSemuaGrup();
      grup.classList.toggle('buka', buka);
      ind.setAttribute('aria-expanded', String(buka));
    };
  });
}

/**
 * Menu ponsel di balik tombol ☰.
 *
 * Kelas `.buka` hanya berpengaruh di bawah 760px (lihat style.css); di layar
 * lebar menu bar selalu tergambar, jadi tombolnya tersembunyi dan kelas ini
 * tidak mengubah apa pun. Menutup menu setiap kali tab dipilih penting: tanpa
 * itu, daftar tegak tetap menutupi isi halaman yang baru saja dibuka.
 */
function tutupMenuPonsel() {
  $('#nav')?.classList.remove('buka');
  $('#btn-menu')?.setAttribute('aria-expanded', 'false');
}

$('#btn-menu').onclick = (e) => {
  e.stopPropagation();
  const nav = $('#nav');
  const buka = !nav.classList.contains('buka');
  nav.classList.toggle('buka', buka);
  $('#btn-menu').setAttribute('aria-expanded', String(buka));
};

function tutupSemuaGrup() {
  document.querySelectorAll('#nav .nav-grup.buka').forEach((g) => {
    g.classList.remove('buka');
    g.querySelector('.nav-induk')?.setAttribute('aria-expanded', 'false');
  });
}
// Ketuk di luar menu menutup submenu yang terbuka lewat klik; Esc juga, supaya
// papan ketik tidak terjebak di menu yang menggantung.
addEventListener('click', (e) => {
  tutupSemuaGrup();
  // Ketukan DI DALAM menu tidak menutupnya — kalau tidak, menu tertutup
  // sebelum jari sempat mencapai tombol tab yang dituju.
  if (!e.target.closest('#nav')) tutupMenuPonsel();
});
addEventListener('keydown', (e) => {
  if (e.key === 'Escape') { tutupSemuaGrup(); tutupMenuPonsel(); }
});

addEventListener('hashchange', () => {
  if (!USER) return;
  const h = (location.hash || '').replace(/^#/, '');
  if (!h || h === activeView) return;                       // dipicu klik tab sendiri
  if (!tabDatar(USER.role).some(([id]) => id === h)) return;
  tandaiAktif(h);
  renderView(h);
});

/**
 * Kepala halaman: "KELOMPOK / Judul" dan tanggal hari ini (WIB). Murni rupa —
 * diturunkan dari daftar menu yang sama dengan bilah samping, jadi tidak ada
 * nama halaman yang ditulis dua kali. Layar kasir tidak memakainya: di sana
 * setiap piksel tinggi layar lebih berguna untuk daftar menu.
 */
const NAMA_HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
const NAMA_BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus',
  'September', 'Oktober', 'November', 'Desember'];
function gambarKepala(name) {
  const kotak = $('#kepala-halaman');
  if (!kotak) return;
  let grup = '', judul = '';
  for (const t of tabTampil(USER?.role)) {
    const cocok = t.grup ? t.anak.find(([id]) => id === name) : (t[0] === name ? t : null);
    if (cocok) { grup = t.grup || ''; judul = cocok[1]; break; }
  }
  kotak.classList.toggle('hidden', !judul || name === 'kasir');
  $('#kh-grup').textContent = grup;
  $('#kh-judul').textContent = judul;
  const d = new Date(Date.now() + 7 * 3600 * 1000);
  $('#kh-tanggal').textContent =
    `${NAMA_HARI[d.getUTCDay()]}, ${d.getUTCDate()} ${NAMA_BULAN[d.getUTCMonth()]} ${d.getUTCFullYear()}`;
}

function renderView(name) {
  activeView = name;
  cardHandler = null;
  gambarKepala(name);
  if (!name) {
    $('#main').innerHTML = `<div class="panel"><h2>Belum ada menu yang terbuka</h2>
      <p class="muted">Kategori akses <b>${esc(USER?.kategori_nama || '—')}</b> belum diberi satu pun
        hak membuka menu. Mintalah pemegang akun dengan hak <b>Mengelola kategori akses</b>
        untuk menyalakan fiturnya.</p></div>`;
    return;
  }
  const render = {
    kasir: USER?.tenant_mode === 'mart' ? martView : kasirView, menu: menuView, riwayat: riwayatView,
    'mart-stok': martStokView, 'mart-opname': martOpnameView,
    'mart-riwayat': martRiwayatView, 'mart-piutang': martPiutangView,
    'mart-beli': martBeliView, 'mart-spd': martSpdView,
    dashboard: USER?.role !== 'tenant' ? dashSAView
      : (USER?.tenant_mode === 'mart' ? martDashView : dashTenantView),
    saldo: saldoView, santri: siswaView, tenant: tenantView, analitik: analitikView,
    donasi: donasiView, topup: topupOrtuView, 'menu-ajuan': menuAjuanView, sdm: sdmView,
    tabungan: tabunganView,
    massal: massalView, akun: akunView,
  }[name];
  if (render) render($('#main'));
}

$('#btn-login').onclick = doLogin;
$('#login-pass').addEventListener('keydown', (e) => { if (e.key === 'Enter') doLogin(); });
async function doLogin() {
  try {
    const d = await api('/api/login', { method: 'POST', body: { username: $('#login-user').value, password: $('#login-pass').value } });
    USER = d.user;
    $('#login-pass').value = '';
    showApp();
  } catch (e) { toast(e.message, 'err'); }
}
$('#btn-logout').onclick = async () => {
  await api('/api/logout', { method: 'POST' }).catch(() => {});
  history.replaceState(null, '', location.pathname);   // buang hash tab
  // Pengecekan berkala dihentikan DI SINI, bukan dibiarkan mati sendiri:
  // sesinya sudah tidak ada, jadi tiap 30 detik ia hanya akan menghasilkan
  // 401 sampai tabnya ditutup.
  clearInterval(notifTimer);
  notifTimer = null;
  notifTerakhir = null;
  $('#hd-suara').classList.add('hidden');
  tutupMenuPonsel();
  showLogin();
};

// ========================================================== HID card capture
// Reader keyboard-wedge mengetik UID + Enter super cepat (jeda antar-karakter < 100ms).
let kbBuf = '', kbLast = 0;
let lastTap = { uid: '', at: 0 };
/*
 * Dipasang di FASE TANGKAP, bukan fase gelembung — dan itu yang membetulkan
 * satu pindaian yang terbaca dua kali.
 *
 * Dulu pendengar ini duduk di fase gelembung, jadi ia berjalan SESUDAH
 * pendengar milik kotak isian yang sedang disorot. Ketika kasir Mart memindai
 * barang sementara kursornya ada di kotak cari — yang justru paling sering
 * terjadi — tombol Enter dari pemindai diproses dua kali: sekali oleh kotak
 * cari, sekali lagi oleh penangkap ini. Satu pindaian, dua barang masuk
 * keranjang, dan tidak ada yang kelihatan salah di layar.
 *
 * Di fase tangkap ia berjalan lebih dulu, dan begitu yakin ketikan tadi
 * memang berasal dari pemindai (deretan cepat, panjang >= 6), ia menelan
 * kejadiannya: stopPropagation membuat kotak isian tidak pernah ikut
 * menanganinya. Mengetik biasa tidak terpengaruh — jeda antar-huruf manusia
 * jauh di atas 100 ms, sehingga kbBuf keburu dikosongkan dan Enter-nya
 * diteruskan apa adanya ke kotak isian.
 */
document.addEventListener('keydown', (e) => {
  if (!USER) return;
  const now = performance.now();
  if (now - kbLast > 100) kbBuf = '';
  kbLast = now;
  if (e.key === 'Enter') {
    const uid = normUid(kbBuf);
    kbBuf = '';
    if (uid.length >= 6) {
      e.preventDefault();
      e.stopPropagation();
      onCardTapped(uid);
    }
  } else if (e.key.length === 1) {
    kbBuf += e.key;
  }
}, true);

function onCardTapped(uid) {
  const now = Date.now();
  /*
   * Rem anti-baca-ganda: reader RFID kerap melaporkan satu tap dua kali, dan
   * di layar kasir/saldo itu berarti satu kartu terbaca dobel.
   *
   * TAPI di kasir Mart, memindai barang yang sama tiga kali berturut-turut
   * adalah hal paling biasa (beli 3 bungkus). Karena itu remnya tidak dicabut
   * — hanya DIPERPENDEK: laporan ganda dari alat tiba dalam puluhan milidetik,
   * sedangkan tangan manusia tidak mungkin memindai barang yang sama lebih
   * cepat dari sekitar setengah detik. Jendela di antaranya memisahkan
   * keduanya tanpa menghilangkan satu pun pindaian yang disengaja.
   *
   * Sempat rem ini dicabut habis untuk Mart, dan akibatnya tepat seperti yang
   * dulu ingin dicegah: satu pindaian jadi dua barang.
   */
  const rem = cardHandler && cardHandler.remMs !== undefined ? cardHandler.remMs : 2000;
  if (rem > 0 && uid === lastTap.uid && now - lastTap.at < rem) return;
  lastTap = { uid, at: now };
  if (cardHandler) { cardHandler(uid); return; }
  if (USER.role === 'tenant' && activeView === 'kasir') kasirLookup(uid);
  else if (USER.role === 'superadmin' && activeView === 'saldo') saldoLookup(uid);
  else if (USER.role === 'superadmin' && activeView === 'siswa') siswaLookupCard(uid);
}

// ╔══════════════════════════════════════════════════════════╗
// ║ TENANT: KASIR                                             ║
// ╚══════════════════════════════════════════════════════════╝
let kasir = { siswa: null, tapToken: null, cart: [], produk: [] };

function kasirView(el) {
  // tenant = keterangan tenant yang login (dipakai untuk tahu apakah ini koperasi,
  // yang boleh menjual barang non-makanan dengan jatah harian tersendiri).
  kasir = { siswa: null, tapToken: null, cart: [], produk: [], tenant: null };
  el.innerHTML = `
  <div class="row">
    <div class="panel">
      <h2>Kartu Santri</h2>
      <div class="tapzone" id="tapzone">
        <div class="big">💳</div>
        Tempelkan kartu untuk memulai transaksi<br>
        <span class="muted">satu tap = satu transaksi</span>
      </div>
      <div style="display:flex; gap:8px; margin-top:10px">
        <input id="manual-uid" placeholder="atau ketik UID manual, mis. 04A1B2C3">
        <button class="linkbtn" id="btn-manual" style="flex:none">Cari</button>
      </div>
      <div id="stu-box"></div>
    </div>
    <div class="panel">
      <h2>Belanja</h2>
      <div class="grid" id="produk-grid"><span class="muted">memuat menu…</span></div>
      <p class="muted hidden" id="mode-bebas-info" style="margin:0 0 4px">
        🛒 Mode tanpa menu — ketik barang yang dibeli beserta harganya.</p>
      <div id="manual-box">
        <input id="manual-desk" maxlength="60" autocomplete="off" style="margin-top:12px"
          placeholder="Deskripsi barang (opsional), mis. Sabun mandi">
        <div style="display:flex; gap:8px; margin-top:8px">
          ${inputUang({ id: 'manual-harga', placeholder: 'Input manual (Rp)' })}
          <select id="manual-kat" class="hidden" style="flex:none; width:auto">
            <option value="makanan">Makanan</option>
            <option value="non_makanan">Non-makanan</option>
          </select>
          <button class="linkbtn" id="btn-add-manual" style="flex:none">+ Tambah</button>
        </div>
      </div>
      <div class="cart" id="cart-box"></div>
      <div class="total"><span>Total</span><span class="rp" id="cart-total">Rp0</span></div>
      <button class="paybtn" id="btn-bayar" disabled>Bayar</button>
    </div>
  </div>`;

  api('/api/produk').then((d) => {
    kasir.produk = d.produk.filter((p) => p.aktif);
    kasir.tenant = d.tenant || null;
    // Pilihan kategori pada input manual hanya berguna di koperasi; di kantin
    // biasa semuanya makanan dan server pun menolak yang lain.
    $('#manual-kat').classList.toggle('hidden', kasir.tenant?.jenis !== 'koperasi');
    // Mode bebas: daftar menu disembunyikan dan deskripsi menjadi wajib —
    // server pun menolak barang tanpa deskripsi di mode ini.
    const bebas = kasir.tenant?.mode_kasir === 'bebas';
    $('#produk-grid').classList.toggle('hidden', bebas);
    $('#mode-bebas-info').classList.toggle('hidden', !bebas);
    $('#manual-desk').placeholder = bebas
      ? 'Barang yang dibeli (wajib), mis. Sabun mandi + sikat gigi'
      : 'Deskripsi barang (opsional), mis. Sabun mandi';
    $('#manual-harga').placeholder = bebas ? 'Harga (Rp)' : 'Input manual (Rp)';
    // Sakelar admin: tanpa input manual, kasir hanya bisa menjual menu yang
    // sudah disetujui. Yang menolaknya tetap server — ini semata supaya kasir
    // tidak mengetik nominal yang akan ditolak sedetik kemudian.
    $('#manual-box').classList.toggle('hidden', !bebas && kasir.tenant?.input_manual === false);
    $('#produk-grid').innerHTML = kasir.produk.map((p) =>
      `<button data-id="${p.id}"><b>${esc(p.nama)}</b><span class="muted rp">${rp(p.harga)}</span>` +
      (p.kategori === 'non_makanan' ? '<span class="katdot" title="non-makanan">non-makanan</span>' : '') +
      '</button>').join('')
      || '<span class="muted">Belum ada menu — tambahkan di tab Menu</span>';
    document.querySelectorAll('#produk-grid button').forEach((b) => {
      b.onclick = () => {
        const p = kasir.produk.find((x) => x.id === Number(b.dataset.id));
        // produk_id ikut dikirim: harga & kategori yang dipakai server diambil
        // dari basis data, bukan dari angka yang kebetulan ada di layar ini.
        if (p) { kasir.cart.push({ produk_id: p.id, nama: p.nama, harga: p.harga, kategori: p.kategori }); renderCart(); }
      };
    });
    renderCart();
  });

  $('#btn-manual').onclick = () => { const uid = normUid($('#manual-uid').value); if (uid) onCardTapped(uid); };
  $('#manual-uid').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('#btn-manual').click(); } });
  $('#btn-add-manual').onclick = () => {
    const desk = $('#manual-desk').value.replace(/\s+/g, ' ').trim();
    const h = nilaiUang('#manual-harga');
    if (kasir.tenant?.mode_kasir === 'bebas' && desk.length < 3) {
      toast('Tulis dulu barang yang dibeli (minimal 3 huruf)', 'err');
      $('#manual-desk').focus();
      return;
    }
    if (!h || h <= 0) { toast('Isi nominal manual dulu', 'err'); $('#manual-harga').focus(); return; }
    const kategori = kasir.tenant?.jenis === 'koperasi' ? $('#manual-kat').value : 'makanan';
    kasir.cart.push({ manual: true, nama: desk || 'Input manual', harga: h, kategori });
    $('#manual-desk').value = '';
    $('#manual-harga').value = '';
    $('#manual-desk').focus();
    renderCart();
  };
  // Enter di kedua kotak = Tambah: kasir mengetik deskripsi, harga, Enter,
  // lalu langsung barang berikutnya tanpa menyentuh tetikus.
  $('#manual-desk').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); $('#manual-harga').focus(); }
  });
  $('#manual-harga').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); $('#btn-add-manual').click(); }
  });
  $('#btn-bayar').onclick = doBayar;
  renderCart();
}

async function kasirLookup(uid) {
  const z = $('#tapzone');
  if (z) { z.classList.add('pulse'); setTimeout(() => z.classList.remove('pulse'), 600); }
  try {
    const d = await api('/api/card-lookup', { method: 'POST', body: { uid } });
    if (!d.siswa) {
      kasir.siswa = null; kasir.tapToken = null;
      $('#stu-box').innerHTML = `
        <div class="stucard" style="border-color:var(--warn)">
          <div class="avatar" style="background:var(--warn)">?</div>
          <div><b>Kartu belum terdaftar</b><br>
          <span class="muted">UID <code>${esc(uid)}</code> — daftarkan lewat akun superadmin</span></div>
        </div>`;
      updatePayBtn();
      return;
    }
    kasir.siswa = d.siswa;
    kasir.tapToken = d.tap_token;
    renderKasirSiswa(d.smart
      ? `💡 Saldo baru saja terisi ulang otomatis <b>${rp(d.smart.isi)}</b> dari saldo titipan.`
      : '');
    updatePayBtn();
  } catch (e) { toast(e.message, 'err'); }
}

function renderKasirSiswa(pesan) {
  const s = kasir.siswa;
  $('#stu-box').innerHTML = `
    <div class="stucard">
      ${avatarHtml(s)}
      <div>
        <b>${esc(s.nama)}</b><br>
        <span class="muted">${esc(s.kelas)} · NIS ${esc(s.nis)}</span><br>
        <span class="badge ${s.status}">${s.status.toUpperCase()}</span>
        ${kasir.tapToken ? '' : '<span class="badge" style="background:#fdf1dc;color:var(--warn)">TAP LAGI UNTUK TRANSAKSI BARU</span>'}
      </div>
      <div class="saldo"><span class="muted">Saldo</span><br><b class="rp">${rp(s.saldo)}</b>
        ${s.saldo_titipan > 0
          // Titipan tidak bisa dibelanjakan, tapi kasir perlu tahu uangnya ada:
          // santri bersaldo tipis bisa diarahkan ke admin, bukan disuruh pulang.
          ? `<br><span class="muted" style="font-size:11px">titipan ${rp(s.saldo_titipan)}</span>` : ''}
      </div>
    </div>
    ${pesan ? `<p class="muted" style="margin:8px 0 0">${pesan}</p>` : ''}`;
}

function renderCart() {
  const box = $('#cart-box');
  if (!box) return;
  box.innerHTML = kasir.cart.length
    ? kasir.cart.map((p, i) =>
        `<div class="cart-line"><span>${esc(p.nama)}${p.kategori === 'non_makanan'
          ? ' <span class="katdot">non-makanan</span>' : ''}</span>
         <span><span class="rp">${rp(p.harga)}</span> <button data-i="${i}">✕</button></span></div>`).join('')
    : `<div class="muted">Keranjang kosong — klik ${
        kasir.tenant?.mode_kasir !== 'bebas' && kasir.tenant?.input_manual === false
          ? 'menu di atas' : 'menu atau input manual'}</div>`;
  box.querySelectorAll('button').forEach((b) => {
    b.onclick = () => { kasir.cart.splice(Number(b.dataset.i), 1); renderCart(); };
  });
  // Di koperasi, dua jatah harian berjalan terpisah — kasir perlu melihat
  // pembagiannya sebelum menekan Bayar, bukan cuma totalnya.
  const nm = cartNonMakanan();
  $('#cart-total').innerHTML = nm > 0
    ? `${rp(cartTotal())}<br><span class="muted" style="font-size:12px">
       makanan ${rp(cartTotal() - nm)} · non-makanan ${rp(nm)}</span>`
    : rp(cartTotal());
  updatePayBtn();
}
const cartTotal = () => kasir.cart.reduce((a, p) => a + p.harga, 0);
const cartNonMakanan = () =>
  kasir.cart.reduce((a, p) => a + (p.kategori === 'non_makanan' ? p.harga : 0), 0);

function updatePayBtn() {
  const b = $('#btn-bayar');
  if (!b) return;
  b.disabled = !(kasir.siswa && kasir.tapToken && kasir.siswa.status === 'aktif' && kasir.cart.length);
}

async function doBayar() {
  const btn = $('#btn-bayar');
  btn.disabled = true;
  const txId = 'tx-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
  try {
    const d = await api('/api/beli', { method: 'POST', body: {
      siswa_id: kasir.siswa.id,
      jumlah: cartTotal(),
      // Rincian dikirim apa adanya; server menghitung ulang harga dari basis data
      // dan menyusun keterangannya sendiri.
      items: kasir.cart.map((p) => p.manual
        ? { manual: true, harga: p.harga, kategori: p.kategori, nama: p.nama }
        : { produk_id: p.produk_id }),
      tap_token: kasir.tapToken,
      tx_id: txId,
    }});
    const pesanSmart = d.smart
      ? ` 💡 Saldo terisi ulang otomatis ${rp(d.smart.isi)} dari titipan (sisa titipan ${rp(d.smart.sisa_titipan)}).`
      : '';
    toast(`✔ Berhasil — sisa saldo ${rp(d.siswa.saldo)}.${pesanSmart}`, 'ok');
    // Satu tap = satu transaksi: begitu transaksinya selesai, identitas &
    // saldo santri ini TIDAK ditampilkan lagi — layar kembali kosong seperti
    // sebelum kartu ditap. Membiarkannya tertampil membuka peluang kasir
    // (sengaja atau tidak) memproses transaksi susulan atas nama santri yang
    // sama tanpa tap ulang kartunya.
    kasir.siswa = null;
    kasir.tapToken = null;
    kasir.cart = [];
    $('#stu-box').innerHTML = '';
    renderCart();
    updatePayBtn();
  } catch (e) {
    toast(e.message, 'err');
    updatePayBtn();               // token masih hidup jika transaksi gagal
  }
}

// ╔══════════════════════════════════════════════════════════╗
// ║ TENANT: IMBOS MART — kasir, stok, opname, laporan         ║
// ╚══════════════════════════════════════════════════════════╝
/*
 * Satu alat, dua makna: pemindai barcode dan reader RFID sama-sama "mengetik"
 * ke halaman lalu menekan Enter, jadi tidak bisa dibedakan dari bentuk
 * angkanya (UID kartu di sini pun angka semua). Yang membedakan KEADAAN
 * layar: selama keranjang diisi, pindaian dibaca sebagai barcode barang;
 * hanya setelah kasir memilih "Kartu", pindaian berikutnya dibaca sebagai
 * kartu. Jadi salah baca tidak pernah menghanguskan keranjang.
 */
let mart = null;

/** Unduh tabel apa pun sebagai CSV — dibuka Excel tanpa perlu alat tambahan. */
function unduhCsv(namaBerkas, judul, baris) {
  const sel = (v) => {
    const t = String(v ?? '');
    return /[",;\n]/.test(t) ? '"' + t.replace(/"/g, '""') + '"' : t;
  };
  // BOM supaya Excel membaca huruf beraksen & rupiah dengan benar.
  const isi = '﻿' + [judul, ...baris].map((b) => b.map(sel).join(';')).join('\r\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([isi], { type: 'text/csv;charset=utf-8' }));
  a.download = namaBerkas;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

/** Cetak sepotong HTML lewat jendela terpisah (struk 58mm & form opname). */
function cetakLembar(judul, gaya, isi) {
  const w = window.open('', '_blank', 'width=420,height=640');
  if (!w) { toast('Izinkan pop-up untuk mencetak', 'err'); return; }
  w.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>${esc(judul)}</title>
    <style>${gaya}</style></head><body>${isi}</body></html>`);
  w.document.close();
  w.focus();
  // Jeda kecil: sebagian peramban mencetak halaman kosong kalau print()
  // dipanggil sebelum isinya selesai ditata.
  setTimeout(() => { w.print(); }, 350);
}

// Hijau/kuning/merah: >ambang aman, ≤ambang menipis, 0 habis. Warna saja tidak
// cukup bagi yang sulit membedakannya, jadi angka stoknya selalu ikut tertulis.
const MART_STATUS = {
  aman: ['aktif', 'aman'], menipis: ['menipis', 'menipis'],
  habis: ['blokir', 'habis'], tak_dilacak: ['netral', 'tanpa stok'],
};
function martBadgeStok(p, angka) {
  const [kelas, teks] = MART_STATUS[p.status] || ['netral', '—'];
  // Di etalase kasir yang dibutuhkan ANGKANYA ("masih cukup untuk tiga lagi?");
  // di tabel stok angka itu sudah punya kolom sendiri, jadi lencananya
  // menyebutkan statusnya supaya tidak mengulang yang sudah terbaca.
  return `<span class="badge ${kelas}">${angka && p.stok !== null ? `Stok: ${p.stok}` : teks}</span>`;
}

// ------------------------------------------------------------------ KASIR

function martView(el) {
  mart = { cart: [], metode: null, siswa: null, tapToken: null, uang: 0, diskon: 0,
    // Struk TIDAK dicetak secara bawaan: mayoritas pembeli Mart adalah santri
    // yang membayar pakai kartu dan tidak memerlukan kertas. Kasir mencentangnya
    // sendiri saat ada pembeli luar yang memintanya.
    info: null, barcodeBaru: null, produk: [], struk: false,
    pilih: null, member: [], memberId: null };
  el.innerHTML = `
  <div class="row mart-pos">
    <div class="panel">
      <div class="kat-head"><h2 style="margin:0">Etalase</h2>
        <span style="display:flex; gap:10px; align-items:baseline">
          <span id="mart-printer"></span>
          <button class="linkbtn" id="mart-bantuan" title="pintasan papan ketik (F1)">⌨ F1</button>
          <button class="linkbtn" id="mart-kas">🧾 Tutup kas harian</button>
        </span></div>
      <div style="display:flex; gap:8px; margin-bottom:10px">
        <input id="mart-q" placeholder="Cari nama barang, SKU, atau scan barcode…" autocomplete="off">
        <button class="go" id="mart-cari" style="flex:none">Cari</button>
        <button class="linkbtn hidden" id="mart-manual-toggle" style="flex:none">+ Input Manual</button>
      </div>
      <div id="mart-manual-box" class="hidden" style="display:flex; gap:8px; margin-bottom:10px">
        <input id="mart-manual-desk" maxlength="60" autocomplete="off" placeholder="Nama barang (mis. kerupuk curah)">
        ${inputUang({ id: 'mart-manual-harga', placeholder: 'Harga satuan (Rp)' })}
        <select id="mart-manual-kat" class="hidden" style="flex:none; width:auto">
          <option value="makanan">Makanan</option>
          <option value="non_makanan">Non-makanan</option>
        </select>
        <button class="linkbtn" id="mart-manual-tambah" style="flex:none">+ Tambah</button>
      </div>
      <div id="mart-hasil"></div>
      <div class="grid mart-katalog" id="mart-katalog"><span class="muted">memuat barang…</span></div>
    </div>
    <div class="panel">
      <div class="kat-head"><h2 style="margin:0">Keranjang <span class="muted" id="mart-n"></span></h2>
        <span style="display:flex; gap:10px; align-items:baseline">
          <button class="linkbtn" id="mart-tahan" title="tahan transaksi (F8)">⏸ Tahan</button>
          <button class="linkbtn" id="mart-tertahan"></button>
          <button class="linkbtn danger" id="mart-kosong">Kosongkan</button>
        </span></div>
      <div id="mart-cart"></div>
      <div id="mart-qty-ketik" class="muted hidden"
        style="margin-top:6px; font-size:12.5px"></div>
      <div id="mart-hitung"></div>
      <div class="modeswitch" id="mart-metode" style="margin-top:10px">
        <button id="mart-m-kartu">💳 Kartu<br><span class="muted" style="font-size:11px">santri & pegawai</span></button>
        <button id="mart-m-tunai">💵 Tunai<br><span class="muted" style="font-size:11px">umum</span></button>
        <button id="mart-m-transfer">🏦 Transfer<br><span class="muted" style="font-size:11px">bank / QRIS</span></button>
        <button id="mart-m-hutang">📒 Hutang<br><span class="muted" style="font-size:11px">member</span></button>
      </div>
      <div id="mart-bayar"></div>
      <div id="mart-ringkas" class="muted" style="margin-top:12px"></div>
    </div>
  </div>`;

  // Rem dipendekkan, bukan dicabut: memindai barang yang sama berkali-kali
  // memang disengaja, tetapi dua laporan dari satu pindaian tidak.
  martScan.remMs = 400;
  cardHandler = martScan;

  $('#mart-cari').onclick = () => { const q = $('#mart-q').value.trim(); if (q) martCari(q, false); };
  $('#mart-q').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); $('#mart-cari').click(); }
  });
  $('#mart-q').addEventListener('input', debounce(() => martGambarKatalog(), 250));
  // Tersembunyi sampai diminta — supaya etalase barcode tidak selalu
  // menampilkan jalan pintas yang bisa dipakai menginput harga sembarangan.
  $('#mart-manual-toggle').onclick = () => $('#mart-manual-box').classList.toggle('hidden');
  $('#mart-manual-desk').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); $('#mart-manual-harga').focus(); }
  });
  $('#mart-manual-harga').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); $('#mart-manual-tambah').click(); }
  });
  $('#mart-manual-tambah').onclick = () => {
    const desk = $('#mart-manual-desk').value.replace(/\s+/g, ' ').trim();
    const h = nilaiUang('#mart-manual-harga');
    if (desk.length < 3) { toast('Tulis dulu nama barangnya (minimal 3 huruf)', 'err'); $('#mart-manual-desk').focus(); return; }
    if (!h || h <= 0) { toast('Isi harga satuan dulu', 'err'); $('#mart-manual-harga').focus(); return; }
    const kategori = mart.info?.tenant?.jenis === 'koperasi' ? $('#mart-manual-kat').value : 'makanan';
    mart.cart.push({ manual: true, nama: desk, harga: h, kategori, qty: 1 });
    $('#mart-manual-desk').value = '';
    $('#mart-manual-harga').value = '';
    martRender();
    // Barang manual biasanya diketik di HP/tablet sambil berdiri. Di layar
    // selebar itu metode bayar ada jauh di bawah, jadi layarnya digulirkan
    // ke sana. Di layar lebar tidak ada guliran dan perilaku lamanya utuh:
    // fokus kembali ke kotak nama supaya barang berikutnya langsung diketik.
    if (martGulirKeBayar()) {
      $('#mart-manual-desk').blur();
      $('#mart-manual-harga').blur();
    } else {
      $('#mart-manual-desk').focus();
    }
  };
  $('#mart-kosong').onclick = () => {
    mart.cart = []; mart.metode = null; mart.uang = 0; mart.diskon = 0; mart.catatan = '';
    martRender();
  };
  $('#mart-m-kartu').onclick = () => martPilihMetode('kartu');
  $('#mart-m-tunai').onclick = () => martPilihMetode('tunai');
  $('#mart-m-transfer').onclick = () => martPilihMetode('transfer');
  $('#mart-m-hutang').onclick = () => martPilihMetode('hutang');
  $('#mart-kas').onclick = martKasModal;
  $('#mart-bantuan').onclick = martBantuanModal;
  $('#mart-tahan').onclick = martTahanSimpan;
  $('#mart-tertahan').onclick = martTahanModal;
  martMuatTertahan();
  martMuatMember();
  cetakGambarStatus();
  cetakPeriksaAgen().then(cetakGambarStatus);

  martRender();
  martMuatKatalog().then(martTerapkanMuatan);
  martMuatInfo();
}

/*
 * Keranjang yang "dilanjutkan" dari layar Riwayat dititipkan di sini lebih
 * dulu, lalu dipasang setelah katalog selesai dimuat — kalau dipasang
 * langsung, martMuatKatalog() yang datang belakangan akan menggambar ulang
 * layar di atasnya dan keranjangnya terlihat kosong.
 */
let martMuatan = null;

function martTerapkanMuatan() {
  if (!martMuatan) return;
  const m = martMuatan;
  martMuatan = null;
  // Satuannya ikut dipulihkan — baris dus yang kembali sebagai pcs akan
  // terjual dengan harga yang salah dan memotong stok satu pcs saja.
  mart.cart = m.items.map((i) => ({ produk_id: i.produk_id, nama: i.nama,
    harga: i.harga, kategori: i.kategori, qty: i.qty, manual: !!i.manual,
    satuan: i.satuan || 'ecer', isi: i.isi || 1 }));
  mart.diskon = m.diskon || 0;
  martRender();
  if (m.hilang?.length) {
    toast(`${m.hilang.length} barang sudah tidak dijual dan tidak ikut dilanjutkan: `
      + m.hilang.join(', '), 'err');
  } else {
    toast(`✔ Transaksi dilanjutkan${m.catatan ? ' — ' + m.catatan : ''}`, 'ok');
  }
}

async function martMuatKatalog() {
  try {
    const d = await api('/api/mart/barang');
    mart.produk = (d.produk || []).filter((p) => p.aktif);
    martGambarKatalog();
  } catch (e) { $('#mart-katalog').innerHTML = `<span class="minus">${esc(e.message)}</span>`; }
}

function martGambarKatalog() {
  const q = ($('#mart-q')?.value || '').trim().toLowerCase();
  const daftar = mart.produk.filter((p) =>
    !q || p.nama.toLowerCase().includes(q) || String(p.barcode || '').includes(q));

  $('#mart-katalog').innerHTML = daftar.length
    ? daftar.map((p) => `
      <button class="mart-kartu" data-pilih="${p.id}" ${
        // Barang habis tidak bisa dijual — KECUALI saat ada barcode baru yang
        // menunggu ditempelkan. Di situ klik berarti "kode ini milik barang
        // ini", bukan "jual satu". Justru saat karton baru datanglah stoknya
        // masih nol, jadi mematikan kartunya menutup satu-satunya jalan
        // mendata kodenya dari layar kasir — padahal martTambah() memang
        // sengaja menempel barcode DULU, baru memeriksa stok.
        p.status === 'habis' && !mart.barcodeBaru ? 'disabled' : ''}>
        <span class="mart-kartu-atas">
          <span class="muted" style="font-size:11px">SKU: ${esc(p.barcode || '—')}</span>
          ${martBadgeStok(p, true)}
        </span>
        <b>${esc(p.nama)}</b>
        <span class="muted" style="font-size:11.5px">${esc(p.kategori_nama || 'Tanpa kategori')}</span>
        <span class="mart-kartu-bawah"><span class="rp">${rp(p.harga)}</span><span class="mart-plus">+</span></span>
      </button>${martAdaGrosir(p) ? `<button class="linkbtn mart-dus" data-dus="${p.id}"
        title="tambah satu ${p.isi_grosir} pcs">+ dus ${rp(p.harga_grosir)}</button>` : ''}`).join('')
    : '<span class="muted">Tidak ada barang cocok.</span>';

  document.querySelectorAll('#mart-katalog [data-pilih]').forEach((b) => {
    b.onclick = () => {
      const p = mart.produk.find((x) => x.id === Number(b.dataset.pilih));
      if (p) martTambah(p, 'ecer');
    };
  });
  document.querySelectorAll('#mart-katalog [data-dus]').forEach((b) => {
    b.onclick = () => {
      const p = mart.produk.find((x) => x.id === Number(b.dataset.dus));
      if (p) martTambah(p, 'grosir');
    };
  });
}

/** Daftar member untuk pilihan metode Hutang. */
async function martMuatMember() {
  try {
    const d = await api('/api/mart/member');
    mart.member = d.member;
  } catch { mart.member = []; }
}

async function martMuatInfo() {
  try {
    mart.info = await api('/api/mart/info');
    // Sakelar admin: tanpa input manual, kasir hanya boleh menjual barang
    // yang sudah terdaftar di stok — tombolnya sendiri tidak ditampilkan
    // sama sekali, bukan cuma dikosongkan.
    $('#mart-manual-toggle')?.classList.toggle('hidden', mart.info.tenant?.input_manual === false);
    $('#mart-manual-kat')?.classList.toggle('hidden', mart.info.tenant?.jenis !== 'koperasi');
    const h = mart.info.hari_ini;
    const box = $('#mart-ringkas');
    if (!box) return;
    box.innerHTML = `Uang masuk hari ini — tunai <b>${rp(h.tunai.total)}</b> ·
      transfer <b>${rp(h.transfer.total)}</b> · kartu <b>${rp(h.cashless.total)}</b>
      ${h.hutang.total ? `<br>Hutang hari ini <b>${rp(h.hutang.total)}</b> — barang keluar,
        uangnya belum masuk laci` : ''}
      ${mart.info.kas ? '<br><span class="badge aktif">kas hari ini sudah ditutup</span>' : ''}`;
  } catch { /* ringkasan opsional */ }
}

function martScan(kode) {
  if (!mart) return;
  if (mart.metode === 'kartu' && !mart.siswa) { martTapKartu(kode); return; }
  martCari(kode, true);
}

async function martCari(q, dariPindai) {
  try {
    const d = await api('/api/mart/cari', { method: 'POST', body: { q } });
    mart.barcodeBaru = d.barcode_baru || null;
    if (d.cocok) {
      // `satuan` dari server menyebut lewat KOLOM MANA kodenya cocok: barcode
      // bungkus → pcs, barcode karton → dus. Tanpa meneruskannya, pindaian
      // karton masuk sebagai satu pcs dan kasir harus menukarnya manual.
      martTambah(d.cocok, d.satuan);
      $('#mart-q').value = '';
      $('#mart-hasil').innerHTML = '';
      martGambarKatalog();
      return;
    }
    if (d.barcode_baru) {
      $('#mart-hasil').innerHTML = `<div class="aju-unduh" style="margin:10px 0">
        <b>Barcode <code>${esc(d.barcode_baru)}</code> belum terdaftar.</b>
        <span class="muted set-hint">Ketik nama barangnya di kotak atas, lalu klik barang yang benar
          untuk menempelkan barcode ini sekaligus memasukkannya ke keranjang.
          Kalau barang itu dijual per dus juga, Anda akan ditanya dulu kode ini
          untuk satuan atau untuk dus.</span></div>`;
      return;
    }
    $('#mart-hasil').innerHTML = '';
    if (dariPindai) toast(`Barcode "${q}" tidak ditemukan`, 'err');
    martGambarKatalog();
  } catch (e) { toast(e.message, 'err'); }
}

/**
 * Sisa stok yang masih boleh diambil = stok di rak DIKURANGI yang sudah
 * telanjur masuk keranjang. Pengurangan itu yang penting: keranjang belum
 * memotong stok di basis data, jadi tanpa ini kasir bisa menumpuk sepuluh
 * barang padahal raknya tinggal dua, dan penolakan baru datang dari server
 * setelah pembeli menunggu di depan meja.
 *
 * Barang yang stoknya tidak dilacak (null) tidak pernah menghalangi — aturan
 * yang sama dengan Services/Stok.php di server. Begitu juga barang ketikan
 * bebas, yang memang tidak punya catatan stok.
 */
function martStokSisa(p) {
  const id = p.id ?? p.produk_id;
  if (!id) return Infinity;
  // Hasil pindaian hanya membawa id/nama/harga/barcode/stok
  // (MartController::ringkasProduk); katalog dipakai lebih dulu karena ia yang
  // paling mutakhir setelah penjualan sebelumnya.
  const ref = mart.produk.find((x) => x.id === id) || p;
  if (ref.stok === null || ref.stok === undefined) return Infinity;

  // Dihitung dalam SATUAN ECERAN, menjumlah seluruh baris barang ini —
  // baris pcs dan baris dus sekaligus. Satu dus berisi 12 memakan 12 dari
  // stok yang sama, jadi memisahkan hitungannya per baris akan membuat
  // keranjang menyanggupi barang yang raknya sudah tidak punya.
  return ref.stok - mart.cart.reduce((n, b) =>
    n + (b.produk_id === id ? b.qty * (b.isi || 1) : 0), 0);
}

/** Pagar tambah keranjang: menolak sekaligus menyebut sisanya. */
function martBolehTambah(p, tambahanPcs) {
  const sisa = martStokSisa(p);
  if (sisa >= tambahanPcs) return true;
  const nama = p.nama || 'barang ini';
  toast(sisa <= 0 ? `Stok ${nama} habis` : `Stok ${nama} tinggal ${sisa} pcs`, 'err');
  return false;
}

/** Apakah barang ini disiapkan untuk dijual per kemasan? */
const martAdaGrosir = (p) => !!(p && p.isi_grosir >= 2 && p.harga_grosir);

/**
 * Barcode baru yang dipindai bisa jadi kode bungkusnya, bisa jadi kode
 * kartonnya — hanya petugas yang memegang barangnya yang tahu.
 *
 * @returns {Promise<'ecer'|'grosir'|null>} null = dibatalkan.
 */
function martTanyaSlotBarcode(p, kode) {
  return new Promise((selesai) => {
    let sudah = false;
    const jawab = (v) => { if (sudah) return; sudah = true; selesai(v); };
    martPulihkanScan();
    openModal(`<h2>Barcode ini untuk satuan apa?</h2>
      <p class="muted">Kode <code>${esc(kode)}</code> akan ditempelkan ke <b>${esc(p.nama)}</b>.</p>
      <div class="modeswitch" style="margin-top:12px">
        <button id="bs-ecer">📦 Satuan<br><span class="muted" style="font-size:11px">${rp(p.harga)} / pcs</span></button>
        <button id="bs-dus">🧃 Dus<br><span class="muted" style="font-size:11px">${rp(p.harga_grosir)} / ${p.isi_grosir} pcs</span></button>
      </div>`);
    /*
     * Esc dan klik latar menutup modal tanpa melewati tombol mana pun. Tanpa
     * penjaga ini janji di atas menggantung selamanya, dan penambahan barang
     * ikut membeku — kasir menekan barang lalu tidak terjadi apa-apa.
     */
    const akar = $('#modal-root');
    const amati = new MutationObserver(() => {
      if (akar.children.length) return;
      amati.disconnect();
      jawab(null);
    });
    amati.observe(akar, { childList: true });
    $('#bs-ecer').onclick = () => { closeModal(); jawab('ecer'); };
    $('#bs-dus').onclick = () => { closeModal(); jawab('grosir'); };
  });
}

async function martTambah(p, satuan) {
  /*
   * Barcode hasil pindaian yang belum terdaftar ditempelkan ke barang yang
   * dipilih kasir — pendataan terjadi sambil melayani, bukan proyek tersendiri.
   *
   * Ada DUA slot: kode bungkus (`barcode`) dan kode karton (`barcode_grosir`).
   * Slot karton hanya ada kalau barangnya memang disiapkan grosir.
   */
  const slotEcerKosong = !p.barcode;
  const slotDusKosong = martAdaGrosir(p) && !p.barcode_grosir;
  if (mart.barcodeBaru && (slotEcerKosong || slotDusKosong)) {
    // Ditanyakan HANYA kalau dua-duanya masih kosong. Satu slot kosong tidak
    // ambigu, dan pertanyaan yang jawabannya cuma satu memperlambat antrean.
    let slot = slotEcerKosong ? 'ecer' : 'grosir';
    if (slotEcerKosong && slotDusKosong) {
      slot = await martTanyaSlotBarcode(p, mart.barcodeBaru);
      if (!slot) return;
    }
    try {
      await api(`/api/mart/produk/${p.id}/barcode`,
        { method: 'PUT', body: { barcode: mart.barcodeBaru, satuan: slot } });
      toast(`✔ Barcode ${slot === 'grosir' ? 'dus' : 'satuan'} ditempel ke ${p.nama}`, 'ok');
      if (slot === 'grosir') p.barcode_grosir = mart.barcodeBaru;
      else p.barcode = mart.barcodeBaru;
      mart.barcodeBaru = null;
      $('#mart-hasil').innerHTML = '';
      martGambarKatalog();
      // Kode karton yang baru ditempel berarti yang dipegang petugas memang
      // satu dus — barisnya dibuat dalam satuan itu, bukan pcs.
      if (slot === 'grosir') satuan = 'grosir';
    } catch (e) { toast(e.message, 'err'); }
  }
  // Satuan grosir hanya kalau barangnya memang disiapkan untuk itu; permintaan
  // grosir atas barang yang belum punya isi/harga grosir jatuh ke eceran,
  // bukan gagal — kasir sedang melayani antrean.
  const grosir = satuan === 'grosir' && martAdaGrosir(p);
  const isi = grosir ? p.isi_grosir : 1;

  // Pagar stok dipasang SESUDAH penempelan barcode: barcode yang baru dipindai
  // tetap layak didata ke barangnya walaupun stoknya sedang habis.
  if (!martBolehTambah(p, isi)) return;
  // Baris dipisah per satuan: 2 dus dan 3 pcs barang yang sama adalah dua
  // baris, karena harganya memang berbeda.
  const ada = mart.cart.find((x) => x.produk_id === p.id && (x.satuan || 'ecer') === (grosir ? 'grosir' : 'ecer'));
  if (ada) ada.qty += 1;
  else {
    mart.cart.push({ produk_id: p.id, nama: p.nama, kategori: p.kategori, qty: 1,
      harga: grosir ? p.harga_grosir : p.harga,
      satuan: grosir ? 'grosir' : 'ecer', isi });
  }
  martRender();
}

function martSubtotal() { return mart.cart.reduce((n, p) => n + p.harga * p.qty, 0); }
function martTotal() { return Math.max(0, martSubtotal() - (mart.diskon || 0)); }

/**
 * Gulirkan layar ke baris metode bayar sesudah barang masuk keranjang.
 *
 * Perlunya hanya di layar sempit (HP & tablet): di sana panel Keranjang turun
 * ke BAWAH Etalase dan katalognya tidak lagi dibatasi tinggi (lihat media
 * query .mart-katalog di style.css), jadi tombol metode bayar berada jauh di
 * luar layar — kasir harus menggulir sendiri sambil memegang barang.
 *
 * Pagarnya sengaja "apakah tombolnya sudah terlihat", BUKAN lebar layar: di
 * layar lebar kedua panel bersebelahan dan tombolnya memang sudah kelihatan,
 * jadi di sana fungsi ini tidak berbuat apa-apa — termasuk kalau jendela
 * komputernya kebetulan disempitkan sampai panelnya ikut turun.
 *
 * @returns {boolean} true kalau guliran dijadwalkan. Pemanggil yang mendapat
 *   true JANGAN mengembalikan fokus ke kotak input: memfokuskan input membuat
 *   peramban HP memunculkan papan ketik lalu menarik layar balik ke input itu,
 *   persis melawan guliran yang baru saja diminta.
 */
function martGulirKeBayar() {
  const el = $('#mart-metode');
  if (!el) return false;
  const kotak = el.getBoundingClientRect();
  const tinggi = window.innerHeight || document.documentElement.clientHeight;
  if (kotak.top >= 0 && kotak.bottom <= tinggi) return false;   // sudah terlihat utuh

  const diam = window.matchMedia
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  // Ditunda sebentar: pemanggil baru saja menutup papan ketik lewat blur(),
  // dan selama papan ketik masih terbuka tinggi layar menyusut sehingga
  // posisi tujuan gulirannya meleset.
  setTimeout(() => {
    const t = $('#mart-metode');
    if (t) t.scrollIntoView({ behavior: diam ? 'auto' : 'smooth', block: 'center' });
  }, 120);

  return true;
}

function martRender() {
  const box = $('#mart-cart');
  if (!box) return;
  box.innerHTML = mart.cart.length
    ? `<div class="cart">${mart.cart.map((p, i) => `
        <div class="cart-line mart-baris">
          <span class="mart-baris-nama"><b>${esc(p.nama)}</b>
            ${p.kategori === 'non_makanan' ? '<span class="katdot">non-makanan</span>' : ''}
            ${p.satuan === 'grosir' ? `<span class="katdot">dus isi ${p.isi}</span>` : ''}
            <br><span class="muted" style="font-size:11.5px">${rp(p.harga)} /
              ${p.satuan === 'grosir' ? 'dus' : 'pcs'}</span>
            ${martAdaGrosir(mart.produk.find((x) => x.id === p.produk_id))
              ? `<button class="linkbtn" data-tukar="${i}" style="font-size:11px">
                  ⇄ jadikan ${p.satuan === 'grosir' ? 'pcs' : 'dus'}</button>` : ''}</span>
          <span class="mart-qty">
            <button class="linkbtn" data-kurang="${i}">−</button>
            <b>${p.qty}</b>
            <button class="linkbtn" data-tambah-qty="${i}">+</button>
          </span>
          <span class="rp mart-sub">${rp(p.harga * p.qty)}</span>
          <button data-hapus="${i}">✕</button>
        </div>`).join('')}</div>`
    : '<p class="muted" style="text-align:center; padding:22px 0">Keranjang masih kosong —<br>pindai atau pilih barang dari katalog.</p>';

  $('#mart-n').textContent = mart.cart.length ? `— ${mart.cart.reduce((n, p) => n + p.qty, 0)} barang` : '';

  $('#mart-hitung').innerHTML = mart.cart.length ? `
    <div class="cart" style="border-top:1px solid var(--line)">
      <div class="cart-line"><span class="muted">Subtotal</span><span class="rp">${rp(martSubtotal())}</span></div>
      <div class="cart-line"><span class="muted">Diskon (Rp)</span>
        <span style="width:130px">${inputUang({ id: 'mart-diskon', nilai: mart.diskon || '' })}</span></div>
    </div>
    <div class="total"><span>Total</span><span class="rp" id="mart-total">${rp(martTotal())}</span></div>` : '';

  box.querySelectorAll('[data-kurang]').forEach((b) => {
    b.onclick = () => { const p = mart.cart[Number(b.dataset.kurang)];
      if (p.qty > 1) p.qty -= 1; else mart.cart.splice(Number(b.dataset.kurang), 1); martRender(); };
  });
  box.querySelectorAll('[data-tambah-qty]').forEach((b) => {
    b.onclick = () => { const p = mart.cart[Number(b.dataset.tambahQty)];
      if (!martBolehTambah(p, 1)) return;
      p.qty += 1; martRender(); };
  });
  box.querySelectorAll('[data-hapus]').forEach((b) => {
    b.onclick = () => { mart.cart.splice(Number(b.dataset.hapus), 1); martRender(); };
  });
  // Tukar satuan: dipakai saat pembeli berubah pikiran di meja kasir —
  // menghapus lalu menambah ulang akan kehilangan jumlah yang sudah diketik.
  box.querySelectorAll('[data-tukar]').forEach((b) => {
    b.onclick = () => {
      const baris = mart.cart[Number(b.dataset.tukar)];
      const p = mart.produk.find((x) => x.id === baris.produk_id);
      if (!p) return;
      const keGrosir = baris.satuan !== 'grosir';
      const isiBaru = keGrosir ? p.isi_grosir : 1;
      // Jatah stok dihitung ulang dengan baris ini dikeluarkan dulu, supaya
      // yang diperiksa adalah kebutuhan BARUNYA, bukan selisih yang keliru.
      const sisaLain = martStokSisa(baris) + baris.qty * (baris.isi || 1);
      if (baris.qty * isiBaru > sisaLain) {
        toast(`Stok ${p.nama} tinggal ${sisaLain} pcs — tidak cukup untuk ${baris.qty} dus`, 'err');
        return;
      }
      baris.satuan = keGrosir ? 'grosir' : 'ecer';
      baris.isi = isiBaru;
      baris.harga = keGrosir ? p.harga_grosir : p.harga;
      martRender();
    };
  });
  // Diskon diketik langsung, jadi hanya angka total dan panel bayar yang
  // diperbarui — menggambar ulang keranjang akan menendang kursor keluar.
  box.querySelectorAll('.mart-baris').forEach((el, i) => {
    el.addEventListener('mousedown', () => { mart.pilih = i; martSorotBaris(); });
  });
  martSorotBaris();

  const dk = $('#mart-diskon');
  if (dk) dk.addEventListener('input', () => {
    mart.diskon = Math.min(nilaiUang('#mart-diskon') || 0, martSubtotal());
    $('#mart-total').textContent = rp(martTotal());
    martRenderBayar();
  });
  martRenderBayar();
}

function martPilihMetode(m) {
  if (!mart.cart.length) { toast('Keranjang masih kosong', 'err'); return; }
  mart.metode = mart.metode === m ? null : m;
  mart.uang = 0;
  martRenderBayar();
}

function martRenderBayar() {
  const box = $('#mart-bayar');
  if (!box) return;
  ['kartu', 'tunai', 'transfer', 'hutang'].forEach((m) =>
    $(`#mart-m-${m}`).classList.toggle('on', mart.metode === m));

  const total = martTotal();
  if (!mart.cart.length || !mart.metode) {
    box.innerHTML = `<button class="paybtn" disabled>${
      mart.cart.length ? 'Pilih metode bayar dulu' : 'Keranjang kosong'}</button>`;
    return;
  }

  if (mart.metode === 'kartu') {
    const s = mart.siswa;
    box.innerHTML = `
      ${s ? `<div class="stucard">${avatarHtml(s)}
          <div><b>${esc(s.nama)}</b><br><span class="muted">${esc(s.kelas)} · NIS ${esc(s.nis)}</span></div>
          <div class="saldo"><span class="muted">Saldo</span><br><b class="rp">${rp(s.saldo)}</b>
            <br><span class="muted" style="font-size:11px">sisa ${rp(s.saldo - total)}</span></div></div>`
        : `<div class="tapzone" style="margin-top:4px"><div class="big">💳</div>
            <b>Tempelkan kartu</b><br><span class="muted">saldo dipotong otomatis</span></div>`}
      ${martStrukPilihan()}
      <button class="paybtn" id="mart-bayar-kartu" ${s && mart.tapToken ? '' : 'disabled'}>
        ${s ? `Bayar ${rp(total)} dari saldo` : 'Menunggu kartu…'}</button>`;
    if ($('#mart-bayar-kartu')) $('#mart-bayar-kartu').onclick = martBayarKartu;
    martPasangStrukPilihan();
    return;
  }

  if (mart.metode === 'hutang') {
    const m = (mart.member || []).find((x) => x.id === mart.memberId);
    const sisaBaru = m ? m.sisa_piutang + total : 0;
    // Kategori menyaring lebih dulu daripada plafon — kalau kategorinya memang
    // tidak boleh berhutang, kasir harus mendengar ALASAN ITU, bukan dikirim
    // mengurus plafon yang tidak akan menolong. Servernya juga menolak.
    const kategoriBoleh = !m || m.boleh_hutang !== false;
    const cukup = m && kategoriBoleh && m.batas_hutang > 0 && sisaBaru <= m.batas_hutang;
    box.innerHTML = `
      <div class="field"><label>Member</label>
        <select id="mart-member">
          <option value="">— pilih member —</option>
          ${(mart.member || []).filter((x) => x.aktif).map((x) => `<option value="${x.id}"
            ${mart.memberId === x.id ? 'selected' : ''}>${esc(x.nama)}${x.hp ? ' · ' + esc(x.hp) : ''}
            </option>`).join('')}
        </select>
        <span class="muted set-hint">Hutang hanya boleh atas nama member terdaftar —
          piutang tanpa identitas yang pasti tidak bisa ditagih.</span></div>
      ${m ? `<div class="preview ${cukup ? 'ok' : ''}" style="${cukup ? '' : 'border-color:var(--err); color:var(--err)'}">
          Piutang berjalan <b>${rp(m.sisa_piutang)}</b> · plafon <b>${rp(m.batas_hutang)}</b>
          ${m.kategori_nama ? `· kategori <b>${esc(m.kategori_nama)}</b>` : ''}<br>
          ${!kategoriBoleh
            ? `Kategori <b>${esc(m.kategori_nama || '')}</b> <b>tidak boleh berhutang</b> —
               pilih metode bayar lain.`
            : m.batas_hutang <= 0
            ? 'Member ini <b>belum diberi plafon</b> — atur dulu di layar Member &amp; Piutang.'
            : cukup
              ? `Setelah belanja ini: <b>${rp(sisaBaru)}</b> dari ${rp(m.batas_hutang)}`
              : `Belanja ini membuat piutangnya <b>${rp(sisaBaru)}</b> — melewati plafon.`}
        </div>` : ''}
      <div class="field"><label>Keterangan <span class="muted">(opsional)</span></label>
        <input id="mart-catatan" maxlength="200" value="${esc(mart.catatan || '')}"
          placeholder="mis. untuk acara kelas 8B, diambil Ust. Rahmat">
        <span class="muted set-hint">Dibaca lagi saat menagih — "hutang ${rp(total)}" tanpa keterangan
          sering berakhir jadi perdebatan tentang untuk apa barangnya diambil.</span></div>
      ${martStrukPilihan()}
      <button class="paybtn" id="mart-bayar-hutang" ${cukup ? '' : 'disabled'}>
        ${m ? (cukup ? `Catat hutang ${rp(total)}`
          : (!kategoriBoleh ? 'Kategorinya tidak boleh berhutang' : 'Tidak boleh berhutang sebanyak itu'))
          : 'Pilih member dulu'}</button>`;
    $('#mart-member').onchange = () => {
      mart.memberId = Number($('#mart-member').value) || null;
      martRenderBayar();
    };
    // Disimpan ke state tiap ketikan: panel ini digambar ulang setiap kali
    // member atau diskon berubah, dan ketikan yang hanya hidup di DOM akan
    // hilang justru saat kasir sedang menyusun transaksinya.
    $('#mart-catatan')?.addEventListener('input', () => { mart.catatan = $('#mart-catatan').value; });
    if ($('#mart-bayar-hutang')) $('#mart-bayar-hutang').onclick = martBayarHutang;
    martPasangStrukPilihan();
    return;
  }

  if (mart.metode === 'transfer') {
    box.innerHTML = `
      <div class="field"><label>Bank / kanal</label>
        <input id="mart-bank" list="mart-bank-umum" placeholder="mis. BSI / BRI / QRIS" autocomplete="off">
        <datalist id="mart-bank-umum">
          ${['BSI', 'BRI', 'BNI', 'Mandiri', 'BCA', 'QRIS', 'DANA', 'GoPay', 'OVO', 'ShopeePay']
            .map((b) => `<option value="${b}">`).join('')}
        </datalist></div>
      <div class="field"><label>Nama pengirim</label>
        <input id="mart-pengirim" maxlength="80" placeholder="nama di bukti transfer" autocomplete="off">
        <span class="muted set-hint">Sistem tidak terhubung ke bank — dua kolom ini yang dipakai
          mencocokkan ke mutasi rekening saat tutup kas.</span></div>
      ${martStrukPilihan()}
      <button class="paybtn" id="mart-bayar-transfer">Selesaikan — ${rp(total)} via transfer</button>`;
    $('#mart-bayar-transfer').onclick = martBayarTransfer;
    martPasangStrukPilihan();
    return;
  }

  const cepat = [total, 20000, 50000, 100000].filter((n, i, a) => n >= total && a.indexOf(n) === i);
  const kembali = mart.uang - total;
  box.innerHTML = `
    <div class="k-chip-baris" style="margin:10px 0 8px">
      ${cepat.map((n) => `<button class="k-chip ${mart.uang === n ? 'aktif' : ''}" data-uang="${n}">
        ${n === total ? 'Uang pas' : rp(n)}</button>`).join('')}
    </div>
    <div class="field"><label>Uang diterima</label>${inputUang({ id: 'mart-uang', nilai: mart.uang || '' })}</div>
    <div id="mart-kembalian" class="mart-kembalian" style="${mart.uang >= total ? '' : 'display:none'}">
      ${mart.uang >= total ? `<span>KEMBALIAN</span><b class="rp">${rp(kembali)}</b>` : ''}</div>
    ${martStrukPilihan()}
    <button class="paybtn" id="mart-bayar-tunai" ${mart.uang >= total ? '' : 'disabled'}>
      ${mart.uang >= total ? `Selesaikan — kembalian ${rp(kembali)}` : 'Masukkan uang diterima'}</button>`;

  box.querySelectorAll('[data-uang]').forEach((b) => {
    b.onclick = () => { mart.uang = Number(b.dataset.uang); martRenderBayar(); };
  });
  // Saat kasir MENGETIK, hanya kotak kembalian & tombol yang diperbarui —
  // menggambar ulang seluruh panel membuat kursor melompat keluar.
  const inp = $('#mart-uang');
  if (inp) inp.addEventListener('input', () => {
    mart.uang = nilaiUang('#mart-uang') || 0;
    const sisa = mart.uang - total;
    const kotak = $('#mart-kembalian');
    const tombol = $('#mart-bayar-tunai');
    if (kotak) {
      kotak.style.display = mart.uang >= total ? '' : 'none';
      kotak.innerHTML = mart.uang >= total ? `<span>KEMBALIAN</span><b class="rp">${rp(sisa)}</b>` : '';
    }
    if (tombol) {
      tombol.disabled = mart.uang < total;
      tombol.textContent = mart.uang >= total ? `Selesaikan — kembalian ${rp(sisa)}` : 'Masukkan uang diterima';
    }
  });
  if ($('#mart-bayar-tunai')) $('#mart-bayar-tunai').onclick = martBayarTunai;
  martPasangStrukPilihan();
}

function martStrukPilihan() {
  return `<label class="cek" style="margin:10px 0 2px"><input type="checkbox" id="mart-struk"
    ${mart.struk ? 'checked' : ''}> cetak struk setelah bayar</label>`;
}
function martPasangStrukPilihan() {
  const c = $('#mart-struk');
  if (c) c.onchange = () => { mart.struk = c.checked; };
}

async function martTapKartu(uid) {
  try {
    const d = await api('/api/card-lookup', { method: 'POST', body: { uid } });
    if (!d.siswa) { toast('Kartu belum terdaftar', 'err'); return; }
    mart.siswa = d.siswa;
    mart.tapToken = d.tap_token;
    martRenderBayar();
  } catch (e) { toast(e.message, 'err'); }
}

async function martBayarKartu() {
  const btn = $('#mart-bayar-kartu');
  btn.disabled = true;
  const salinan = mart.cart.map((p) => ({ ...p }));
  const total = martTotal();
  try {
    // Diskon tidak berlaku untuk pembayaran kartu: jalur /api/beli menghitung
    // harga dari basis data dan mencocokkannya dengan total di layar, jadi
    // potongan hanya akan membuat keduanya berbeda dan transaksi ditolak.
    if (mart.diskon > 0) throw new Error('Diskon hanya berlaku untuk tunai & transfer — kosongkan dulu diskonnya');
    const d = await api('/api/beli', { method: 'POST', body: {
      siswa_id: mart.siswa.id, jumlah: total,
      items: mart.cart.map((p) => p.manual
        ? { manual: true, nama: p.nama, harga: p.harga, kategori: p.kategori, qty: p.qty }
        // Harga tidak dikirim: server membacanya dari kolom yang sesuai
        // satuannya (lihat Services\Keranjang).
        : { produk_id: p.produk_id, qty: p.qty, satuan: p.satuan || 'ecer' }),
      tap_token: mart.tapToken,
      tx_id: 'mart-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8),
    }});
    toast(`✔ Terbayar — sisa saldo ${rp(d.siswa.saldo)}`, 'ok');
    if (mart.struk) martCetak({ metode: 'Kartu (RFID)', item: salinan, subtotal: total,
      diskon: 0, total, bayar: total, kembalian: 0, no: martNoStruk('kartu', d.transaksi?.id),
      ket: `${d.siswa.nama} · sisa ${rp(d.siswa.saldo)}` });
    martSelesai();
  } catch (e) { toast(e.message, 'err'); btn.disabled = false; }
}

async function martBayarTunai() { martBayarMart('tunai'); }
async function martBayarTransfer() { martBayarMart('transfer'); }
async function martBayarHutang() { martBayarMart('hutang'); }

async function martBayarMart(metode) {
  const btn = $('#mart-bayar-' + metode);
  btn.disabled = true;
  const salinan = mart.cart.map((p) => ({ ...p }));
  const sub = martSubtotal();
  const diskon = mart.diskon || 0;
  const total = martTotal();
  const bank = $('#mart-bank')?.value.trim() || '';
  const pengirim = $('#mart-pengirim')?.value.trim() || '';
  try {
    const d = await api('/api/mart/jual-tunai', { method: 'POST', body: {
      metode,
      items: mart.cart.map((p) => p.manual
        ? { manual: true, nama: p.nama, harga: p.harga, kategori: p.kategori, qty: p.qty }
        // Harga tidak dikirim: server membacanya dari kolom yang sesuai
        // satuannya (lihat Services\Keranjang).
        : { produk_id: p.produk_id, qty: p.qty, satuan: p.satuan || 'ecer' }),
      diskon, uang_diterima: metode === 'tunai' ? mart.uang : total,
      bank, pengirim, member_id: mart.memberId || undefined,
      catatan: mart.catatan || '',
      tx_id: 'mrt-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8),
    }});
    toast(metode === 'tunai'
      ? `✔ Terbayar tunai — kembalian ${rp(d.jual.kembalian)}`
      : metode === 'transfer' ? `✔ Transfer tercatat — ${esc(bank)}`
        : `✔ Hutang dicatat — piutang ${esc(d.member?.nama || '')} kini ${rp(d.member?.sisa_piutang || 0)}`,
      'ok');
    if (mart.struk) martCetak({
      metode: metode === 'tunai' ? 'Tunai'
        : metode === 'transfer' ? `Transfer ${bank}` : 'HUTANG (belum dibayar)',
      item: salinan, subtotal: sub, diskon, total,
      bayar: d.jual.uang_diterima, kembalian: d.jual.kembalian,
      no: martNoStruk('jual', d.jual.id),
      ket: metode === 'transfer' ? `Pengirim: ${pengirim}`
        : metode === 'hutang' ? `Hutang a.n. ${d.member?.nama || ''}` : '',
    });
    martSelesai();
  } catch (e) { toast(e.message, 'err'); btn.disabled = false; }
}

/**
 * Nomor struk, disusun sama persis seperti di server (MartController::noStruk)
 * supaya nomor di kertas dan nomor di layar Riwayat tidak pernah berbeda.
 */
const martNoStruk = (jenis, id) => id
  ? (jenis === 'kartu' ? 'KRT-' : 'MRT-') + String(id).padStart(6, '0') : '';

// ------------------------------------------------------------- CETAK STRUK

/*
 * Dua jalur cetak, satu isi struk.
 *
 *   agen    — program kecil di PC kasir yang bicara ESC/POS langsung ke
 *             printer. Cepat (< 1 detik), memotong kertas, bisa membuka laci.
 *   peramban— jendela cetak biasa. Tanpa dialog kalau Chrome dijalankan
 *             dengan --kiosk-printing, dengan dialog kalau tidak.
 *
 * Mode disimpan PER PC (localStorage), bukan per tenant: ini sifat mesinnya,
 * bukan sifat tenantnya. Satu Mart bisa punya dua PC kasir dengan perangkat
 * berbeda, dan mereka tidak boleh saling memaksa.
 *
 * Mode 'otomatis' mencoba agen lalu mundur ke peramban kalau agennya tidak
 * menjawab. Jalur mundur itu bukan kemewahan: agen adalah program yang bisa
 * mati — PC restart, Windows memperbarui diri, antivirus menahannya — dan
 * tanpa jalur mundur struk berhenti keluar di tengah jam ramai.
 */
const CETAK_KUNCI = 'mart.cetak.mode';
const CETAK_AGEN_URL = 'http://127.0.0.1:9100';
let cetakAgenAda = null;            // null = belum diperiksa

const cetakMode = () => localStorage.getItem(CETAK_KUNCI) || 'otomatis';

/**
 * Apakah agen lokal menjawab? Hasilnya diingat supaya tidak menyapa ulang
 * setiap transaksi — kasir tidak boleh menunggu batas waktu jaringan hanya
 * untuk mencetak.
 */
async function cetakPeriksaAgen(paksa) {
  if (!paksa && cetakAgenAda !== null) return cetakAgenAda;
  try {
    const kendali = new AbortController();
    const waktu = setTimeout(() => kendali.abort(), 700);
    const r = await fetch(CETAK_AGEN_URL + '/ping', { signal: kendali.signal });
    clearTimeout(waktu);
    cetakAgenAda = r.ok;
  } catch { cetakAgenAda = false; }
  return cetakAgenAda;
}

/** Penanda kecil di layar kasir: kasir harus tahu ia sedang di jalur yang mana. */
function cetakGambarStatus() {
  const kotak = $('#mart-printer');
  if (!kotak) return;
  const mode = cetakMode();
  const teks = mode === 'dialog' ? '🖨 dialog cetak'
    : cetakAgenAda ? '🖨 agen aktif'
    : mode === 'agen' ? '🖨 agen tidak terdeteksi'
    : '🖨 lewat peramban';
  const warna = mode === 'agen' && !cetakAgenAda ? 'var(--err)' : 'var(--muted)';
  kotak.innerHTML = `<button class="linkbtn" id="mart-printer-btn"
    style="color:${warna}" title="pengaturan pencetak">${teks}</button>`;
  $('#mart-printer-btn').onclick = martPrinterModal;
}

/**
 * Kirim struk ke jalur yang berlaku.
 *
 * Yang dikirim ke agen adalah ISI struk dalam bentuk data, bukan HTML: agen
 * yang menyusunnya menjadi perintah ESC/POS. Dengan begitu lebar kertas,
 * pemotongan, dan laci kas diurus oleh yang memang tahu printernya.
 */
async function martCetak(x) {
  const mode = cetakMode();
  if (mode !== 'dialog' && (mode === 'agen' || await cetakPeriksaAgen())) {
    try {
      const r = await fetch(CETAK_AGEN_URL + '/cetak', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(martStrukData(x)),
      });
      if (r.ok) return;
      throw new Error('agen menolak');
    } catch (e) {
      cetakAgenAda = false;
      cetakGambarStatus();
      if (mode === 'agen') {
        toast('Agen pencetak tidak menjawab — struk dicetak lewat peramban', 'err');
      }
    }
  }
  martCetakStrukHtml(x);
}

/** Bentuk data struk — dipakai agen, dan juga dasar penyusunan struk HTML. */
function martStrukData(x) {
  return {
    toko: USER?.tenant_nama || 'IMBOS Mart',
    sekolah: 'Insan Mulia Boarding School Pringsewu',
    no: x.no || '', waktu: x.waktu || new Date().toISOString(),
    kasir: x.operator || USER?.nama || '-',
    ulang: !!x.ulang,
    item: x.item.map((p) => ({ nama: p.nama, qty: p.qty, harga: p.harga, subtotal: p.qty * p.harga })),
    subtotal: x.subtotal, diskon: x.diskon || 0, total: x.total,
    metode: x.metode, bayar: x.bayar, kembalian: x.kembalian || 0,
    ket: x.ket || '',
  };
}

/**
 * Struk 58mm untuk jalur peramban.
 *
 * Lebar isi 48mm, bukan 58mm: kertas 58mm hanya mencetak sekitar 48mm di
 * tengahnya, dan teks yang disusun selebar kertas akan terpotong di kanan.
 * Huruf monospace supaya kolom angka tetap lurus, dan seluruhnya hitam pekat
 * — printer termal tidak mengenal abu-abu, ia hanya memanaskan atau tidak.
 */
function martCetakStrukHtml(x) {
  const d = martStrukData(x);
  const wkt = new Date(d.waktu).toLocaleString('id-ID',
    { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  const gaya = `@page { size: 58mm auto; margin: 2mm; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { width: 48mm; margin: 0; color: #000;
      font: 11px/1.4 'Courier New', 'Consolas', monospace; }
    .tengah { text-align: center; }
    .toko { font-size: 15px; font-weight: bold; letter-spacing: .02em; }
    .sekolah { font-size: 9px; margin-bottom: 4px; }
    .ulang { font-size: 11px; font-weight: bold; border: 1px solid #000;
      padding: 1px 0; margin: 3px 0; }
    .garis { border-top: 1px dashed #000; margin: 4px 0; }
    .tebal { border-top: 1px solid #000; margin: 4px 0; }
    .kop { font-size: 10px; }
    .kop div { display: flex; justify-content: space-between; }
    .item { margin-bottom: 2px; }
    .item .nama { word-break: break-word; }
    .item .hit { display: flex; justify-content: space-between; font-size: 10px; }
    .b { display: flex; justify-content: space-between; gap: 4px; }
    .b span:last-child { white-space: nowrap; }
    .total { font-size: 15px; font-weight: bold; margin: 2px 0; }
    .kembali { font-size: 13px; font-weight: bold; }
    .kaki { font-size: 9px; margin-top: 8px; line-height: 1.35; }`;

  const baris = d.item.map((p) => `
    <div class="item">
      <div class="nama">${esc(p.nama)}</div>
      <div class="hit"><span>${p.qty} x ${rp(p.harga)}</span><span>${rp(p.subtotal)}</span></div>
    </div>`).join('');

  cetakLembar('Struk ' + (d.no || ''), gaya, `
    <div class="tengah toko">${esc(d.toko)}</div>
    <div class="tengah sekolah">${esc(d.sekolah)}</div>
    ${d.ulang ? '<div class="tengah ulang">CETAK ULANG</div>' : ''}
    <div class="garis"></div>
    <div class="kop">
      ${d.no ? `<div><span>No.</span><span>${esc(d.no)}</span></div>` : ''}
      <div><span>Waktu</span><span>${esc(wkt)}</span></div>
      <div><span>Kasir</span><span>${esc(d.kasir)}</span></div>
    </div>
    <div class="garis"></div>
    ${baris}
    <div class="garis"></div>
    <div class="b"><span>Subtotal</span><span>${rp(d.subtotal)}</span></div>
    ${d.diskon ? `<div class="b"><span>Diskon</span><span>-${rp(d.diskon)}</span></div>` : ''}
    <div class="tebal"></div>
    <div class="b total"><span>TOTAL</span><span>${rp(d.total)}</span></div>
    <div class="tebal"></div>
    <div class="b"><span>${esc(d.metode)}</span><span>${rp(d.bayar)}</span></div>
    ${d.kembalian ? `<div class="b kembali"><span>KEMBALI</span><span>${rp(d.kembalian)}</span></div>` : ''}
    ${d.ket ? `<div class="kop" style="margin-top:3px">${esc(d.ket)}</div>` : ''}
    <div class="garis"></div>
    <div class="tengah kaki">Terima kasih atas kunjungan Anda<br>
      Barang yang sudah dibeli tidak dapat ditukar atau dikembalikan
      <br><br>~ ${esc(d.toko)} ~</div>`);
}

/** Pengaturan pencetak — per PC, bukan per tenant. */
async function martPrinterModal() {
  await cetakPeriksaAgen(true);
  const mode = cetakMode();
  const pil = (nilai, judul, ket) => `
    <label class="cek" style="align-items:flex-start; margin-bottom:8px">
      <input type="radio" name="cetak-mode" value="${nilai}" ${mode === nilai ? 'checked' : ''}>
      <span><b>${judul}</b><br><span class="muted" style="font-size:12.5px">${ket}</span></span>
    </label>`;
  openModal(`
    <h2>Pencetak Struk</h2>
    <p class="muted">Pengaturan ini milik <b>komputer ini</b>, bukan milik tenant —
      PC kasir lain punya pengaturannya sendiri.</p>
    <p>Agen pencetak lokal:
      <b style="color:${cetakAgenAda ? 'var(--ok)' : 'var(--err)'}">
        ${cetakAgenAda ? 'terdeteksi' : 'tidak terdeteksi'}</b></p>
    ${pil('otomatis', 'Otomatis (disarankan)',
      'Pakai agen kalau ada, mundur ke peramban kalau tidak. Struk tidak pernah berhenti keluar hanya karena agennya mati.')}
    ${pil('agen', 'Agen saja',
      'Hanya lewat agen lokal. Kalau agennya mati, struk tetap dicetak lewat peramban tetapi Anda diberi tahu.')}
    ${pil('dialog', 'Lewat peramban',
      'Jendela cetak peramban. Tanpa dialog kalau Chrome dijalankan dengan mode kiosk-printing, dengan dialog kalau tidak.')}
    <div class="aju-unduh" style="margin-top:10px">
      <b>Supaya tidak muncul dialog cetak:</b>
      <span class="muted set-hint">Klik kanan pintasan Chrome → Properties → pada kolom
        <b>Target</b>, tambahkan <code>--kiosk-printing</code> sebelum alamat situs. Lalu jadikan
        printer struk sebagai <b>Default printer</b> Windows. Pintasan Chrome yang lama sebaiknya
        dihapus — membuka situs dari pintasan lain membuat dialognya muncul lagi.</span>
    </div>
    <button class="linkbtn" id="cetak-uji" style="margin-top:10px">🖨 Cetak struk percobaan</button>`,
    async () => {
      const p = document.querySelector('input[name="cetak-mode"]:checked');
      localStorage.setItem(CETAK_KUNCI, p ? p.value : 'otomatis');
      await cetakPeriksaAgen(true);
      cetakGambarStatus();
      toast('✔ Pengaturan pencetak disimpan', 'ok');
    }, 'Simpan');

  $('#cetak-uji').onclick = () => martCetak({
    no: 'UJI-000000', metode: 'Tunai', subtotal: 13000, diskon: 1000, total: 12000,
    bayar: 20000, kembalian: 8000, ket: 'Struk percobaan — bukan penjualan',
    item: [{ nama: 'Contoh Barang Satu', qty: 2, harga: 4000 },
      { nama: 'Contoh Barang Dua', qty: 1, harga: 5000 }],
  });
}

function martSelesai() {
  mart.cart = [];
  mart.metode = null;
  // Centang struk sengaja ikut dikembalikan ke mati: ia pilihan PER TRANSAKSI,
  // bukan setelan sesi. Kalau dibiarkan menyala setelah melayani satu pembeli
  // luar, seluruh santri sesudahnya ikut tercetak struknya tanpa ada yang
  // menyadari — persis pemborosan yang ingin dihindari.
  mart.struk = false;
  mart.siswa = null;
  mart.tapToken = null;
  mart.uang = 0;
  mart.diskon = 0;
  mart.pilih = null;
  mart.catatan = '';
  martRender();
  // Kursor dikembalikan ke kotak cari: transaksi berikutnya harus bisa dimulai
  // dengan memindai, tanpa tangan berpindah ke tetikus lebih dulu.
  const q = $('#mart-q');
  if (q) q.focus();
  martMuatInfo();
  martMuatKatalog();          // stok di katalog ikut turun
  martMuatTertahan();
  martMuatMember();
  mart.memberId = null;
}

// ------------------------------------------------- TRANSAKSI TERTAHAN

/*
 * Keranjang yang diparkir sebentar: pembeli lupa membawa uang, atau menyuruh
 * temannya mengambil satu barang lagi, sementara antrean memanjang. Disimpan
 * di server supaya tetap ada walau peramban kasir tertutup — dan supaya
 * transaksi yang ditahan di satu meja bisa dilanjutkan di meja sebelahnya.
 */
let martNTertahan = 0;

async function martMuatTertahan() {
  try {
    const d = await api('/api/mart/tahan');
    martNTertahan = d.tahan.length;
  } catch { martNTertahan = 0; }
  const b = $('#mart-tertahan');
  if (!b) return;
  b.textContent = martNTertahan ? `⏵ ${martNTertahan} tertahan` : '';
  b.classList.toggle('hidden', !martNTertahan);
}

async function martTahanSimpan() {
  if (!mart.cart.length) { toast('Keranjang masih kosong', 'err'); return; }
  openModal(`
    <h2>Tahan Transaksi</h2>
    <p class="muted">Keranjang ini diparkir di server dan bisa dilanjutkan dari meja kasir mana
      pun. Stok <b>tidak</b> dipotong — barangnya belum terjual.</p>
    <div class="cart">
      ${mart.cart.map((p) => `<div class="cart-line"><span>${esc(p.nama)} ×${p.qty}</span>
        <span class="rp">${rp(p.harga * p.qty)}</span></div>`).join('')}
      <div class="cart-line"><span><b>Total</b></span>
        <span class="rp"><b>${rp(martTotal())}</b></span></div>
    </div>
    <div class="field"><label>Catatan (opsional)</label>
      <input id="th-catatan" maxlength="80" placeholder="mis. Bu Siti ambil uang dulu">
      <span class="muted set-hint">Supaya mudah dikenali saat dilanjutkan nanti.</span></div>`,
    async () => {
      await api('/api/mart/tahan', { method: 'POST', body: {
        items: mart.cart.map((p) => p.manual
        ? { manual: true, nama: p.nama, harga: p.harga, kategori: p.kategori, qty: p.qty }
        // Harga tidak dikirim: server membacanya dari kolom yang sesuai
        // satuannya (lihat Services\Keranjang).
        : { produk_id: p.produk_id, qty: p.qty, satuan: p.satuan || 'ecer' }),
        diskon: mart.diskon || 0, catatan: $('#th-catatan').value,
      }});
      toast('✔ Transaksi ditahan', 'ok');
      mart.cart = []; mart.metode = null; mart.uang = 0; mart.diskon = 0; mart.pilih = null;
      mart.catatan = '';
      martRender();
      martMuatTertahan();
      const q = $('#mart-q');
      if (q) q.focus();
    }, 'Tahan');
  const c = $('#th-catatan');
  if (c) c.focus();
}

/** Daftar transaksi tertahan, dari layar kasir. */
async function martTahanModal() {
  let d;
  try { d = await api('/api/mart/tahan'); } catch (e) { toast(e.message, 'err'); return; }
  if (!d.tahan.length) { toast('Tidak ada transaksi tertahan', 'ok'); martMuatTertahan(); return; }
  openModal(`
    <h2>Transaksi Tertahan <span class="muted">${d.tahan.length}</span></h2>
    <p class="muted">Dilanjutkan = keranjangnya kembali ke layar kasir, dan catatan tertahannya
      dihapus supaya tidak ada yang menjualnya dua kali.</p>
    <div class="tblwrap" style="max-height:50vh; overflow:auto"><table>
      <thead><tr><th>Waktu</th><th>Catatan</th><th>Isi</th><th class="num">Item</th>
        <th class="num">Total</th><th></th></tr></thead>
      <tbody>${d.tahan.map((x) => `<tr>
        <td>${waktuSingkat(x.created_at)}</td>
        <td><b>${esc(x.catatan || '—')}</b></td>
        <td class="muted">${esc(x.ringkas || '')}</td>
        <td class="num">${x.n_item}</td>
        <td class="num rp">${rp(x.total)}</td>
        <td style="white-space:nowrap">
          <button class="linkbtn" data-lanjut="${x.id}">lanjutkan</button> ·
          <button class="linkbtn danger" data-buang="${x.id}">buang</button></td>
      </tr>`).join('')}</tbody>
    </table></div>`);
  document.querySelectorAll('[data-lanjut]').forEach((b) => {
    b.onclick = () => martTahanLanjut(Number(b.dataset.lanjut));
  });
  document.querySelectorAll('[data-buang]').forEach((b) => {
    b.onclick = async () => {
      const x = d.tahan.find((y) => y.id === Number(b.dataset.buang));
      if (!confirm(`Buang transaksi tertahan ${x.catatan || waktuSingkat(x.created_at)}?`)) return;
      try {
        await api('/api/mart/tahan/' + x.id, { method: 'DELETE' });
        toast('Transaksi tertahan dibuang', 'ok');
        closeModal();
        martMuatTertahan();
        if (activeView === 'mart-riwayat') martMuatRiwayat();
      } catch (e) { toast(e.message, 'err'); }
    };
  });
}

/**
 * Lanjutkan satu transaksi tertahan.
 *
 * Kalau keranjang di layar sedang berisi, isinya akan tertimpa — jadi
 * ditanyakan lebih dulu. Kehilangan keranjang yang sudah dipindai satu per
 * satu lebih menyakitkan daripada satu pertanyaan.
 */
async function martTahanLanjut(id) {
  if (activeView === 'kasir' && mart?.cart.length
      && !confirm('Keranjang yang sedang terbuka akan diganti. Lanjutkan?')) return;
  let d;
  try { d = await api(`/api/mart/tahan/${id}/ambil`, { method: 'POST' }); }
  catch (e) { toast(e.message, 'err'); return; }

  closeModal();
  martMuatan = { items: d.items, diskon: d.diskon, catatan: d.catatan, hilang: d.hilang };
  if (activeView === 'kasir') {
    martTerapkanMuatan();
    martMuatTertahan();
  } else {
    // Pindah ke layar kasir; muatannya dipasang martView setelah katalog siap.
    $('#nav button[data-v="kasir"]')?.click();
  }
}

// --------------------------------------------------- PINTASAN PAPAN KETIK

/*
 * Kasir yang mahir tidak menyentuh tetikus. Satu transaksi penuh — pindai,
 * betulkan jumlah, pilih metode, isi uang, bayar, cetak — harus bisa
 * diselesaikan dari papan ketik saja.
 *
 * Dua aturan yang menjaganya tidak mengganggu:
 *
 *   1. Tombol HURUF/ANGKA (+ - * Del, panah) hanya bekerja saat kursor TIDAK
 *      sedang berada di kotak isian. Kalau tidak, mengetik nama bank "BSI+"
 *      atau mencari "A*" akan diam-diam mengubah keranjang.
 *   2. Tombol F selalu bekerja, karena tidak ada kotak isian yang memakainya.
 *
 * Pendengarnya dipasang sekali untuk seumur halaman dan menyaring sendiri
 * layar mana yang berlaku — lebih aman daripada memasang & mencabut
 * pendengar setiap kali layar berganti, yang pernah meninggalkan pendengar
 * menggantung di layar lain.
 */
const MART_PINTASAN = [
  ['pindai / ketik', 'cari barang lalu masukkan ke keranjang'],
  ['F1', 'tampilkan bantuan ini'],
  ['F2', 'pindah ke kotak cari'],
  ['↑ ↓', 'pilih baris keranjang'],
  ['+ / −', 'tambah / kurangi jumlah baris terpilih'],
  ['* lalu angka', 'setel jumlah langsung (mis. *12 Enter)'],
  ['Delete', 'hapus baris terpilih'],
  ['F3', 'bayar pakai Kartu'],
  ['F4', 'bayar Tunai'],
  ['F6', 'bayar Transfer'],
  ['F7', 'catat Hutang (member)'],
  ['%', 'isi diskon'],
  ['F8', 'tahan transaksi'],
  ['Shift + F8', 'daftar transaksi tertahan'],
  ['F9', 'selesaikan pembayaran'],
  ['Enter', 'di kotak uang: langsung selesaikan'],
  ['Esc', 'kosongkan keranjang'],
];

function martBantuanModal() {
  openModal(`
    <h2>Pintasan Papan Ketik</h2>
    <p class="muted">Satu transaksi penuh bisa diselesaikan tanpa menyentuh tetikus.</p>
    <div class="tblwrap"><table>
      <thead><tr><th style="width:140px">Tombol</th><th>Fungsi</th></tr></thead>
      <tbody>${MART_PINTASAN.map(([k, v]) => `<tr>
        <td><code>${esc(k)}</code></td><td>${esc(v)}</td></tr>`).join('')}</tbody>
    </table></div>
    <p class="muted">Tombol <code>+ − * % Delete</code> dan panah hanya bekerja saat kursor
      tidak sedang berada di kotak isian — supaya mengetik nama bank atau kata pencarian
      tidak diam-diam mengubah keranjang.</p>`);
}

/** Sedang mengetik di kotak isian? */
const diIsian = () => {
  const a = document.activeElement;
  return !!a && (a.tagName === 'INPUT' || a.tagName === 'SELECT' || a.tagName === 'TEXTAREA');
};

let martQtyKetik = null;        // null = tidak sedang mengetik jumlah lewat '*'

function martPilihBaris(arah) {
  if (!mart.cart.length) return;
  const n = mart.cart.length;
  mart.pilih = mart.pilih === null || mart.pilih === undefined
    ? (arah > 0 ? 0 : n - 1)
    : (mart.pilih + arah + n) % n;
  martSorotBaris();
}

function martSorotBaris() {
  document.querySelectorAll('#mart-cart .mart-baris').forEach((el, i) =>
    el.classList.toggle('terpilih', i === mart.pilih));
}

function martUbahQty(delta) {
  if (mart.pilih === null || mart.pilih === undefined) martPilihBaris(1);
  const p = mart.cart[mart.pilih];
  if (!p) return;
  if (delta > 0 && !martBolehTambah(p, delta)) return;
  if (p.qty + delta < 1) {
    mart.cart.splice(mart.pilih, 1);
    mart.pilih = Math.min(mart.pilih, mart.cart.length - 1);
    if (mart.cart.length === 0) mart.pilih = null;
  } else {
    p.qty += delta;
  }
  martRender();
  martSorotBaris();
}

function martSetQty(n) {
  const p = mart.cart[mart.pilih];
  if (!p || !(n >= 1)) return;
  // Baris ini akan diganti seluruhnya, jadi jatahnya dihitung terhadap baris
  // LAIN — qty baris ini sendiri dikembalikan dulu ke sisa.
  const sisa = martStokSisa(p) + p.qty;
  if (n > sisa) {
    toast(sisa <= 0 ? `Stok ${p.nama} habis` : `Stok ${p.nama} tinggal ${sisa}`, 'err');
    if (sisa < 1) return;
    n = sisa;
  }
  p.qty = Math.min(n, 99);
  martRender();
  martSorotBaris();
}

document.addEventListener('keydown', (e) => {
  if (!mart || activeView !== 'kasir' || USER?.tenant_mode !== 'mart') return;
  // Modal terbuka punya aturannya sendiri (Esc menutup, Enter menyimpan).
  if ($('#modal-root')?.children.length) return;

  // ---- tombol F: selalu berlaku
  const f = {
    F1: () => martBantuanModal(),
    F2: () => { const q = $('#mart-q'); if (q) { q.focus(); q.select(); } },
    F3: () => martPilihMetode('kartu'),
    F4: () => martPilihMetode('tunai'),
    F6: () => martPilihMetode('transfer'),
    F7: () => martPilihMetode('hutang'),
    F8: () => (e.shiftKey ? martTahanModal() : martTahanSimpan()),
    F9: () => {
      const tombol = $('#mart-bayar-kartu') || $('#mart-bayar-tunai') || $('#mart-bayar-transfer');
      if (tombol && !tombol.disabled) tombol.click();
      else toast('Belum siap dibayar — lengkapi dulu metode & nominalnya', 'err');
    },
  }[e.key];
  if (f) { e.preventDefault(); f(); return; }

  // ---- sisanya hanya saat tidak sedang mengetik
  if (diIsian()) {
    // Enter di kotak uang = langsung selesaikan, supaya tangan tidak pindah.
    if (e.key === 'Enter' && document.activeElement.id === 'mart-uang') {
      e.preventDefault();
      const b = $('#mart-bayar-tunai');
      if (b && !b.disabled) b.click();
    }
    return;
  }

  // Mengetik jumlah lewat '*': *12 Enter → baris terpilih menjadi 12.
  if (martQtyKetik !== null) {
    if (/^[0-9]$/.test(e.key)) { e.preventDefault(); martQtyKetik += e.key; martQtyPetunjuk(); return; }
    if (e.key === 'Enter') { e.preventDefault(); martSetQty(Number(martQtyKetik)); martQtyKetik = null; martQtyPetunjuk(); return; }
    if (e.key === 'Backspace') { e.preventDefault(); martQtyKetik = martQtyKetik.slice(0, -1); martQtyPetunjuk(); return; }
    if (e.key === 'Escape') { e.preventDefault(); martQtyKetik = null; martQtyPetunjuk(); return; }
  }

  switch (e.key) {
    case 'ArrowDown': e.preventDefault(); martPilihBaris(1); break;
    case 'ArrowUp': e.preventDefault(); martPilihBaris(-1); break;
    case '+': case '=': e.preventDefault(); martUbahQty(1); break;
    case '-': e.preventDefault(); martUbahQty(-1); break;
    case '%':
      e.preventDefault();
      if ($('#mart-diskon')) { $('#mart-diskon').focus(); $('#mart-diskon').select(); }
      else toast('Keranjang masih kosong', 'err');
      break;
    case '*':
      e.preventDefault();
      if (mart.pilih === null || mart.pilih === undefined) martPilihBaris(1);
      if (mart.cart.length) { martQtyKetik = ''; martQtyPetunjuk(); }
      break;
    case 'Delete':
      e.preventDefault();
      if (mart.cart.length && mart.pilih !== null && mart.pilih !== undefined) {
        mart.cart.splice(mart.pilih, 1);
        mart.pilih = mart.cart.length ? Math.min(mart.pilih, mart.cart.length - 1) : null;
        martRender();
        martSorotBaris();
      }
      break;
    case 'Escape':
      if (!mart.cart.length) break;
      e.preventDefault();
      // Dikonfirmasi: satu tombol tidak boleh menghapus belanjaan yang sudah
      // dipindai satu per satu.
      if (confirm(`Kosongkan keranjang (${mart.cart.length} barang)?`)) {
        mart.cart = []; mart.metode = null; mart.uang = 0; mart.diskon = 0; mart.pilih = null;
        mart.catatan = '';
        martRender();
      }
      break;
    default: break;
  }
});

/** Petunjuk kecil saat jumlah sedang diketik lewat '*'. */
function martQtyPetunjuk() {
  const kotak = $('#mart-qty-ketik');
  if (!kotak) return;
  kotak.innerHTML = martQtyKetik === null ? ''
    : `<b>Jumlah: ${esc(martQtyKetik || '…')}</b> — tekan Enter untuk menerapkan, Esc untuk batal`;
  kotak.classList.toggle('hidden', martQtyKetik === null);
}

// ------------------------------------------------------- TUTUP KAS HARIAN

/**
 * closeModal() mengosongkan cardHandler untuk semua pemakainya. Di layar kasir
 * itu berarti pemindai barang mati diam-diam begitu satu modal ditutup, dan
 * kasir baru menyadarinya ketika pindaian berikutnya tidak menghasilkan apa-apa.
 * Jadi penangkapnya dipasang kembali saat modal benar-benar hilang.
 */
function martPulihkanScan() {
  const akar = $('#modal-root');
  const amati = new MutationObserver(() => {
    if (akar.children.length) return;
    amati.disconnect();
    if (activeView === 'kasir') cardHandler = martScan;
  });
  amati.observe(akar, { childList: true });
}

async function martKasModal() {
  const d = await api('/api/mart/info');
  const h = d.hari_ini;
  if (d.kas) {
    openModal(`<h2>Kas ${esc(d.tanggal)} sudah ditutup</h2>
      <p class="muted">Ditutup oleh <b>${esc(d.kas.operator)}</b>.</p>
      <div class="cart">
        <div class="cart-line"><span>Kas awal</span><span class="rp">${rp(d.kas.kas_awal)}</span></div>
        <div class="cart-line"><span>Uang masuk tunai</span><span class="rp">${rp(d.kas.tunai)}</span></div>
        <div class="cart-line"><span>Uang keluar (sales)</span><span class="rp">−${rp(d.kas.keluar || 0)}</span></div>
        <div class="cart-line"><span>Hitungan fisik laci</span><span class="rp">${rp(d.kas.hitung_fisik)}</span></div>
        <div class="cart-line"><span>Selisih laci</span><span class="rp">${rp(d.kas.selisih)}</span></div>
        <div class="cart-line"><span>Transfer tercatat</span><span class="rp">${rp(d.kas.transfer)}</span></div>
        <div class="cart-line"><span>Transfer dicocokkan</span><span class="rp">${rp(d.kas.hitung_transfer)}</span></div>
        <div class="cart-line"><span>Selisih transfer</span><span class="rp">${rp(d.kas.selisih_transfer)}</span></div>
      </div>`);
    martPulihkanScan();
    return;
  }
  const usul = d.kas_kemarin ? d.kas_kemarin.hitung_fisik : 0;
  martPulihkanScan();
  openModal(`
    <h2>Tutup kas — ${esc(d.tanggal)}</h2>
    <p class="muted">Dua kantong uang dihitung terpisah supaya tidak saling menutupi selisih:
      <b>laci</b> dihitung fisik, <b>transfer</b> dicocokkan ke mutasi rekening.
      Penjualan kartu (${rp(h.cashless.total)}) tidak masuk keduanya — uangnya di kas sekolah.</p>
    <fieldset class="ortu-set"><legend>Laci (tunai)</legend>
      <div class="field"><label>Kas awal / modal laci</label>${inputUang({ id: 'mk-awal', nilai: usul })}
        <span class="muted set-hint">${d.kas_kemarin ? 'Diisi dari hitungan penutupan terakhir.' : 'Modal kembalian yang ada di laci pagi tadi.'}</span></div>
      <p class="muted" style="margin:0 0 8px">Uang masuk hari ini <b>${rp(h.tunai.total)}</b>
        (${h.tunai.n} kali)${h.tunai.cicilan ? ` — termasuk cicilan piutang ${rp(h.tunai.cicilan)}` : ''}.
        ${h.keluar.total ? `<br>Uang keluar ke sales <b class="minus">−${rp(h.keluar.total)}</b>
          (${h.keluar.n} pembayaran).` : ''}</p>
      <div class="field"><label>Hitungan fisik laci sekarang</label>${inputUang({ id: 'mk-fisik', nilai: '' })}</div>
    </fieldset>
    <fieldset class="ortu-set"><legend>Transfer</legend>
      <p class="muted" style="margin:0 0 8px">Masuk <b>${rp(h.transfer.total)}</b> (${h.transfer.n} kali)
        ${h.keluar_transfer.total ? ` · keluar <b class="minus">−${rp(h.keluar_transfer.total)}</b>` : ''}.</p>
      <div class="field"><label>Perubahan bersih di rekening</label>
        ${inputUang({ id: 'mk-transfer', nilai: h.transfer.total - h.keluar_transfer.total })}
        <span class="muted set-hint">Biarkan apa adanya kalau cocok dengan mutasi rekening.</span></div>
    </fieldset>
    <div class="field"><label>Catatan (opsional)</label>
      <input id="mk-catatan" maxlength="300" placeholder="mis. ada pembelian galon Rp20.000"></div>
    <div id="mk-hitung" class="muted"></div>`,
    async () => {
      const d2 = await api('/api/mart/tutup-kas', { method: 'POST', body: {
        kas_awal: nilaiUang('#mk-awal') || 0,
        hitung_fisik: nilaiUang('#mk-fisik') || 0,
        hitung_transfer: nilaiUang('#mk-transfer') || 0,
        catatan: $('#mk-catatan').value,
      }});
      const k = d2.kas;
      toast(k.selisih === 0 && k.selisih_transfer === 0
        ? '✔ Kas ditutup — semuanya cocok'
        : `Kas ditutup — selisih laci ${rp(k.selisih)}, transfer ${rp(k.selisih_transfer)}`,
        k.selisih === 0 && k.selisih_transfer === 0 ? 'ok' : 'err');
      martMuatInfo();
    }, 'Tutup kas');

  const hitung = () => {
    // Uang keluar ikut dikurangkan: membayar pemasok dari laci membuat isinya
    // berkurang, dan dulu itu muncul sebagai selisih yang harus dijelaskan
    // dengan catatan tangan.
    const seharusnya = (nilaiUang('#mk-awal') || 0) + h.tunai.total - h.keluar.total;
    const fisik = nilaiUang('#mk-fisik') || 0;
    const selisih = fisik - seharusnya;
    const st = (nilaiUang('#mk-transfer') || 0) - (h.transfer.total - h.keluar_transfer.total);
    $('#mk-hitung').innerHTML =
      `Laci seharusnya <b>${rp(seharusnya)}</b>` +
      (fisik ? ` · selisih <b style="color:${selisih === 0 ? 'var(--ok)' : 'var(--err)'}">${rp(selisih)}</b>` : '') +
      `<br>Selisih transfer <b style="color:${st === 0 ? 'var(--ok)' : 'var(--err)'}">${rp(st)}</b>`;
  };
  ['#mk-awal', '#mk-fisik', '#mk-transfer'].forEach((sel) => $(sel).addEventListener('input', hitung));
  hitung();
}

// --------------------------------------------------- BARANG & STOK (menu)

let martStok = { produk: [], kategori: [], ringkas: null };

function martStokView(el) {
  el.innerHTML = `<div class="panel">
    <div class="kat-head">
      <h2 style="margin:0">Barang &amp; Stok <span class="muted" id="ms-count"></span></h2>
      <span>
        <button class="linkbtn" id="ms-kategori">⚙ kelola kategori</button> ·
        <button class="linkbtn" id="ms-unduh">⬇ unduh CSV</button> ·
        <button class="linkbtn" id="ms-cetak">🖨 cetak</button> ·
        <button class="linkbtn" id="ms-persediaan-toggle">📦 laporan persediaan</button> ·
        <button class="linkbtn" id="ms-impor">⬆ Tambah kolektif (Excel)</button> ·
        <button class="linkbtn" id="ms-tambah">+ Tambah barang</button>
      </span>
    </div>
    <div id="ms-persediaan" class="hidden" style="margin-bottom:14px">
      <div class="kat-head"><h3 style="margin:0; font-size:14px">Persediaan menurut harga modal</h3>
        <span style="display:flex; gap:8px; align-items:center">
          <select id="ms-ps-format" class="k-chip-select" style="width:auto">
            <option value="xls">Excel (.xls)</option>
            <option value="doc">Word (.doc)</option>
            <option value="pdf">PDF (lewat dialog cetak)</option>
          </select>
          <button class="linkbtn" id="ms-ps-unduh">⬇ unduh</button> ·
          <button class="linkbtn" id="ms-ps-cetak">🖨 cetak</button>
        </span></div>
      <div id="ms-ps-isi" class="muted">memuat…</div>
    </div>
    <div class="aju-unduh hidden" id="ms-impor-box">
      <span class="muted">Untuk puluhan sampai ratusan barang sekaligus:</span>
      <button class="linkbtn" id="ms-impor-tpl">⬇ Unduh template Excel</button>
      <label class="linkbtn" style="cursor:pointer">📂 Pilih berkas yang sudah diisi
        <input type="file" id="ms-impor-berkas" accept=".xlsx,.csv" class="hidden"></label>
      <span class="muted set-hint">Templatenya sudah memuat daftar kategori Anda sebagai pilihan.
        Berkas dipratinjau dulu; barang yang namanya sudah ada <b>dilewati, tidak ditimpa</b>.</span>
    </div>
    <p class="muted">Khusus IMBOS Mart, perubahan barang <b>berlaku langsung</b> tanpa menunggu
      persetujuan admin — ini unit usaha yayasan sendiri. Seluruh barang &amp; mutasinya tetap
      terbaca di laporan admin pusat. Stok tidak bisa diketik di formulir barang; ia hanya bergerak
      lewat penjualan, barang masuk, koreksi, atau opname — supaya setiap angka punya jejak.</p>
    <div id="ms-ringkas" class="cards"></div>
    <div class="filterbar">
      <div class="field"><label>Cari</label><input id="ms-cari" placeholder="nama / SKU"></div>
      <div class="field"><label>Kategori</label><select id="ms-kat"><option value="">semua</option></select></div>
      <div class="field"><label>Status stok</label><select id="ms-status">
        <option value="">semua</option><option value="menipis">menipis</option>
        <option value="habis">habis</option></select></div>
    </div>
    <div class="tblwrap"><table>
      <thead><tr><th>SKU</th><th>Barang</th><th>Kategori</th><th class="num">Harga beli</th>
        <th class="num">Harga jual</th><th class="num">Margin</th><th class="num">Stok</th>
        <th>Status</th><th>Expired</th><th></th></tr></thead>
      <tbody id="ms-body"></tbody>
    </table></div>
  </div>`;

  $('#ms-tambah').onclick = () => martBarangModal(null);
  $('#ms-kategori').onclick = martKategoriModal;
  $('#ms-persediaan-toggle').onclick = () => {
    const tampil = !$('#ms-persediaan').classList.toggle('hidden');
    $('#ms-persediaan-toggle').textContent = tampil
      ? '📦 tutup laporan persediaan' : '📦 laporan persediaan';
    // Dimuat ulang tiap dibuka: ini nilai uang yang sedang berwujud barang,
    // dan stok bergerak terus selama kasir melayani.
    if (tampil) martMuatPersediaan();
  };
  $('#ms-ps-unduh').onclick = () => {
    if ($('#ms-ps-format').value === 'pdf') { martCetakPersediaan(); return; }
    const a = document.createElement('a');
    a.href = '/api/mart/persediaan?format=' + $('#ms-ps-format').value;
    document.body.appendChild(a); a.click(); a.remove();
  };
  $('#ms-ps-cetak').onclick = martCetakPersediaan;
  $('#ms-unduh').onclick = martUnduhBarang;
  $('#ms-cetak').onclick = martCetakBarang;
  $('#ms-impor').onclick = () => $('#ms-impor-box').classList.toggle('hidden');
  $('#ms-impor-tpl').onclick = () => {
    // Berkas biner: lewat <a>, bukan fetch+JSON.
    const a = document.createElement('a');
    a.href = '/api/mart/barang/impor/template';
    document.body.appendChild(a); a.click(); a.remove();
  };
  $('#ms-impor-berkas').onchange = (e) => {
    const f = e.target.files[0];
    e.target.value = '';   // berkas yang sama boleh dipilih lagi sesudah diperbaiki
    if (f) martImporPratinjau(f);
  };
  const muat = debounce(martMuatStok, 250);
  $('#ms-cari').addEventListener('input', muat);
  $('#ms-kat').onchange = martMuatStok;
  $('#ms-status').onchange = martMuatStok;
  martMuatStok();
}

async function martMuatStok() {
  const p = new URLSearchParams();
  if ($('#ms-cari')?.value.trim()) p.set('cari', $('#ms-cari').value.trim());
  if ($('#ms-kat')?.value) p.set('kategori_id', $('#ms-kat').value);
  if ($('#ms-status')?.value) p.set('status', $('#ms-status').value);
  const d = await api('/api/mart/barang?' + p);
  martStok = d;

  // Digambar ulang setiap muat, bukan sekali saja: impor kolektif bisa
  // melahirkan kategori baru, dan saringan yang tidak memuatnya membuat
  // barang yang baru masuk terlihat seperti tidak ada.
  const sel = $('#ms-kat');
  if (sel) {
    const dipilih = sel.value;
    sel.innerHTML = '<option value="">semua</option>' +
      d.kategori.map((k) => `<option value="${k.id}">${esc(k.nama)}</option>`).join('');
    sel.value = dipilih;
  }
  const r = d.ringkas;
  $('#ms-ringkas').innerHTML = `
    <div class="statcard"><span class="muted">Jumlah</span><br><b>${r.n_barang}</b></div>
    <div class="statcard"><span class="muted">Stok menipis</span><br><b style="color:var(--warn)">${r.n_menipis}</b></div>
    <div class="statcard"><span class="muted">Stok habis</span><br><b class="minus">${r.n_habis}</b></div>
    <div class="statcard"><span class="muted">Nilai stok (modal)</span><br><b class="rp">${rp(r.nilai_stok)}</b></div>`;
  $('#ms-count').textContent = `— ${d.produk.length} barang`;

  $('#ms-body').innerHTML = d.produk.map((p) => `
    <tr>
      <td>${p.barcode ? `<code>${esc(p.barcode)}</code>` : '<span class="muted">—</span>'}${
        p.barcode_grosir ? `<br><span class="muted" style="font-size:11px">dus:
          <code>${esc(p.barcode_grosir)}</code></span>` : ''}</td>
      <td><b>${esc(p.nama)}</b>${p.aktif ? '' : ' <span class="badge nonaktif">nonaktif</span>'}</td>
      <td>${esc(p.kategori_nama || '—')}${p.kategori === 'non_makanan' ? ' <span class="katdot">non-makanan</span>' : ''}</td>
      <td class="num rp">${p.hpp ? rp(p.hpp) : '<span class="muted">—</span>'}</td>
      <td class="num rp">${rp(p.harga)}${p.harga_grosir
        ? `<br><span class="muted" style="font-size:11px">grosir ${rp(p.harga_grosir)} / ${p.isi_grosir}</span>` : ''}</td>
      <td class="num">${p.margin === null ? '<span class="muted">—</span>'
        : `${p.margin}%<br><span class="muted" style="font-size:11px">${rp(p.untung)}</span>`}</td>
      <td class="num">${p.stok === null ? '<span class="muted">—</span>' : p.stok}</td>
      <td>${martBadgeStok(p)}</td>
      <td>${martBadgeExpired(p)}</td>
      <td style="white-space:nowrap">
        <button class="linkbtn" data-stok="${p.id}">stok</button>
        <button class="linkbtn" data-ubah="${p.id}">ubah</button>
        <button class="linkbtn danger" data-hapus="${p.id}">hapus</button>
      </td>
    </tr>`).join('') || '<tr><td colspan="10" class="muted">Belum ada barang.</td></tr>';

  document.querySelectorAll('#ms-body [data-ubah]').forEach((b) => {
    b.onclick = () => martBarangModal(d.produk.find((x) => x.id === Number(b.dataset.ubah)));
  });
  document.querySelectorAll('#ms-body [data-stok]').forEach((b) => {
    b.onclick = () => martStokModal(d.produk.find((x) => x.id === Number(b.dataset.stok)));
  });
  document.querySelectorAll('#ms-body [data-hapus]').forEach((b) => {
    b.onclick = async () => {
      const p = d.produk.find((x) => x.id === Number(b.dataset.hapus));
      if (!confirm(`Hapus "${p.nama}" dari daftar barang?`)) return;
      try {
        const h = await api('/api/mart/barang/' + p.id, { method: 'DELETE' });
        toast(h.dinonaktifkan ? h.pesan : '✔ Barang dihapus', 'ok');
        martMuatStok();
      } catch (e) { toast(e.message, 'err'); }
    };
  });
}

/**
 * Pratinjau berkas tambah barang kolektif, lalu simpan.
 *
 * Berkasnya dikirim DUA KALI — sekali untuk dipratinjau, sekali lagi saat
 * disimpan — dan server mengurainya ulang dari nol. Mengirim balik baris hasil
 * pratinjau akan lebih hemat, tetapi artinya harga & stok yang tersimpan
 * datang dari layar, bukan dari berkas yang dilihat orangnya.
 */
async function martImporPratinjau(file) {
  let data, d;
  try {
    data = await fileToDataUrl(file);
    d = await api('/api/mart/barang/impor/pratinjau', { method: 'POST',
      body: { data, nama_berkas: file.name } });
  } catch (e) { toast(e.message, 'err'); return; }

  const r = d.ringkas;
  const baris = d.baris.map((b) => `
    <tr class="${b.status === 'siap' ? '' : 'tp-menunggu'}">
      <td class="num muted">${b.baris}</td>
      <td>${b.barcode ? `<code>${esc(b.barcode)}</code>` : '<span class="muted">—</span>'}</td>
      <td>${esc(b.nama || '—')}</td>
      <td>${b.kategori ? esc(b.kategori) : '<span class="muted">—</span>'}
        ${b.kategori_baru ? '<span class="badge tp-pending">baru</span>' : ''}</td>
      <td class="num rp">${b.hpp === null ? '<span class="muted">—</span>' : rp(b.hpp)}</td>
      <td class="num rp">${b.harga ? rp(b.harga) : '—'}</td>
      <td class="num">${b.stok}</td>
      <td>${b.status === 'siap' ? '<span class="badge aktif">siap</span>'
        : `<span class="minus">${esc(b.pesan)}</span>`}</td>
    </tr>`).join('');

  openModal(`
    <h2>Pratinjau tambah barang</h2>
    <p><b>${esc(d.nama_berkas)}</b> — ${r.n_baris} baris:
      <b>${r.n_siap} siap disimpan</b>${r.n_gagal ? `, <span class="minus">${r.n_gagal} dilewati</span>` : ''}.</p>
    ${r.n_berstok ? `<p class="muted">${r.n_berstok} barang datang dengan stok awal, bernilai modal
      <b>${rp(r.nilai_stok)}</b>. Stok awal itu dicatat sebagai barang masuk, jadi ada jejaknya.</p>` : ''}
    ${d.kategori_baru.length ? `<p class="muted">Kategori baru yang akan dibuatkan:
      <b>${d.kategori_baru.map(esc).join(', ')}</b>. Periksa dulu kalau-kalau itu hanya salah ketik
      dari kategori yang sudah ada.</p>` : ''}
    ${r.n_gagal ? `<p class="muted">Baris yang dilewati tidak ikut disimpan. Perbaiki di Excel lalu
      unggah lagi — barang yang sudah tersimpan tidak akan kembar.</p>` : ''}
    <div class="tblwrap" style="max-height:50vh; overflow:auto"><table>
      <thead><tr><th class="num">Baris</th><th>SKU</th><th>Barang</th><th>Kategori</th>
        <th class="num">Beli</th><th class="num">Jual</th><th class="num">Stok</th>
        <th>Status</th></tr></thead>
      <tbody>${baris}</tbody>
    </table></div>`,
    r.n_siap ? async () => {
      const h = await api('/api/mart/barang/impor/terapkan', { method: 'POST',
        body: { data, nama_berkas: d.nama_berkas, sidik: d.sidik } });
      toast(`✔ ${h.n} barang ditambahkan — langsung tampil di kasir`, 'ok');
      martMuatStok();
    } : null, `Simpan ${r.n_siap} barang`);
  $('#modal-root .modal').classList.add('modal-lebar', 'modal-impor');
}

let martPs = null;

async function martMuatPersediaan() {
  $('#ms-ps-isi').innerHTML = '<span class="muted">memuat…</span>';
  try {
    martPs = await api('/api/mart/persediaan');
  } catch (e) { $('#ms-ps-isi').innerHTML = `<span class="minus">${esc(e.message)}</span>`; return; }
  const t = martPs.total;
  $('#ms-ps-isi').innerHTML = `
    <div class="cards">
      <div class="statcard" style="border-color:var(--brand)">
        <span class="muted">Nilai persediaan (modal)</span><br>
        <b class="rp" style="color:var(--brand)">${rp(t.nilai)}</b></div>
      <div class="statcard"><span class="muted">Barang berstok</span><br><b>${t.n_barang}</b></div>
      <div class="statcard"><span class="muted">Satuan di rak</span><br><b>${t.stok.toLocaleString('id-ID')}</b></div>
    </div>
    ${t.n_tanpa_hpp ? `<p class="minus">⚠ ${t.n_tanpa_hpp} barang (${t.stok_tanpa_hpp} satuan)
      belum punya harga modal, jadi menyumbang <b>nol</b> ke nilai di atas — nilai persediaan
      sebenarnya <b>lebih besar</b> daripada angka itu. Isi HPP-nya supaya laporannya utuh.</p>` : ''}
    <div class="tblwrap"><table>
      <thead><tr><th>SKU</th><th>Barang</th><th>Kategori</th><th class="num">Stok</th>
        <th class="num">Harga modal</th><th class="num">Nilai</th></tr></thead>
      <tbody>${martPs.baris.map((b) => `<tr>
        <td class="muted">${esc(b.barcode || '—')}</td>
        <td>${esc(b.nama)}${b.aktif ? '' : ' <span class="badge nonaktif">nonaktif</span>'}</td>
        <td class="muted">${esc(b.kategori_nama || '—')}</td>
        <td class="num">${b.stok}</td>
        <td class="num rp ${b.hpp ? '' : 'minus'}">${b.hpp ? rp(b.hpp) : 'belum diisi'}</td>
        <td class="num rp"><b>${rp(b.nilai)}</b></td></tr>`).join('')
        || '<tr><td colspan="6" class="muted">Belum ada barang yang stoknya dilacak.</td></tr>'}</tbody>
    </table></div>`;
}

function martCetakPersediaan() {
  if (!martPs) { toast('Buka dulu laporannya', 'err'); return; }
  const t = martPs.total;
  const gaya = `body{font:12px/1.5 system-ui;margin:18px;color:#111} h1{font-size:17px;margin:0}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th,td{border:1px solid #bbb;padding:5px 8px;text-align:left} th{background:#eee}
    .n{text-align:right} .tebal td{font-weight:700;background:#f6f6f6}`;
  cetakLembar('Persediaan Mart', gaya, `
    <h1>Laporan Persediaan (nilai modal) — ${esc(martPs.tenant.nama)}</h1>
    <div>Dicetak ${new Date().toLocaleString('id-ID')}</div>
    <table>
      <thead><tr><th>SKU</th><th>Barang</th><th>Kategori</th><th class="n">Stok</th>
        <th class="n">Harga modal</th><th class="n">Nilai</th></tr></thead>
      <tbody>${martPs.baris.map((b) => `<tr><td>${esc(b.barcode || '')}</td>
        <td>${esc(b.nama)}${b.aktif ? '' : ' (nonaktif)'}</td><td>${esc(b.kategori_nama || '')}</td>
        <td class="n">${b.stok}</td><td class="n">${rp(b.hpp)}</td>
        <td class="n">${rp(b.nilai)}</td></tr>`).join('')}
        <tr class="tebal"><td>TOTAL</td><td></td><td></td><td class="n">${t.stok}</td>
          <td></td><td class="n">${rp(t.nilai)}</td></tr></tbody>
    </table>
    <p style="margin-top:14px;font-size:11px;color:#555">Dinilai dengan HARGA MODAL (HPP), bukan
      harga jual.${t.n_tanpa_hpp ? ` Perhatian: ${t.n_tanpa_hpp} barang (${t.stok_tanpa_hpp} satuan)
      belum punya harga modal, sehingga nilai sebenarnya lebih besar daripada total di atas.` : ''}</p>`);
}

/**
 * Lampu expired. Warnanya datang dari SERVER (expired_status), bukan
 * dihitung ulang di sini: ambang 50/30 hari yang disalin ke layar, unduhan,
 * dan cetakan pasti akan berbeda suatu hari.
 */
const MART_EXP_BADGE = { hijau: 'aktif', kuning: 'menipis', merah: 'blokir', lewat: 'blokir' };

function martBadgeExpired(p) {
  if (!p.expired) return '<span class="muted">—</span>';
  const sisa = p.expired_hari;
  const teks = p.expired_status === 'lewat'
    ? `LEWAT ${Math.abs(sisa)} hari`
    : `${sisa} hari lagi`;

  return `<span class="badge ${MART_EXP_BADGE[p.expired_status] || 'netral'}" title="${esc(p.expired)}">
    ${esc(teks)}</span><br><span class="muted" style="font-size:11px">${esc(p.expired)}</span>`;
}

function martBarangModal(p) {
  const kat = martStok.kategori || [];
  openModal(`
    <h2>${p ? 'Ubah' : 'Tambah'} Barang</h2>
    <div class="field"><label>Nama barang</label>
      <input id="mb-nama" value="${esc(p?.nama || '')}" maxlength="120" placeholder="mis. Aqua 600ml"></div>
    <div class="field"><label>SKU / barcode <span class="muted">(opsional)</span></label>
      <input id="mb-barcode" data-scan="1" value="${esc(p?.barcode || '')}" maxlength="32" placeholder="pindai atau ketik">
      <span class="muted set-hint">Boleh dikosongkan — barang tetap bisa dicari lewat nama di kasir.</span>
      <span class="minus set-hint hidden" id="mb-barcode-cek"></span></div>
    <div class="field"><label>Kategori</label><select id="mb-kat">
      <option value="">— tanpa kategori —</option>
      ${kat.map((k) => `<option value="${k.id}" ${p?.kategori_id === k.id ? 'selected' : ''}>${esc(k.nama)}</option>`).join('')}
    </select></div>
    <div class="field"><label>Jatah belanja santri</label><select id="mb-jatah">
      <option value="makanan" ${p?.kategori !== 'non_makanan' ? 'selected' : ''}>Makanan</option>
      <option value="non_makanan" ${p?.kategori === 'non_makanan' ? 'selected' : ''}>Non-makanan</option>
    </select>
      <span class="muted set-hint">Menentukan jatah harian mana yang terpakai saat santri membeli
        pakai kartu — berbeda dari kategori etalase di atas.</span></div>
    <div class="row" style="gap:10px">
      <div class="field"><label>Harga beli (HPP)</label>${inputUang({ id: 'mb-hpp', nilai: p?.hpp || '' })}</div>
      <div class="field"><label>Harga jual</label>${inputUang({ id: 'mb-harga', nilai: p?.harga || '' })}</div>
    </div>
    <p class="muted" id="mb-margin" style="margin:0 0 10px"></p>
    <div class="row" style="gap:10px">
      ${p ? '' : `<div class="field"><label>Stok awal</label>
        <input type="number" id="mb-stok" min="0" value="0"></div>`}
      <div class="field"><label>Ambang "menipis"</label>
        <input type="number" id="mb-min" min="0" value="${p?.stok_min ?? 5}">
        <span class="muted set-hint">Stok di bawah/sama dengan angka ini ditandai kuning.</span></div>
    </div>
    <div class="field"><label>Tanggal expired <span class="muted">(opsional)</span></label>
      <input type="date" id="mb-exp" value="${esc(p?.expired || '')}">
      <span class="muted set-hint">Ditandai kuning mulai 50 hari sebelum tanggalnya, merah mulai 30 hari.
        Diperbarui sendiri setiap nota pembelian yang menyebut tanggalnya.</span></div>
    <fieldset class="ortu-set"><legend>Dijual grosir (dus/karton)</legend>
      <span class="muted set-hint" style="display:block; margin-bottom:8px">Kosongkan kalau barang ini
        hanya dijual satuan. Stoknya tetap satu dan dihitung dalam satuan eceran — menjual 1 dus
        memotong stok sebanyak isinya.</span>
      <div class="row" style="gap:10px">
        <div class="field"><label>Isi per grosir (pcs)</label>
          <input type="number" id="mb-isi" min="2" value="${p?.isi_grosir ?? ''}" placeholder="mis. 12"></div>
        <div class="field"><label>Harga beli grosir</label>
          ${inputUang({ id: 'mb-hppg', nilai: p?.hpp_grosir || '' })}</div>
        <div class="field"><label>Harga jual grosir</label>
          ${inputUang({ id: 'mb-hargag', nilai: p?.harga_grosir || '' })}</div>
      </div>
      <div class="field"><label>Barcode dus (opsional)</label>
        <input id="mb-barcode-grosir" data-scan="1" maxlength="32" autocomplete="off"
          value="${esc(p?.barcode_grosir || '')}" placeholder="pindai barcode kartonnya">
        <span class="muted set-hint">Karton biasanya punya barcode sendiri yang berbeda dari
          barcode bungkusnya. Diisi di sini, memindainya di kasir langsung masuk sebagai
          <b>satu dus</b> — bukan satu pcs yang lalu ditukar manual.</span>
        <span class="minus hidden" id="mb-barcode-grosir-cek" style="font-size:12.5px"></span></div>
      <p class="muted" id="mb-margin-g" style="margin:0"></p>
    </fieldset>
    ${p ? `<label class="cek"><input type="checkbox" id="mb-aktif" ${p.aktif ? 'checked' : ''}>
      ditampilkan di kasir</label>` : ''}`,
    async () => {
      const body = {
        nama: $('#mb-nama').value, barcode: $('#mb-barcode').value,
        kategori_id: $('#mb-kat').value || null, kategori: $('#mb-jatah').value,
        hpp: $('#mb-hpp').value.trim() === '' ? null : nilaiUang('#mb-hpp'),
        harga: nilaiUang('#mb-harga'), stok_min: Number($('#mb-min').value || 5),
        expired: $('#mb-exp').value || '',
        isi_grosir: $('#mb-isi').value === '' ? null : Number($('#mb-isi').value),
        hpp_grosir: $('#mb-hppg').value.trim() === '' ? null : nilaiUang('#mb-hppg'),
        harga_grosir: $('#mb-hargag').value.trim() === '' ? null : nilaiUang('#mb-hargag'),
        barcode_grosir: $('#mb-barcode-grosir').value,
      };
      // Dihentikan di sini juga, bukan hanya di server: kasir mendapat
      // jawabannya seketika, tanpa menunggu bolak-balik jaringan. Servernya
      // tetap menolak (MartStokController::simpan) — layar bukan pagarnya.
      if (body.hpp !== null && body.harga && body.hpp > body.harga) {
        throw new Error(`Harga beli ${rp(body.hpp)} lebih tinggi daripada harga jual ${rp(body.harga)} — `
          + 'barang ini akan rugi tiap kali terjual. Betulkan salah satunya dulu.');
      }
      if (!p) body.stok = Number($('#mb-stok').value || 0);
      else body.aktif = $('#mb-aktif').checked ? 1 : 0;
      if (p) await api('/api/mart/barang/' + p.id, { method: 'PUT', body });
      else await api('/api/mart/barang', { method: 'POST', body });
      toast('✔ Barang tersimpan', 'ok');
      martMuatStok();
    });

  const hitungMargin = () => {
    const h = nilaiUang('#mb-harga') || 0;
    const m = nilaiUang('#mb-hpp') || 0;
    $('#mb-margin').innerHTML = h > 0 && m > 0
      ? `Untung <b>${rp(h - m)}</b> per satuan · margin <b>${(((h - m) / h) * 100).toFixed(1)}%</b>` +
        (m > h ? ' <b class="minus">— rugi tiap terjual, tidak bisa disimpan</b>'
          : m === h ? ' <b class="minus">— laba nol, pas di harga modal</b>' : '')
      : 'Isi harga beli & jual untuk melihat margin.';
  };
  ['#mb-hpp', '#mb-harga'].forEach((s) => $(s).addEventListener('input', hitungMargin));
  hitungMargin();

  // Grosir yang tidak lebih murah per satuan bukan grosir — dikatakan sejak
  // di layar, meski servernya juga menolak.
  const hitungGrosir = () => {
    const isi = Number($('#mb-isi').value || 0);
    const hg = nilaiUang('#mb-hargag') || 0;
    const mg = nilaiUang('#mb-hppg') || 0;
    const satuan = nilaiUang('#mb-harga') || 0;
    if (!isi || !hg) { $('#mb-margin-g').textContent = 'Kosongkan bila barang ini tidak dijual grosir.'; return; }
    const perPcs = hg / isi;
    const hemat = satuan ? satuan * isi - hg : 0;
    $('#mb-margin-g').innerHTML = `Setara <b>${rp(Math.round(perPcs))}</b> / pcs`
      + (satuan ? ` · pembeli hemat <b>${rp(hemat)}</b> per ${isi} pcs` : '')
      + (mg ? ` · untung <b>${rp(hg - mg)}</b> per grosir` : '')
      + (satuan && hg >= satuan * isi
        ? ' <b class="minus">— tidak lebih murah daripada eceran, tidak bisa disimpan</b>' : '')
      + (mg && mg > hg ? ' <b class="minus">— rugi tiap terjual</b>' : '');
  };
  /*
   * Barcode dus mengikuti nasib setelan grosirnya: begitu isi atau harga
   * dusnya dikosongkan, kotak barcode dus ikut dikosongkan dan dikunci.
   * Server menolak kombinasi itu (barcode dus tanpa harga dus adalah baris
   * keranjang yang tidak bisa dihargai), dan membiarkan kotaknya tetap terisi
   * hanya akan memunculkan galat yang sebabnya tidak kelihatan di layar.
   */
  const kunciBarcodeDus = () => {
    const siap = Number($('#mb-isi').value || 0) >= 2 && nilaiUang('#mb-hargag') > 0;
    const kotak = $('#mb-barcode-grosir');
    kotak.disabled = !siap;
    kotak.placeholder = siap ? 'pindai barcode kartonnya'
      : 'isi dulu isi & harga jual grosirnya';
    if (!siap) kotak.value = '';
  };
  ['#mb-isi', '#mb-hppg', '#mb-hargag', '#mb-harga'].forEach((x) =>
    $(x).addEventListener('input', () => { hitungGrosir(); kunciBarcodeDus(); }));
  hitungGrosir();
  kunciBarcodeDus();

  // Cek dobel seketika dari martStok.produk (sudah di memori) — server
  // tetap menolak saat simpan (409), ini cuma peringatan dini.
  const cekBarcode = (kotak, pesan, tombol) => {
    const kode = $(kotak).value.trim();
    const el = $(pesan);
    // Dicocokkan ke KEDUA kolom: satu kode harus menunjuk tepat satu
    // (barang, satuan), jadi barcode satuan yang menabrak barcode dus barang
    // lain pun ambigu saat dipindai.
    const x = kode ? (martStok.produk || []).find((b) => b.id !== p?.id
      && (b.barcode === kode || b.barcode_grosir === kode)) : null;
    if (!x) { el.classList.add('hidden'); el.innerHTML = ''; return; }
    const sebagai = x.barcode_grosir === kode ? 'barcode dus' : 'barcode satuan';
    el.innerHTML = `⚠ Sudah dipakai sebagai ${sebagai}: <b>${esc(x.nama)}</b>
      (stok ${x.stok ?? '—'}) —
      <button type="button" class="linkbtn danger" id="${tombol}">ubah barang itu</button>`;
    el.classList.remove('hidden');
    $('#' + tombol).onclick = () => { closeModal(); martBarangModal(x); };
  };
  const cekEcer = () => cekBarcode('#mb-barcode', '#mb-barcode-cek', 'mb-barcode-ubah');
  const cekDus = () => cekBarcode('#mb-barcode-grosir', '#mb-barcode-grosir-cek', 'mb-barcode-grosir-ubah');
  $('#mb-barcode').addEventListener('input', debounce(cekEcer, 250));
  $('#mb-barcode-grosir').addEventListener('input', debounce(cekDus, 250));
  cekEcer();
  cekDus();

  // Pindaian jatuh ke kotak yang sedang disorot — petugas yang baru menekan
  // kotak barcode dus lalu memindai karton tidak perlu mengetik ulang.
  cardHandler = (uid) => {
    const dus = document.activeElement && document.activeElement.id === 'mb-barcode-grosir';
    const f = $(dus ? '#mb-barcode-grosir' : '#mb-barcode');
    if (!f || f.disabled) return;
    f.value = uid;
    if (dus) cekDus(); else cekEcer();
  };
}

function martStokModal(p) {
  openModal(`
    <h2>Stok — ${esc(p.nama)}</h2>
    <p class="muted">Stok sekarang <b>${p.stok === null ? 'belum dilacak' : p.stok}</b>.
      Semua pergerakan tercatat beserta alasannya.</p>
    <div class="field"><label>Jenis</label><select id="mst-jenis">
      <option value="masuk">Barang masuk (dari sales)</option>
      <option value="koreksi">Koreksi manual (+/−)</option>
    </select></div>
    <div class="field"><label>Jumlah</label>
      <input type="number" id="mst-qty" value="0">
      <span class="muted set-hint">Untuk koreksi, boleh negatif (mis. −2 untuk barang rusak).</span></div>
    <div class="field"><label>Keterangan</label>
      <input id="mst-ket" maxlength="200" placeholder="mis. kiriman Pak Sopir 12 dus"></div>
    <div id="mst-riwayat" class="muted">memuat riwayat…</div>`,
    async () => {
      await api(`/api/mart/barang/${p.id}/stok`, { method: 'POST', body: {
        jenis: $('#mst-jenis').value, qty: Number($('#mst-qty').value || 0),
        keterangan: $('#mst-ket').value,
      }});
      toast('✔ Stok diperbarui', 'ok');
      martMuatStok();
    }, 'Simpan');

  api(`/api/mart/barang/${p.id}/mutasi`).then((d) => {
    const box = $('#mst-riwayat');
    if (!box) return;
    box.innerHTML = d.mutasi.length ? `<div class="tblwrap" style="max-height:220px; overflow:auto"><table>
      <thead><tr><th>Waktu</th><th>Jenis</th><th class="num">Qty</th><th class="num">Sisa</th><th>Ket</th></tr></thead>
      <tbody>${d.mutasi.map((m) => `<tr>
        <td>${new Date(m.created_at).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' })}</td>
        <td>${esc(m.jenis)}</td>
        <td class="num" style="color:${m.qty > 0 ? 'var(--ok)' : 'var(--err)'}">${m.qty > 0 ? '+' : ''}${m.qty}</td>
        <td class="num">${m.stok_sesudah}</td>
        <td class="muted">${esc(m.keterangan || m.ref || '')}</td></tr>`).join('')}</tbody>
    </table></div>` : '<span class="muted">Belum ada pergerakan.</span>';
  }).catch(() => {});
}

async function martKategoriModal() {
  const d = await api('/api/mart/kategori');
  openModal(`
    <h2>Kategori Barang</h2>
    <p class="muted">Kategori etalase untuk menyaring barang di kasir (Sembako, ATK, …).
      Menghapus kategori <b>tidak</b> menghapus barangnya — barang itu hanya kehilangan labelnya.</p>
    <div class="gol-list">${d.kategori.map((k) => `
      <div class="gol-baris"><div class="gol-kepala">
        <b>${esc(k.nama)}</b><span class="muted">${k.jumlah} barang</span>
        ${k.aktif ? '' : '<span class="badge">nonaktif</span>'}
        <button class="linkbtn" data-ubah-kat="${k.id}">ubah</button>
        <button class="linkbtn danger" data-hapus-kat="${k.id}">hapus</button>
      </div></div>`).join('') || '<p class="muted">Belum ada kategori.</p>'}</div>
    <button class="linkbtn" id="mk-tambah">+ Tambah kategori</button>`);

  const segar = () => { closeModal(); martKategoriModal(); };
  const form = (k) => openModal(`
    <h2>${k ? 'Ubah' : 'Tambah'} Kategori</h2>
    <div class="field"><label>Nama kategori</label>
      <input id="mkf-nama" value="${esc(k?.nama || '')}" maxlength="40" placeholder="mis. Sembako"></div>`,
    async () => {
      const body = { nama: $('#mkf-nama').value };
      if (k) await api('/api/mart/kategori/' + k.id, { method: 'PUT', body });
      else await api('/api/mart/kategori', { method: 'POST', body });
      toast('✔ Kategori tersimpan', 'ok');
      segar();
      martMuatStok();
    });

  $('#mk-tambah').onclick = () => form(null);
  document.querySelectorAll('[data-ubah-kat]').forEach((b) => {
    b.onclick = () => form(d.kategori.find((k) => k.id === Number(b.dataset.ubahKat)));
  });
  document.querySelectorAll('[data-hapus-kat]').forEach((b) => {
    b.onclick = async () => {
      try {
        await api('/api/mart/kategori/' + b.dataset.hapusKat, { method: 'DELETE' });
        toast('✔ Kategori dihapus', 'ok');
        segar();
        martMuatStok();
      } catch (e) { toast(e.message, 'err'); }
    };
  });
}

function martUnduhBarang() {
  const d = martStok.produk || [];
  unduhCsv(`barang-mart-${new Date().toISOString().slice(0, 10)}.csv`,
    // Expired ikut diunduh: layar sudah punya kolomnya, dan unduhan yang
    // tertinggal dari layar adalah persis cacat yang diperbaiki di Laporan
    // Keuangan (angka layar vs unduhan tidak sama).
    ['SKU', 'SKU dus', 'Nama', 'Kategori', 'Jatah santri', 'Harga beli', 'Harga jual', 'Untung', 'Margin %',
      'Stok', 'Status', 'Expired', 'Sisa hari'],
    d.map((p) => [p.barcode || '', p.barcode_grosir || '', p.nama, p.kategori_nama || '', p.kategori,
      p.hpp || '', p.harga, p.untung ?? '', p.margin ?? '', p.stok ?? '', p.status,
      p.expired || '', p.expired_hari ?? '']));
}

function martCetakBarang() {
  const d = martStok.produk || [];
  const gaya = `body{font:12px/1.45 system-ui;margin:16px} h1{font-size:16px;margin:0 0 2px}
    table{width:100%;border-collapse:collapse;margin-top:10px} th,td{border:1px solid #bbb;padding:5px 7px;text-align:left}
    th{background:#eee} .n{text-align:right}`;
  cetakLembar('Daftar Barang', gaya, `
    <h1>Daftar Barang &amp; Stok — ${esc(USER?.tenant_nama || 'IMBOS Mart')}</h1>
    <div>${new Date().toLocaleString('id-ID')} · ${d.length} barang</div>
    <table><thead><tr><th>SKU</th><th>Nama</th><th>Kategori</th><th class="n">Beli</th>
      <th class="n">Jual</th><th class="n">Margin</th><th class="n">Stok</th>
      <th>Expired</th></tr></thead>
    <tbody>${d.map((p) => `<tr><td>${esc(p.barcode || '-')}${
      p.barcode_grosir ? `<br><small>dus: ${esc(p.barcode_grosir)}</small>` : ''}</td><td>${esc(p.nama)}</td>
      <td>${esc(p.kategori_nama || '-')}</td><td class="n">${p.hpp ? rp(p.hpp) : '-'}</td>
      <td class="n">${rp(p.harga)}</td><td class="n">${p.margin ?? '-'}%</td>
      <td class="n">${p.stok ?? '-'}</td>
      <td>${esc(p.expired || '-')}${p.expired_hari !== null && p.expired_hari !== undefined
        ? ` (${p.expired_hari} hr)` : ''}</td></tr>`).join('')}</tbody></table>`);
}

// ------------------------------------------------- DASHBOARD & LAPORAN

let martDash = null;

function martDashView(el) {
  el.innerHTML = `<div id="md-isi" class="muted">memuat laporan…</div>`;
  martMuatDash();
}

async function martMuatDash() {
  try {
    martDash = await api('/api/mart/dashboard');
  } catch (e) { $('#md-isi').innerHTML = `<div class="panel minus">${esc(e.message)}</div>`; return; }
  const d = martDash;
  // Panel "Omset" dihapus (keputusan rapat tim kasir): angkanya sudah ada di
  // menu Riwayat, yang punya rentang tanggal bebas — bukan preset 7/30 hari.
  // Dua angka lain yang dulu menumpang di panel itu tetap ada di layarnya
  // masing-masing: nilai stok di Barang & Stok, piutang di Member & Piutang.
  $('#md-isi').innerHTML = `
  <div class="panel">
    <div class="kat-head"><h2 style="margin:0">Laporan Keuangan Rinci</h2>
      <button class="linkbtn" id="md-keu-toggle">▾ Tampilkan laporan</button></div>
    <div id="md-keu-box" class="hidden">
      <span style="display:flex; gap:10px; align-items:baseline; margin-bottom:8px">
        <select id="md-periode" class="k-chip-select" style="width:auto">
          <option value="hari">Hari ini</option><option value="pekan">7 hari</option>
          <option value="bulan" selected>30 hari</option><option value="semua">Semua</option>
        </select>
        <button class="linkbtn" id="md-unduh-keu">⬇ unduh CSV</button> ·
        <button class="linkbtn" id="md-cetak-keu">🖨 cetak / PDF</button>
      </span>
      <div id="md-keu" class="muted">memuat…</div>
    </div>
  </div>

  <div class="row">
    <div class="panel">
      <h2>Peringatan Stok</h2>
      ${martDaftarStok('Habis — tidak bisa dijual', d.stok.habis, 'minus')}
      ${martDaftarStok('Menipis — segera belanja', d.stok.menipis, 'warn')}
      ${!d.stok.habis.length && !d.stok.menipis.length
        ? '<p class="muted">Semua stok aman. 👍</p>' : ''}
    </div>
    <div class="panel">
      <div class="kat-head"><h2 style="margin:0">Barang Terlaris <span class="muted">30 hari</span></h2>
        <button class="linkbtn" id="md-unduh-laris">⬇ unduh</button></div>
      <div class="tblwrap"><table>
        <thead><tr><th>Barang</th><th class="num">Terjual</th>
          <th class="num" title="dihitung sebelum diskon nota">Nilai (sblm diskon)</th></tr></thead>
        <tbody>${d.terlaris.map((b) => `<tr><td>${esc(b.nama)}</td>
          <td class="num"><b>${b.qty}</b></td><td class="num rp">${rp(b.nilai)}</td></tr>`).join('')
          || '<tr><td colspan="3" class="muted">Belum ada penjualan.</td></tr>'}</tbody>
      </table></div>
      <h2 style="margin-top:16px">Tidak Laku <span class="muted">belum terjual 30 hari</span></h2>
      <div class="tblwrap"><table>
        <thead><tr><th>Barang</th><th class="num">Harga</th><th class="num">Stok</th></tr></thead>
        <tbody>${d.tidak_laku.map((b) => `<tr><td>${esc(b.nama)}</td>
          <td class="num rp">${rp(b.harga)}</td><td class="num">${b.stok ?? '—'}</td></tr>`).join('')
          || '<tr><td colspan="3" class="muted">Semua barang laku. 👍</td></tr>'}</tbody>
      </table></div>
    </div>
  </div>

  <div class="panel">
    <div class="kat-head"><h2 style="margin:0">Penutupan Kas Terakhir</h2>
      <button class="linkbtn" id="md-unduh-kas">⬇ unduh</button></div>
    <div class="tblwrap"><table>
      <thead><tr><th>Tanggal</th><th class="num">Tunai</th><th class="num">Hitung laci</th>
        <th class="num">Selisih laci</th><th class="num">Transfer</th><th class="num">Selisih transfer</th>
        <th>Operator</th></tr></thead>
      <tbody>${(d.kas_terakhir || []).map((k) => `<tr>
        <td>${esc(k.tanggal)}</td><td class="num rp">${rp(k.tunai)}</td>
        <td class="num rp">${rp(k.hitung_fisik)}</td>
        <td class="num rp" style="color:${k.selisih ? 'var(--err)' : 'var(--ok)'}">${rp(k.selisih)}</td>
        <td class="num rp">${rp(k.transfer)}</td>
        <td class="num rp" style="color:${k.selisih_transfer ? 'var(--err)' : 'var(--ok)'}">${rp(k.selisih_transfer)}</td>
        <td>${esc(k.operator)}</td></tr>`).join('')
        || '<tr><td colspan="7" class="muted">Belum ada penutupan kas.</td></tr>'}</tbody>
    </table></div>
  </div>`;

  /*
   * Laporan ini TERSEMBUNYI sampai diminta (keputusan rapat tim kasir:
   * "perbaiki bugnya, lalu sembunyikan dulu menunya").
   *
   * Markup-nya sengaja tidak dihapus — yang dihapus akan ikut membuang
   * perbaikan hitungannya, dan melepas markup tanpa melepas pemasangan
   * handler di bawah justru melempar galat null. Disembunyikan lewat pola
   * toggle yang sudah dipakai di layar lain.
   *
   * Kuerinya juga ikut ditunda: laporan ini menggabungkan dua buku dan
   * menghitung HPP, dan itu tidak perlu dijalankan pada setiap pembukaan
   * Dashboard untuk panel yang tertutup.
   */
  $('#md-keu-toggle').onclick = () => {
    const tampil = !$('#md-keu-box').classList.toggle('hidden');
    $('#md-keu-toggle').textContent = tampil ? '▴ Sembunyikan laporan' : '▾ Tampilkan laporan';
    // Selalu dimuat ulang saat dibuka, bukan hanya saat pertama: ini laporan
    // uang, dan dibuka lagi setelah berpindah layar harus memberi angka hari
    // ini — bukan sisa angka yang kebetulan masih tersimpan.
    if (tampil) martMuatKeuangan();
  };
  $('#md-periode').onchange = martMuatKeuangan;
  $('#md-unduh-laris').onclick = () => unduhCsv(`terlaris-mart-${martHariIni()}.csv`,
    ['Barang', 'Qty terjual', 'Nilai'], d.terlaris.map((b) => [b.nama, b.qty, b.nilai]));
  $('#md-unduh-kas').onclick = () => unduhCsv(`tutup-kas-mart-${martHariIni()}.csv`,
    ['Tanggal', 'Kas awal', 'Tunai', 'Hitung laci', 'Selisih laci', 'Transfer',
      'Transfer dicocokkan', 'Selisih transfer', 'Operator', 'Catatan'],
    (d.kas_terakhir || []).map((k) => [k.tanggal, k.kas_awal, k.tunai, k.hitung_fisik, k.selisih,
      k.transfer, k.hitung_transfer, k.selisih_transfer, k.operator, k.catatan || '']));
  $('#md-unduh-keu').onclick = martUnduhKeuangan;
  $('#md-cetak-keu').onclick = martCetakKeuangan;
}

const martHariIni = () => new Date().toISOString().slice(0, 10);

function martDaftarStok(judul, daftar, warna) {
  if (!daftar.length) return '';
  return `<h3 style="margin:12px 0 6px; font-size:13.5px; color:var(--${warna})">${judul}
    <span class="muted">(${daftar.length})</span></h3>
    <div class="tblwrap"><table>
      <thead><tr><th>SKU</th><th>Barang</th><th class="num">Stok</th><th class="num">Ambang</th></tr></thead>
      <tbody>${daftar.map((b) => `<tr>
        <td>${b.barcode ? `<code>${esc(b.barcode)}</code>` : '—'}</td>
        <td>${esc(b.nama)}</td>
        <td class="num"><b style="color:var(--${warna})">${b.stok}</b></td>
        <td class="num muted">${b.stok_min}</td></tr>`).join('')}</tbody>
    </table></div>`;
}

const MART_METODE_LABEL = { tunai: '💵 Tunai', transfer: '🏦 Transfer',
  hutang: '📒 Hutang', kartu: '💳 Cashless', pelunasan: '🧾 Pelunasan hutang' };

function martTabelRiwayat(tx) {
  return `<div class="tblwrap"><table>
    <thead><tr><th>Waktu</th><th>Metode</th><th class="num">Total</th><th class="num">Item</th>
      <th>Keterangan</th><th>Operator</th></tr></thead>
    <tbody>${tx.map((x) => `<tr>
      <td>${waktuSingkat(x.waktu)}</td><td>${MART_METODE_LABEL[x.metode] || esc(x.metode)}</td>
      <td class="num rp">${rp(x.total)}</td><td class="num">${x.n_item ?? '—'}</td>
      <td class="muted">${esc(x.ket || '')}</td><td>${esc(x.operator || '')}</td></tr>`).join('')
      || '<tr><td colspan="6" class="muted">Belum ada transaksi.</td></tr>'}</tbody>
  </table></div>`;
}

function martUnduhRiwayat(tx, nama) {
  unduhCsv(`${nama}-mart-${martHariIni()}.csv`,
    ['Waktu', 'Metode', 'Total', 'Jumlah item', 'Keterangan', 'Operator'],
    tx.map((x) => [x.waktu, x.metode, x.total, x.n_item ?? '', x.ket || '', x.operator || '']));
}

let martKeu = null;

async function martMuatKeuangan() {
  const periode = $('#md-periode').value;
  $('#md-keu').innerHTML = '<span class="muted">memuat…</span>';
  try {
    martKeu = await api('/api/mart/keuangan?periode=' + periode);
  } catch (e) { $('#md-keu').innerHTML = `<span class="minus">${esc(e.message)}</span>`; return; }
  const r = martKeu.ringkas;
  // Dua kelompok yang sengaja tidak dicampur: BARANG KELUAR (omzet & laba)
  // dan UANG MASUK (isi laci). Penjualan hutang ada di kelompok pertama tapi
  // tidak di kelompok kedua, dan di situlah letak seluruh kerumitannya.
  /*
   * Urutan barisnya adalah HITUNGAN, dan harus benar-benar bisa dijumlahkan.
   *
   * Sebelumnya "Diskon diberikan" berdiri sebagai baris pengurang tepat di atas
   * baris laba yang ditebalkan — padahal penjualan di sini SUDAH bersih dari
   * diskon (MartJual.total = subtotal − diskon). Pembaca yang menjumlah kolom
   * meleset tepat sebesar diskon, dan baris tebalnya tampak seperti hasil
   * penjumlahan itu. Diskon sekarang turun ke bawah garis sebagai keterangan,
   * dengan kalimat yang menyebut bahwa ia sudah termasuk.
   */
  const baris = [
    ['Penjualan bersih', r.penjualan_kotor, 'nilai barang yang keluar dari rak, SESUDAH diskon dipotong'],
    ['Modal barang terjual (HPP)', -r.hpp, 'harga beli barang yang terjual'],
    ['Laba kotor toko', r.laba_kotor, 'penjualan bersih − modal; belum dikurangi biaya operasional', true],
    ['Diskon yang sudah dipotong', r.diskon, 'bukan pengurang baru — sudah termasuk di penjualan bersih di atas'],
    ['Rata-rata keranjang', r.rata_keranjang, `nilai rata-rata per transaksi dari ${r.n_transaksi} transaksi`],
    ['Pemasukan tunai', r.masuk_tunai ?? r.tunai, 'uang di laci Mart — penjualan tunai + cicilan piutang'],
    ['Pemasukan transfer / QRIS', r.masuk_transfer ?? r.transfer, 'masuk rekening — dicocokkan saat tutup kas'],
    ['Pemasukan cashless (RFID)', r.cashless, 'dibayar saldo kartu; uangnya ada di kas sekolah'],
    ['Penjualan hutang', r.hutang || 0, 'barang keluar, uangnya belum masuk — sudah dihitung sebagai omzet'],
    ['Piutang tertagih periode ini', r.piutang_tertagih || 0, 'cicilan yang diterima; bukan omzet baru'],
    ['Sisa piutang hari ini', r.piutang_sisa || 0, 'seluruh hutang yang belum dibayar, apa pun periodenya'],
  ];
  $('#md-keu').innerHTML = `
    <div class="tblwrap"><table>
      <thead><tr><th>Pos</th><th class="num">Nilai</th><th>Keterangan</th></tr></thead>
      <tbody>${baris.map(([l, v, ket, tebal]) => `<tr${tebal ? ' style="font-weight:700"' : ''}>
        <td>${esc(l)}</td>
        <td class="num rp" style="${v < 0 ? 'color:var(--err)' : ''}">${v < 0 ? '−' : ''}${rp(Math.abs(v))}</td>
        <td class="muted">${esc(ket)}</td></tr>`).join('')}</tbody>
    </table></div>
    <p class="muted">Margin ${r.margin_persen === null ? '—' : r.margin_persen + '%'} ·
      ${r.n_transaksi} transaksi pada periode ini.</p>
    ${martKeu.tanpa_modal?.nilai ? `<p class="minus" style="margin-top:0">
      ⚠ ${rp(martKeu.tanpa_modal.nilai)} dari penjualan di atas (${martKeu.tanpa_modal.n} baris barang)
      <b>tidak punya harga modal tercatat</b> — barang ketikan bebas, atau barang yang HPP-nya belum
      diisi. Nilai itu masuk laba seolah modalnya nol, jadi <b>laba di atas lebih besar daripada yang
      sebenarnya</b>. Isi HPP-nya di Barang &amp; Stok supaya angkanya bisa dipercaya.</p>` : ''}
    <h3 style="font-size:13.5px">Transaksi periode ini
      <span class="muted">(${martKeu.transaksi.length} terakhir${
        martKeu.transaksi.length >= 200 ? ', dibatasi 200 teratas' : ''})</span></h3>
    ${martTabelRiwayat(martKeu.transaksi)}`;
}

function martUnduhKeuangan() {
  if (!martKeu) return;
  const r = martKeu.ringkas;
  unduhCsv(`keuangan-mart-${r.periode}-${martHariIni()}.csv`,
    ['Pos', 'Nilai'],
    [['Periode', r.periode],
      ['Penjualan bersih (sesudah diskon)', r.penjualan_kotor],
      ['Modal barang terjual (HPP)', r.hpp], ['Laba kotor', r.laba_kotor],
      ['Diskon yang sudah dipotong', r.diskon],
      ['Margin %', r.margin_persen ?? ''], ['Jumlah transaksi', r.n_transaksi],
      ['Rata-rata keranjang', r.rata_keranjang],
      // Dulu baris ini memakai r.tunai/r.transfer (penjualan saja) padahal
      // labelnya menyebut laci — unduhannya kehilangan SELURUH cicilan piutang,
      // dan angkanya tidak pernah cocok dengan layar yang memakai masuk_*.
      ['Pemasukan tunai (laci: penjualan + cicilan)', r.masuk_tunai ?? r.tunai],
      ['Pemasukan transfer/QRIS (penjualan + cicilan)', r.masuk_transfer ?? r.transfer],
      ['Pemasukan cashless (RFID)', r.cashless],
      ['Penjualan hutang', r.hutang || 0], ['Piutang tertagih periode ini', r.piutang_tertagih || 0],
      ['Sisa piutang hari ini', r.piutang_sisa || 0],
      ['Penjualan tanpa modal tercatat', martKeu.tanpa_modal?.nilai || 0],
      [], ['Barang terlaris', ''], ['Barang', 'Qty', 'Nilai (sebelum diskon)'],
      ...martKeu.terlaris.map((b) => [b.nama, b.qty, b.nilai])]);
}

function martCetakKeuangan() {
  if (!martKeu) return;
  const r = martKeu.ringkas;
  const LABEL = { hari: 'Hari ini', pekan: '7 hari terakhir', bulan: '30 hari terakhir', semua: 'Seluruh periode' };
  const gaya = `body{font:12px/1.5 system-ui;margin:18px;color:#111} h1{font-size:17px;margin:0}
    h2{font-size:13px;margin:18px 0 6px} table{width:100%;border-collapse:collapse}
    th,td{border:1px solid #bbb;padding:5px 8px;text-align:left} th{background:#eee}
    .n{text-align:right} .tebal td{font-weight:700;background:#f6f6f6}`;
  const b = (l, v, tebal) => `<tr class="${tebal ? 'tebal' : ''}"><td>${esc(l)}</td><td class="n">${rp(v)}</td></tr>`;
  cetakLembar('Laporan Keuangan Mart', gaya, `
    <h1>Laporan Keuangan — ${esc(martKeu.tenant.nama)}</h1>
    <div>${esc(LABEL[r.periode] || r.periode)} · dicetak ${new Date().toLocaleString('id-ID')}</div>
    <h2>Ringkasan</h2>
    <table>${b('Penjualan bersih (sesudah diskon)', r.penjualan_kotor)}
      ${b('Modal barang terjual (HPP)', r.hpp)}${b('Laba kotor toko', r.laba_kotor, true)}
      ${b('Diskon yang sudah dipotong', r.diskon)}
      ${b('Rata-rata keranjang', r.rata_keranjang)}
      <tr><td>Margin</td><td class="n">${r.margin_persen === null ? '—' : r.margin_persen + '%'}</td></tr>
      <tr><td>Jumlah transaksi</td><td class="n">${r.n_transaksi}</td></tr></table>
    <h2>Sumber pemasukan</h2>
    <table>${b('Tunai (laci Mart: penjualan + cicilan)', r.masuk_tunai ?? r.tunai)}
      ${b('Transfer / QRIS (penjualan + cicilan)', r.masuk_transfer ?? r.transfer)}
      ${b('Cashless RFID (kas sekolah)', r.cashless)}
      ${b('Penjualan hutang (uang belum masuk)', r.hutang || 0)}</table>
    <h2>Barang terlaris</h2>
    <table><thead><tr><th>Barang</th><th class="n">Qty</th>
      <th class="n">Nilai (sblm diskon)</th></tr></thead>
      <tbody>${martKeu.terlaris.map((x) => `<tr><td>${esc(x.nama)}</td>
        <td class="n">${x.qty}</td><td class="n">${rp(x.nilai)}</td></tr>`).join('')}</tbody></table>
    <p style="margin-top:16px;font-size:11px;color:#555">Catatan: angka laba di atas adalah LABA KOTOR
      (penjualan − modal barang). Biaya operasional belum dikurangkan. Nilai barang terlaris
      dihitung SEBELUM diskon nota, jadi jumlahnya bisa melebihi penjualan bersih di atas.
      ${martKeu.tanpa_modal?.nilai ? `Sebanyak ${rp(martKeu.tanpa_modal.nilai)} dari penjualan
        tidak punya harga modal tercatat, sehingga laba di atas lebih besar daripada yang
        sebenarnya.` : ''}</p>`);
}

// ----------------------------------------------------- STOK OPNAME (AUDIT)

let opname = null;

/* ════════════════════════════════════════════════════════════════════════
   LAPORAN CLOSING HARIAN — SPD (Sales Per-Day)

   Satu baris per hari: penjualan, modal barangnya, dan marginnya, ditutup
   catatan penutupan kas hari itu. Bentuknya mengikuti format yang dibawa tim
   kasir ke rapat.

   Seluruh hitungannya di server (MartLaporanController::spd) — layar ini tidak
   menjumlahkan apa pun sendiri, supaya angka di layar, di unduhan, dan di
   cetakan tidak mungkin berbeda.
   ════════════════════════════════════════════════════════════════════════ */
let martSpd = null;

function martSpdView(el) {
  const hariIni = martHariIni();
  el.innerHTML = `
  <div class="panel">
    <div class="kat-head"><h2 style="margin:0">Closing Harian <span class="muted" id="spd-ket"></span></h2>
      <span style="display:flex; gap:8px; align-items:center">
        <select id="spd-format" class="k-chip-select" style="width:auto">
          <option value="xls">Excel (.xls)</option>
          <option value="doc">Word (.doc)</option>
          <option value="pdf">PDF (lewat dialog cetak)</option>
        </select>
        <button class="linkbtn" id="spd-unduh">⬇ unduh</button> ·
        <button class="linkbtn" id="spd-cetak">🖨 cetak</button> ·
        <button class="linkbtn" id="spd-copy">📋 copy laporan</button>
      </span></div>
    <div class="filterbar">
      <div class="field"><label>Dari</label><input type="date" id="spd-dari" value="${hariIni}"></div>
      <div class="field"><label>Sampai</label><input type="date" id="spd-sampai" value="${hariIni}"></div>
      <div class="field"><label>Kategori</label><select id="spd-kategori">
        <option value="semua">Semua</option>
        <option value="tertinggi">SPD Tertinggi</option>
        <option value="terendah">SPD Terendah</option>
      </select></div>
      <button class="go" id="spd-go">Terapkan</button>
      <button id="spd-reset">Hari ini</button>
    </div>
    <div id="spd-ringkas" class="cards"></div>
    <div class="tblwrap"><table>
      <thead><tr><th>Tanggal</th><th class="num">Sales Per-Day</th><th class="num">HPP</th>
        <th class="num">Margin</th><th class="num">Margin %</th><th class="num">Transaksi</th>
        <th>Ket</th></tr></thead>
      <tbody id="spd-body"><tr><td colspan="7" class="muted">memuat…</td></tr></tbody>
    </table></div>
    <p class="muted">Margin di sini adalah <b>laba kotor</b> — penjualan dikurangi modal barang
      yang terjual. Biaya operasional (gaji, listrik, susut) belum dikurangkan.</p>
  </div>`;

  $('#spd-go').onclick = martMuatSpd;
  $('#spd-kategori').onchange = martMuatSpd;
  $('#spd-reset').onclick = () => {
    $('#spd-dari').value = hariIni;
    $('#spd-sampai').value = hariIni;
    $('#spd-kategori').value = 'semua';
    martMuatSpd();
  };
  $('#spd-unduh').onclick = martUnduhSpd;
  $('#spd-cetak').onclick = martCetakSpd;
  $('#spd-copy').onclick = martCopySpd;
  martMuatSpd();
}

/**
 * Salin ringkasan HARI INI + rekap bulan berjalan ke clipboard, siap tempel
 * ke WhatsApp — bukan rentang tanggal yang sedang disaring di layar.
 * "Copy laporan" dimaksudkan sebagai laporan harian rutin, jadi tombol ini
 * tidak bergantung pada filter tanggal yang sedang dipilih.
 */
async function martCopySpd() {
  const b = $('#spd-copy');
  b.disabled = true;
  const asli = b.textContent;
  try {
    const { teks } = await api('/api/mart/spd/teks');
    try {
      await navigator.clipboard.writeText(teks);
    } catch {
      // Fallback untuk peramban/konteks yang menolak Clipboard API.
      const ta = document.createElement('textarea');
      ta.value = teks;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
    }
    toast('✔ Teks laporan disalin — tempel di WhatsApp', 'ok');
  } catch (e) {
    toast(e.message, 'err');
  } finally {
    b.disabled = false;
    b.textContent = asli;
  }
}

/** Saringan layar sebagai query — dipakai bersama oleh daftar & unduhan. */
function martSpdQs() {
  const p = new URLSearchParams();
  if ($('#spd-dari').value) p.set('dari', $('#spd-dari').value);
  if ($('#spd-sampai').value) p.set('sampai', $('#spd-sampai').value);
  p.set('kategori', $('#spd-kategori').value || 'semua');

  return p.toString();
}

async function martMuatSpd() {
  $('#spd-body').innerHTML = '<tr><td colspan="7" class="muted">memuat…</td></tr>';
  try {
    martSpd = await api('/api/mart/spd?' + martSpdQs());
  } catch (e) {
    $('#spd-body').innerHTML = `<tr><td colspan="7" class="minus">${esc(e.message)}</td></tr>`;
    $('#spd-ringkas').innerHTML = '';
    return;
  }
  const t = martSpd.total;
  $('#spd-ket').textContent = `— ${martSpd.dari} s/d ${martSpd.sampai}`;
  $('#spd-ringkas').innerHTML = `
    <div class="statcard"><span class="muted">Total Sales Per-Day</span><br>
      <b class="rp">${rp(t.spd)}</b>
      <span class="muted" style="display:block; font-size:11.5px">${t.n_hari} hari berjualan ·
        ${t.n_transaksi} transaksi</span></div>
    <div class="statcard"><span class="muted">Total HPP</span><br><b class="rp">${rp(t.hpp)}</b></div>
    <div class="statcard" style="border-color:var(--brand)"><span class="muted">Margin</span><br>
      <b class="rp" style="color:var(--brand)">${rp(t.margin)}</b>
      <span class="muted" style="display:block; font-size:11.5px">${
        t.margin_persen === null ? '—' : t.margin_persen + '%'} dari penjualan</span></div>
    <div class="statcard"><span class="muted">Rata-rata per hari</span><br>
      <b class="rp">${rp(t.rata_spd)}</b></div>`;

  $('#spd-body').innerHTML = martSpd.baris.map((b) => `
    <tr>
      <td>${esc(b.tanggal)}</td>
      <td class="num rp"><b>${rp(b.spd)}</b></td>
      <td class="num rp muted">${rp(b.hpp)}</td>
      <td class="num rp">${rp(b.margin)}</td>
      <td class="num">${b.margin_persen === null ? '—' : b.margin_persen + '%'}</td>
      <td class="num">${b.n_transaksi}</td>
      <td class="muted">${b.kas_ditutup
        ? esc(b.ket || '') + (b.selisih_kas ? ` <span class="minus">selisih laci ${rp(b.selisih_kas)}</span>` : '')
        : '<span class="badge menipis">kas belum ditutup</span>'}</td>
    </tr>`).join('')
    || '<tr><td colspan="7" class="muted">Tidak ada penjualan maupun penutupan kas pada rentang ini.</td></tr>';
}

/**
 * Unduhan diambil dari SERVER, bukan disusun ulang di layar.
 *
 * Excel & Word dilayani endpoint yang sama dengan tabelnya, jadi angkanya
 * mustahil berbeda dari yang barusan dilihat. "PDF" jatuh ke dialog cetak
 * peramban — tidak ada pembangkit PDF di server ini, dan dialog cetak sudah
 * menyediakan "Simpan sebagai PDF" di semua peramban yang dipakai sekolah.
 */
function martUnduhSpd() {
  const fmt = $('#spd-format').value;
  if (fmt === 'pdf') { martCetakSpd(); return; }
  // Berkasnya non-JSON: lewat <a>, bukan fetch (pola sama dengan template impor).
  const a = document.createElement('a');
  a.href = `/api/mart/spd?${martSpdQs()}&format=${fmt}`;
  document.body.appendChild(a);
  a.click();
  a.remove();
}

function martCetakSpd() {
  if (!martSpd) { toast('Belum ada data untuk dicetak', 'err'); return; }
  const t = martSpd.total;
  const gaya = `body{font:12px/1.5 system-ui;margin:18px;color:#111} h1{font-size:17px;margin:0}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th,td{border:1px solid #bbb;padding:5px 8px;text-align:left} th{background:#eee}
    .n{text-align:right} .tebal td{font-weight:700;background:#f6f6f6}`;
  cetakLembar('Closing Harian Mart', gaya, `
    <h1>Laporan Closing Harian (SPD) — ${esc(martSpd.tenant.nama)}</h1>
    <div>${esc(martSpd.dari)} s/d ${esc(martSpd.sampai)} ·
      dicetak ${new Date().toLocaleString('id-ID')}</div>
    <table>
      <thead><tr><th>Tanggal</th><th class="n">Sales Per-Day</th><th class="n">HPP</th>
        <th class="n">Margin</th><th class="n">Margin %</th><th class="n">Transaksi</th>
        <th>Ket</th></tr></thead>
      <tbody>${martSpd.baris.map((b) => `<tr>
        <td>${esc(b.tanggal)}</td><td class="n">${rp(b.spd)}</td><td class="n">${rp(b.hpp)}</td>
        <td class="n">${rp(b.margin)}</td>
        <td class="n">${b.margin_persen === null ? '—' : b.margin_persen + '%'}</td>
        <td class="n">${b.n_transaksi}</td>
        <td>${b.kas_ditutup ? esc(b.ket || '') : 'kas belum ditutup'}</td></tr>`).join('')}
        <tr class="tebal"><td>TOTAL (${t.n_hari} hari)</td><td class="n">${rp(t.spd)}</td>
          <td class="n">${rp(t.hpp)}</td><td class="n">${rp(t.margin)}</td>
          <td class="n">${t.margin_persen === null ? '—' : t.margin_persen + '%'}</td>
          <td class="n">${t.n_transaksi}</td><td></td></tr>
      </tbody>
    </table>
    <p style="margin-top:14px;font-size:11px;color:#555">Margin di atas adalah LABA KOTOR
      (penjualan − modal barang terjual); biaya operasional belum dikurangkan.
      Rata-rata ${rp(t.rata_spd)} per hari berjualan.</p>`);
}

function martOpnameView(el) {
  el.innerHTML = '<div id="mo-isi" class="muted">memuat…</div>';
  martMuatOpname();
}

async function martMuatOpname() {
  try {
    opname = await api('/api/mart/opname');
  } catch (e) { $('#mo-isi').innerHTML = `<div class="panel minus">${esc(e.message)}</div>`; return; }

  $('#mo-isi').innerHTML = opname.berjalan ? martOpnameBerjalan() : martOpnameKosong();
  if (opname.berjalan) martPasangOpname();
  else $('#mo-mulai').onclick = martMulaiOpname;
  const u = $('#mo-unduh-riwayat');
  if (u) u.onclick = () => unduhCsv(`riwayat-opname-${martHariIni()}.csv`,
    ['Mulai', 'Selesai', 'Item', 'Surplus', 'Defisit', 'Nilai surplus', 'Nilai defisit',
      'Nilai bersih', 'Operator', 'Catatan'],
    opname.riwayat.map((o) => [o.created_at, o.selesai_pada, o.n_item, o.n_surplus, o.n_defisit,
      o.nilai_surplus, o.nilai_defisit, o.nilai_bersih, o.operator, o.catatan || '']));
  document.querySelectorAll('#mo-isi [data-detail]').forEach((b) => {
    b.onclick = () => martOpnameDetail(Number(b.dataset.detail));
  });
}

function martRiwayatOpname() {
  return `<div class="panel">
    <div class="kat-head"><h2 style="margin:0">Riwayat Opname</h2>
      <button class="linkbtn" id="mo-unduh-riwayat">⬇ unduh CSV</button></div>
    <div class="tblwrap"><table>
      <thead><tr><th>Selesai</th><th class="num">Item</th><th class="num">Surplus</th>
        <th class="num">Defisit</th><th class="num">Nilai selisih</th><th>Operator</th><th></th></tr></thead>
      <tbody>${opname.riwayat.map((o) => `<tr>
        <td>${o.selesai_pada ? waktuSingkat(o.selesai_pada) : '—'}</td>
        <td class="num">${o.n_item}</td>
        <td class="num" style="color:var(--ok)">${o.n_surplus}</td>
        <td class="num minus">${o.n_defisit}</td>
        <td class="num rp" style="color:${o.nilai_bersih < 0 ? 'var(--err)' : 'var(--ok)'}">
          ${o.nilai_bersih < 0 ? '−' : ''}${rp(Math.abs(o.nilai_bersih))}</td>
        <td>${esc(o.operator)}</td>
        <td><button class="linkbtn" data-detail="${o.id}">rincian</button></td></tr>`).join('')
        || '<tr><td colspan="7" class="muted">Belum pernah opname.</td></tr>'}</tbody>
    </table></div>
  </div>`;
}

function martOpnameKosong() {
  return `<div class="panel">
    <h2>Stok Opname (Audit Fisik)</h2>
    <p class="muted">Opname adalah menghitung barang yang <b>benar-benar ada di rak</b>, lalu
      membandingkannya dengan angka di sistem. Selisihnya bukan kesalahan yang harus disembunyikan —
      ia justru satu-satunya cara mengetahui adanya barang susut, rusak, atau salah catat.</p>
    <ol class="muted" style="margin:0 0 14px; padding-left:20px; line-height:1.9">
      <li><b>Mulai opname</b> — sistem memotret stok buku saat ini.</li>
      <li><b>Hitung fisik</b> — isi jumlah nyata per barang (boleh bertahap, boleh dicetak dulu).</li>
      <li><b>Selesaikan &amp; Rekonsiliasi Auto</b> — stok buku disamakan ke hitungan fisik dan
        nilai selisihnya dicatat sebagai rugi/laba inventaris Mart.</li>
    </ol>
    <p class="muted"><b>Kerjakan saat toko tutup.</b> Stok buku dipotret di langkah 1 dan tidak
      bergerak lagi setelahnya — penjualan yang terjadi di tengah opname akan terbaca sebagai
      selisih, dan itu memang disengaja supaya tidak ada yang tertutupi diam-diam.</p>
    <p class="muted">Selisih opname dicatat di <b>buku Mart sendiri</b> dan tidak pernah menyentuh
      neraca kas sekolah — tidak ada uang yang berpindah, yang berubah hanya nilai barang di rak.</p>
    <button class="go" id="mo-mulai">Mulai Opname Baru</button>
  </div>
  ${martRiwayatOpname()}`;
}

async function martMulaiOpname() {
  if (!confirm('Mulai sesi opname baru? Stok buku akan dipotret sekarang.')) return;
  try {
    await api('/api/mart/opname', { method: 'POST' });
    toast('✔ Opname dimulai — silakan hitung fisik', 'ok');
    martMuatOpname();
  } catch (e) { toast(e.message, 'err'); }
}

function martOpnameBerjalan() {
  const o = opname.berjalan;
  const belum = o.item.filter((i) => i.stok_fisik === null).length;
  return `<div class="panel">
    <div class="kat-head">
      <h2 style="margin:0">Opname Berjalan <span class="muted">dimulai ${waktuSingkat(o.created_at)}</span></h2>
      <span>
        <button class="linkbtn" id="mo-cetak-form">🖨 Cetak Form Fisik</button> ·
        <button class="linkbtn" id="mo-unduh">⬇ unduh CSV</button> ·
        <button class="linkbtn danger" id="mo-batal">batalkan sesi</button>
      </span></div>
    <div class="cards">
      <div class="statcard"><span class="muted">Barang diaudit</span><br><b>${o.n_item}</b></div>
      <div class="statcard"><span class="muted">Belum dihitung</span><br>
        <b style="color:${belum ? 'var(--warn)' : 'var(--ok)'}">${belum}</b></div>
      <div class="statcard"><span class="muted">Lebih (surplus)</span><br>
        <b style="color:var(--ok)">${o.n_surplus}</b>
        <span class="muted" style="display:block; font-size:11.5px">${rp(o.nilai_surplus)}</span></div>
      <div class="statcard"><span class="muted">Kurang (defisit)</span><br>
        <b class="minus">${o.n_defisit}</b>
        <span class="muted" style="display:block; font-size:11.5px">${rp(o.nilai_defisit)}</span></div>
      <div class="statcard" style="border-color:var(--brand)">
        <span class="muted">Nilai selisih bersih</span><br>
        <b class="rp" style="color:${o.nilai_bersih < 0 ? 'var(--err)' : 'var(--ok)'}">
          ${o.nilai_bersih < 0 ? '−' : ''}${rp(Math.abs(o.nilai_bersih))}</b></div>
    </div>
    <p class="muted">Isi kolom <b>Stok Fisik</b> apa adanya. Kosongkan kalau barangnya belum
      dihitung — kosong berarti "belum dihitung", bukan nol, dan stoknya tidak akan diubah.</p>
    <div class="tblwrap"><table>
      <thead><tr><th>SKU</th><th>Barang</th><th class="num">Stok Sistem</th>
        <th class="num" style="width:110px">Stok Fisik</th><th class="num">Selisih</th>
        <th class="num">Nilai Selisih</th><th style="width:150px">Alasan</th></tr></thead>
      <tbody>${o.item.map((i) => martOpnameBaris(i)).join('')}</tbody>
    </table></div>
    <div class="aju-unduh" style="margin-top:14px">
      ${belum ? `<label class="cek"><input type="checkbox" id="mo-abaikan">
        lanjutkan tanpa ${belum} barang yang belum dihitung — stoknya dibiarkan apa adanya,
        bukan dianggap nol</label>` : ''}
      <div class="field" style="margin-top:8px"><label>Catatan audit (opsional)</label>
        <input id="mo-catatan" maxlength="300" placeholder="mis. gudang B belum sempat dicek"></div>
      <button class="go" id="mo-selesai" style="margin-top:8px">✔ Selesaikan &amp; Rekonsiliasi Auto</button>
      <span class="muted set-hint">Setelah ditekan, stok buku disamakan ke hitungan fisik dan
        sesi ini tidak bisa diubah lagi.</span>
    </div>
  </div>
  ${martRiwayatOpname()}`;
}

function martOpnameBaris(i) {
  const warna = i.selisih > 0 ? 'var(--ok)' : i.selisih < 0 ? 'var(--err)' : 'var(--muted)';
  const belum = i.stok_fisik === null;
  return `<tr data-baris="${i.id}" style="${belum ? 'background:#fffdf5' : ''}">
    <td>${i.barcode ? `<code>${esc(i.barcode)}</code>` : '<span class="muted">—</span>'}</td>
    <td><b>${esc(i.nama)}</b></td>
    <td class="num">${i.stok_sistem}</td>
    <td class="num"><input type="number" min="0" style="width:90px; text-align:right"
      data-fisik="${i.id}" value="${belum ? '' : i.stok_fisik}" placeholder="—"></td>
    <td class="num"><b style="color:${warna}">${belum ? '—' : (i.selisih > 0 ? '+' : '') + i.selisih}</b></td>
    <td class="num rp" style="color:${warna}">${belum ? '—'
      : (i.nilai_selisih < 0 ? '−' : '') + rp(Math.abs(i.nilai_selisih))}</td>
    <td><select data-alasan="${i.id}" ${i.selisih === 0 ? 'disabled' : ''}>
      ${opname.alasan.map((a) => `<option value="${a}" ${i.alasan === a ? 'selected' : ''}>${esc(a)}</option>`).join('')}
    </select></td></tr>`;
}

function martPasangOpname() {
  const o = opname.berjalan;
  const simpan = (id) => {
    const f = document.querySelector(`[data-fisik="${id}"]`);
    const a = document.querySelector(`[data-alasan="${id}"]`);
    return api(`/api/mart/opname/${o.id}`, { method: 'PUT', body: { item: [{
      id, stok_fisik: f.value === '' ? null : Number(f.value), alasan: a.value,
    }] } }).then(martMuatOpname).catch((e) => toast(e.message, 'err'));
  };
  document.querySelectorAll('[data-fisik]').forEach((f) => {
    f.addEventListener('change', () => simpan(Number(f.dataset.fisik)));
    // Enter memindahkan kursor ke baris berikutnya — menghitung rak jadi satu
    // alur tangan tanpa menyentuh tetikus.
    f.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      const semua = [...document.querySelectorAll('[data-fisik]')];
      const n = semua.indexOf(f);
      opnameFokus = semua[n + 1] ? Number(semua[n + 1].dataset.fisik) : null;
      f.blur();
    });
  });
  document.querySelectorAll('[data-alasan]').forEach((a) => {
    a.addEventListener('change', () => simpan(Number(a.dataset.alasan)));
  });
  $('#mo-cetak-form').onclick = martCetakFormOpname;
  $('#mo-unduh').onclick = () => unduhCsv(`opname-berjalan-${martHariIni()}.csv`,
    ['SKU', 'Barang', 'Stok sistem', 'Stok fisik', 'Selisih', 'HPP', 'Nilai selisih', 'Alasan'],
    o.item.map((i) => [i.barcode, i.nama, i.stok_sistem, i.stok_fisik ?? '', i.selisih,
      i.hpp, i.nilai_selisih, i.alasan]));
  $('#mo-batal').onclick = async () => {
    if (!confirm('Batalkan sesi opname ini? Semua hitungan yang sudah diisi akan hilang. '
      + 'Stok barang tidak berubah.')) return;
    try {
      await api(`/api/mart/opname/${o.id}`, { method: 'DELETE' });
      toast('Sesi opname dibatalkan', 'ok');
      martMuatOpname();
    } catch (e) { toast(e.message, 'err'); }
  };
  $('#mo-selesai').onclick = martSelesaikanOpname;

  // Pulihkan fokus setelah tabel digambar ulang oleh penyimpanan.
  if (opnameFokus !== null) {
    const f = document.querySelector(`[data-fisik="${opnameFokus}"]`);
    opnameFokus = null;
    if (f) { f.focus(); f.select(); }
  }
}

let opnameFokus = null;

async function martSelesaikanOpname() {
  const o = opname.berjalan;
  const abaikan = !!$('#mo-abaikan')?.checked;
  const pesan = `Selesaikan opname & rekonsiliasi otomatis?\n\n`
    + `• ${o.n_surplus} barang lebih (${rp(o.nilai_surplus)})\n`
    + `• ${o.n_defisit} barang kurang (${rp(o.nilai_defisit)})\n`
    + `• Nilai selisih bersih ${o.nilai_bersih < 0 ? '−' : ''}${rp(Math.abs(o.nilai_bersih))}\n\n`
    + `Stok buku akan disamakan ke hitungan fisik. Tidak bisa dibatalkan.`;
  if (!confirm(pesan)) return;
  try {
    const d = await api(`/api/mart/opname/${o.id}/selesai`, { method: 'POST', body: {
      abaikan_belum: abaikan, catatan: $('#mo-catatan').value,
    }});
    const n = d.opname.nilai_bersih;
    toast(n === 0 ? '✔ Opname selesai — stok cocok seluruhnya'
      : `✔ Opname selesai — penyesuaian nilai ${n < 0 ? '−' : '+'}${rp(Math.abs(n))}`, 'ok');
    martMuatOpname();
  } catch (e) { toast(e.message, 'err'); }
}

/** Form kosong untuk dibawa berkeliling rak dan diisi dengan pulpen. */
function martCetakFormOpname() {
  const o = opname.berjalan;
  const gaya = `@page { size: A4; margin: 12mm; }
    body{font:12px/1.5 system-ui;margin:0;color:#111} h1{font-size:16px;margin:0}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th,td{border:1px solid #999;padding:6px 8px} th{background:#eee;text-align:left}
    .n{text-align:right} .isi{width:70px} .ttd{margin-top:26px;font-size:11px}`;
  cetakLembar('Form Opname', gaya, `
    <h1>Form Hitung Fisik — ${esc(USER?.tenant_nama || 'IMBOS Mart')}</h1>
    <div>Sesi #${o.id} · dimulai ${new Date(o.created_at).toLocaleString('id-ID')} ·
      petugas ${esc(o.operator)}</div>
    <table><thead><tr><th>SKU</th><th>Barang</th><th class="n">Sistem</th>
      <th class="n isi">Fisik</th><th>Catatan</th></tr></thead>
      <tbody>${o.item.map((i) => `<tr><td>${esc(i.barcode || '-')}</td><td>${esc(i.nama)}</td>
        <td class="n">${i.stok_sistem}</td><td></td><td></td></tr>`).join('')}</tbody></table>
    <div class="ttd">Penghitung: ______________________ &nbsp;&nbsp;
      Saksi: ______________________ &nbsp;&nbsp; Tanggal: ______________</div>`);
}

async function martOpnameDetail(id) {
  const d = await api('/api/mart/opname/' + id);
  const o = d.opname;
  const beda = o.item.filter((i) => i.selisih !== 0);
  openModal(`
    <h2>Opname #${o.id}</h2>
    <p class="muted">Dimulai ${waktuSingkat(o.created_at)} ·
      selesai ${o.selesai_pada ? waktuSingkat(o.selesai_pada) : '—'} · petugas ${esc(o.operator)}
      ${o.catatan ? `<br>Catatan: ${esc(o.catatan)}` : ''}</p>
    <div class="cart">
      <div class="cart-line"><span>Barang diaudit</span><span>${o.n_item}</span></div>
      <div class="cart-line"><span>Lebih / kurang</span><span>${o.n_surplus} / ${o.n_defisit}</span></div>
      <div class="cart-line"><span>Nilai surplus</span><span class="rp">${rp(o.nilai_surplus)}</span></div>
      <div class="cart-line"><span>Nilai defisit</span><span class="rp">${rp(o.nilai_defisit)}</span></div>
      <div class="cart-line"><span><b>Penyesuaian bersih</b></span>
        <span class="rp"><b>${o.nilai_bersih < 0 ? '−' : ''}${rp(Math.abs(o.nilai_bersih))}</b></span></div>
    </div>
    <h3 style="font-size:13.5px">Barang yang selisih (${beda.length})</h3>
    <div class="tblwrap" style="max-height:40vh; overflow:auto"><table>
      <thead><tr><th>Barang</th><th class="num">Sistem</th><th class="num">Fisik</th>
        <th class="num">Selisih</th><th class="num">Nilai</th><th>Alasan</th></tr></thead>
      <tbody>${beda.map((i) => `<tr><td>${esc(i.nama)}</td>
        <td class="num">${i.stok_sistem}</td><td class="num">${i.stok_fisik ?? '—'}</td>
        <td class="num" style="color:${i.selisih < 0 ? 'var(--err)' : 'var(--ok)'}">
          ${i.selisih > 0 ? '+' : ''}${i.selisih}</td>
        <td class="num rp">${i.nilai_selisih < 0 ? '−' : ''}${rp(Math.abs(i.nilai_selisih))}</td>
        <td>${esc(i.alasan)}</td></tr>`).join('')
        || '<tr><td colspan="6" class="muted">Semua cocok.</td></tr>'}</tbody>
    </table></div>
    <button class="linkbtn" id="mo-unduh-detail">⬇ unduh rincian CSV</button>`);
  $('#mo-unduh-detail').onclick = () => unduhCsv(`opname-${o.id}.csv`,
    ['SKU', 'Barang', 'Stok sistem', 'Stok fisik', 'Selisih', 'HPP', 'Nilai selisih', 'Alasan'],
    o.item.map((i) => [i.barcode, i.nama, i.stok_sistem, i.stok_fisik ?? '', i.selisih,
      i.hpp, i.nilai_selisih, i.alasan]));
}


// --------------------------------------------------- RIWAYAT PENJUALAN MART

/*
 * Penjualan Mart hidup di DUA BUKU yang sengaja tidak pernah dilebur: tunai &
 * transfer di `mart_jual` (uangnya di laci Mart), kartu di `transaksi`
 * (uangnya di kas sekolah). Pemisahan itulah yang menjaga neraca sekolah
 * tetap nol. Tetapi bagi penjaga Mart keduanya satu hal yang sama — "apa saja
 * yang terjual hari ini" — dan sebelum layar ini ada, tab Riwayat hanya
 * memperlihatkan penjualan kartu sehingga tunai & transfer seolah hilang.
 */
let martRw = { baris: [], hal: 1, nHalaman: 1, ringkas: null };

function martRiwayatView(el) {
  const hariIni = martHariIni();
  el.innerHTML = `<div class="panel hidden" id="rwm-tahan-panel">
    <div class="kat-head"><h2 style="margin:0">Transaksi Tertahan
      <span class="muted" id="rwm-tahan-n"></span></h2></div>
    <p class="muted">Keranjang yang diparkir dan belum dibayar — stoknya belum berkurang.
      Klik <b>lanjutkan</b> untuk mengembalikannya ke layar kasir.</p>
    <div class="tblwrap"><table>
      <thead><tr><th>Waktu</th><th>Catatan</th><th>Isi</th><th class="num">Item</th>
        <th class="num">Total</th><th>Kasir</th><th></th></tr></thead>
      <tbody id="rwm-tahan-body"></tbody>
    </table></div>
  </div>
  <div class="panel">
    <div class="kat-head"><h2 style="margin:0">Riwayat Penjualan</h2>
      <span>
        <button class="linkbtn" id="rwm-unduh">⬇ unduh CSV</button> ·
        <button class="linkbtn" id="rwm-ulang">⟳ muat ulang</button>
      </span></div>
    <p class="muted">Seluruh penjualan Mart — tunai, transfer, dan kartu — dalam satu daftar.
      Uangnya tetap di buku masing-masing: tunai &amp; transfer ada di laci Mart, kartu masuk
      kas sekolah. Yang disatukan di sini tampilannya, bukan uangnya.</p>
    <div class="filterbar">
      <div class="field"><label>Dari</label><input type="date" id="rwm-dari" value="${hariIni}"></div>
      <div class="field"><label>Sampai</label><input type="date" id="rwm-sampai" value="${hariIni}"></div>
      <div class="field"><label>Metode</label><select id="rwm-metode">
        <option value="">semua</option><option value="tunai">tunai</option>
        <option value="transfer">transfer</option><option value="hutang">hutang (member)</option>
        <option value="kartu">cashless (kartu RFID)</option>
        <option value="pelunasan">pelunasan hutang</option>
      </select></div>
      <div class="field"><label>Cari</label>
        <input id="rwm-cari" placeholder="no. struk (MRT-000123), barang, pembeli, bank, kasir"></div>
      <button class="go" id="rwm-go">Terapkan</button>
      <button id="rwm-reset">Hari ini</button>
    </div>
    <div id="rwm-ringkas" class="cards"></div>
    <div class="tblwrap"><table>
      <thead><tr><th>Waktu</th><th>No. struk</th><th>Metode</th><th class="num">Item</th>
        <th class="num">Total</th><th>Keterangan</th><th>Kasir</th><th></th></tr></thead>
      <tbody id="rwm-body"></tbody>
    </table></div>
    <div id="rwm-nav" style="display:flex; gap:8px; align-items:center; margin-top:12px"></div>
  </div>`;

  $('#rwm-go').onclick = () => { martRw.hal = 1; martMuatRiwayat(); };
  $('#rwm-ulang').onclick = martMuatRiwayat;
  $('#rwm-unduh').onclick = martUnduhRiwayatMart;
  $('#rwm-reset').onclick = () => {
    $('#rwm-dari').value = $('#rwm-sampai').value = martHariIni();
    $('#rwm-metode').value = '';
    $('#rwm-cari').value = '';
    martRw.hal = 1;
    martMuatRiwayat();
  };
  $('#rwm-metode').onchange = () => { martRw.hal = 1; martMuatRiwayat(); };
  martMuatTahanPanel();
  $('#rwm-cari').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); $('#rwm-go').click(); }
  });
  martMuatRiwayat();
}

/** Parameter saringan, dipakai bersama oleh daftar dan unduhan. */
function martRiwayatQs(tambahan) {
  const p = new URLSearchParams();
  const dari = $('#rwm-dari').value, sampai = $('#rwm-sampai').value;
  // Hari operasional WIB, bukan UTC — kalau tidak, penjualan sebelum pukul
  // 07.00 terbaca sebagai milik hari sebelumnya.
  if (dari) p.set('dari', new Date(dari + 'T00:00:00+07:00').toISOString());
  if (sampai) p.set('sampai', new Date(sampai + 'T23:59:59.999+07:00').toISOString());
  if ($('#rwm-metode').value) p.set('metode', $('#rwm-metode').value);
  if ($('#rwm-cari').value.trim()) p.set('cari', $('#rwm-cari').value.trim());
  for (const [k, v] of Object.entries(tambahan || {})) p.set(k, v);
  return p.toString();
}

async function martMuatRiwayat() {
  $('#rwm-body').innerHTML = '<tr><td colspan="8" class="muted">memuat…</td></tr>';
  let d;
  try {
    d = await api('/api/mart/riwayat?' + martRiwayatQs({ hal: martRw.hal, per: 25 }));
  } catch (e) {
    $('#rwm-body').innerHTML = `<tr><td colspan="8" class="minus">${esc(e.message)}</td></tr>`;
    return;
  }
  martRw = { baris: d.baris, hal: d.hal, nHalaman: d.n_halaman, ringkas: d.ringkas };
  const r = d.ringkas;

  $('#rwm-ringkas').innerHTML = `
    <div class="statcard"><span class="muted">Transaksi</span><br><b>${r.n.toLocaleString('id-ID')}</b></div>
    <div class="statcard"><span class="muted">Omzet periode ini</span><br><b class="rp">${rp(r.omzet)}</b></div>
    <div class="statcard" style="border-color:var(--brand)"><span class="muted">Laba</span><br>
      <b class="rp" style="color:var(--brand)">${rp(r.laba ?? 0)}</b>
      <span class="muted" style="display:block; font-size:11.5px">omzet − modal barang (HPP),
        sebelum biaya operasional</span></div>
    <div class="statcard"><span class="muted">💵 Tunai</span><br><b class="rp">${rp(r.tunai)}</b></div>
    <div class="statcard"><span class="muted">🏦 Transfer</span><br><b class="rp">${rp(r.transfer)}</b></div>
    <div class="statcard"><span class="muted">💳 Cashless</span><br><b class="rp">${rp(r.kartu)}</b>
      <span class="muted" style="display:block; font-size:11.5px">dibayar saldo kartu —
        uangnya di kas sekolah</span></div>
    ${r.hutang ? `<div class="statcard"><span class="muted">📒 Hutang (belum dibayar)</span><br>
      <b class="rp" style="color:var(--brand)">${rp(r.hutang)}</b></div>` : ''}
    ${r.pelunasan ? `<div class="statcard"><span class="muted">🧾 Hutang (dibayar)</span><br>
      <b class="rp">${rp(r.pelunasan)}</b>
      <span class="muted" style="display:block; font-size:11.5px">cicilan masuk —
        tidak dihitung ulang sebagai omzet</span></div>` : ''}
    ${r.diskon ? `<div class="statcard"><span class="muted">Diskon diberikan</span><br>
      <b class="rp">${rp(r.diskon)}</b></div>` : ''}`;

  $('#rwm-body').innerHTML = d.baris.map((b, i) => {
    const batal = b.status === 'batal';
    // Yang bisa dibetulkan hanya penjualan tunai/transfer yang masih sah dan
    // kasnya belum ditutup. Penjualan kartu tidak pernah: uangnya sudah masuk
    // kas sekolah dan menyentuh neraca.
    const bisa = b.jenis === 'jual' && !batal && !b.terkunci;
    // Cicilan piutang bukan penjualan: tidak ada struknya sendiri untuk
    // dicetak, dan rinciannya adalah rincian nota hutang yang dicicil.
    const cicilan = b.jenis === 'pelunasan';
    return `
    <tr${batal ? ' class="rwm-batal"' : ''}>
      <td>${waktuSingkat(b.waktu)}</td>
      <td><code>${esc(b.no)}</code>
        ${batal ? '<br><span class="badge blokir">batal</span>' : ''}
        ${!batal && b.n_ubah ? `<br><span class="badge" title="pernah dibetulkan"
          style="background:#fff3d6;color:var(--warn)">diubah ${b.n_ubah}×</span>` : ''}</td>
      <td>${MART_METODE_LABEL[b.metode] || esc(b.metode)}</td>
      <td class="num">${b.n_item ?? '<span class="muted">—</span>'}</td>
      <td class="num rp"><b>${rp(b.total)}</b>${b.diskon
        ? `<br><span class="muted" style="font-size:11px">diskon ${rp(b.diskon)}</span>` : ''}</td>
      <td class="muted">${esc(b.ket || '')}</td>
      <td>${esc(b.operator || '')}</td>
      <td style="white-space:nowrap">
        <button class="linkbtn" data-rinci="${i}">rincian</button>
        ${cicilan ? '' : ` · <button class="linkbtn" data-cetak="${i}">🖨</button>`}
        ${bisa ? ` · <button class="linkbtn" data-betul="${i}">✏ betulkan</button>
          · <button class="linkbtn danger" data-batal="${i}">✕ batalkan</button>` : ''}
      </td>
    </tr>`;
  }).join('') || '<tr><td colspan="8" class="muted">Tidak ada penjualan pada rentang ini.</td></tr>';

  document.querySelectorAll('#rwm-body [data-rinci]').forEach((b) => {
    b.onclick = () => {
      const x = martRw.baris[Number(b.dataset.rinci)];
      // Rincian cicilan = rincian nota hutang yang dicicil; baris cicilannya
      // sendiri tidak punya daftar barang.
      martRiwayatDetail(x.jenis === 'pelunasan'
        ? { ...x, jenis: 'jual', id: x.jual_id } : x, false);
    };
  });
  document.querySelectorAll('#rwm-body [data-cetak]').forEach((b) => {
    b.onclick = () => martRiwayatDetail(martRw.baris[Number(b.dataset.cetak)], true);
  });
  document.querySelectorAll('#rwm-body [data-betul]').forEach((b) => {
    b.onclick = () => martBetulkanModal(martRw.baris[Number(b.dataset.betul)]);
  });
  document.querySelectorAll('#rwm-body [data-batal]').forEach((b) => {
    b.onclick = () => martBatalModal(martRw.baris[Number(b.dataset.batal)]);
  });

  martMuatTahanPanel();

  $('#rwm-nav').innerHTML = d.n_halaman > 1 ? `
    <button id="rwm-prev" ${d.hal <= 1 ? 'disabled' : ''}>← sebelumnya</button>
    <span class="muted">halaman ${d.hal} dari ${d.n_halaman}</span>
    <button id="rwm-next" ${d.hal >= d.n_halaman ? 'disabled' : ''}>berikutnya →</button>` : '';
  if ($('#rwm-prev')) $('#rwm-prev').onclick = () => { martRw.hal -= 1; martMuatRiwayat(); };
  if ($('#rwm-next')) $('#rwm-next').onclick = () => { martRw.hal += 1; martMuatRiwayat(); };
}

/**
 * Rincian satu transaksi — dan, kalau diminta, cetak ulang struknya.
 *
 * Rinciannya dibaca ULANG dari server, tidak dari baris yang kebetulan sedang
 * tampil: struk cetak ulang harus menyebut barang yang benar-benar tercatat
 * terjual, bukan ringkasannya.
 */
async function martRiwayatDetail(b, cetak) {
  let d;
  try {
    d = await api(`/api/mart/riwayat/${b.jenis}/${b.id}`);
  } catch (e) { toast(e.message, 'err'); return; }
  const t = d.transaksi;
  const metodeStruk = t.metode === 'tunai' ? 'Tunai'
    : t.metode === 'transfer' ? ('Transfer ' + (t.bank || '')).trim() : 'Kartu (RFID)';

  if (cetak) {
    if (!d.item.length) {
      toast('Transaksi lama ini tidak menyimpan rincian barang — struknya tidak bisa dicetak ulang', 'err');
      return;
    }
    martCetak({
      no: t.no, metode: metodeStruk, waktu: t.created_at, operator: t.operator, ulang: true,
      item: d.item.map((i) => ({ nama: i.nama, harga: i.harga, qty: i.qty })),
      subtotal: t.subtotal ?? t.total, diskon: t.diskon || 0, total: t.total,
      bayar: t.uang_diterima ?? t.total, kembalian: t.kembalian || 0, ket: t.ket || '',
    });
    return;
  }

  openModal(`
    <h2>Struk ${esc(t.no)}</h2>
    ${t.status === 'batal' ? `<p class="preview" style="border-color:var(--err); color:var(--err)">
      <b>Transaksi ini dibatalkan.</b> Stoknya sudah dikembalikan dan nilainya tidak lagi
      dihitung sebagai penjualan. Barisnya sengaja tetap ada supaya bisa ditelusuri.</p>` : ''}
    <p class="muted">${esc(waktuSingkat(t.created_at))} · ${MART_METODE_LABEL[t.metode] || esc(t.metode)}
      · kasir ${esc(t.operator || '-')}${t.ket ? `<br>${esc(t.ket)}` : ''}
      ${t.terkunci ? '<br>🔒 terkunci — kas hari itu sudah ditutup' : ''}</p>
    ${d.item.length ? `<div class="tblwrap"><table>
      <thead><tr><th>Barang</th><th class="num">Harga</th><th class="num">Qty</th>
        <th class="num">Subtotal</th></tr></thead>
      <tbody>${d.item.map((i) => `<tr><td>${esc(i.nama)}</td>
        <td class="num rp">${rp(i.harga)}</td><td class="num">${i.qty}</td>
        <td class="num rp">${rp(i.subtotal)}</td></tr>`).join('')}</tbody>
    </table></div>` : `<p class="muted">Transaksi ini tidak menyimpan rincian barang.
      ${t.keterangan ? `Keterangannya: <b>${esc(t.keterangan)}</b>` : ''}</p>`}
    <div class="cart">
      <div class="cart-line"><span>Subtotal</span><span class="rp">${rp(t.subtotal ?? t.total)}</span></div>
      ${t.diskon ? `<div class="cart-line"><span>Diskon</span>
        <span class="rp">−${rp(t.diskon)}</span></div>` : ''}
      <div class="cart-line"><span><b>Total</b></span><span class="rp"><b>${rp(t.total)}</b></span></div>
      ${t.kembalian ? `<div class="cart-line"><span>Dibayar / kembalian</span>
        <span class="rp">${rp(t.uang_diterima)} / ${rp(t.kembalian)}</span></div>` : ''}
    </div>
    ${(d.ubah || []).length ? `<h3 style="font-size:13.5px">Riwayat perubahan ${d.ubah.length}×</h3>
      ${martJejakHtml(d.ubah)}` : ''}
    <button class="linkbtn" id="rwm-cetak-detail" ${d.item.length ? '' : 'disabled'}>🖨 cetak ulang struk</button>`);
  if ($('#rwm-cetak-detail')) $('#rwm-cetak-detail').onclick = () => martRiwayatDetail(b, true);
}

// ----------------------------------------------- MEMBER & PIUTANG (layar)

/*
 * Member ada di sini bukan untuk gagah-gagahan: ia SYARAT agar hutang boleh
 * terjadi. Dan piutang punya layar sendiri karena menagih adalah pekerjaan
 * tersendiri — bukan sesuatu yang diselipkan di antara antrean kasir.
 *
 * Satu hal yang dijaga tampilannya: penjualan hutang adalah OMZET, tetapi
 * bukan UANG MASUK. Sepanjang layar ini kedua kata itu tidak pernah dipakai
 * bergantian.
 */
let martMem = { member: [], piutang: [], ringkas: null };

function martPiutangView(el) {
  el.innerHTML = `
  <div class="panel">
    <div class="kat-head"><h2 style="margin:0">Piutang Berjalan
      <span class="muted" id="mp-n"></span></h2>
      <span><button class="linkbtn" id="mp-unduh-piutang">⬇ unduh CSV</button> ·
        <button class="linkbtn" id="mp-riwayat">🧾 riwayat cicilan</button></span></div>
    <p class="muted">Barang sudah keluar dari rak, uangnya belum masuk laci. Nilainya
      <b>sudah terhitung</b> sebagai omzet pada hari penjualannya — yang ditunggu di sini
      uangnya, dan ia diakui pada hari cicilannya dibayar.</p>
    <div id="mp-ringkas" class="cards"></div>
    <div class="tblwrap"><table>
      <thead><tr><th>Tanggal</th><th>No. struk</th><th>Member</th><th class="num">Nilai</th>
        <th class="num">Dibayar</th><th class="num">Sisa</th><th class="num">Umur</th><th></th></tr></thead>
      <tbody id="mp-piutang-body"></tbody>
    </table></div>
  </div>

  <div class="panel">
    <div class="kat-head"><h2 style="margin:0">Member <span class="muted" id="mp-nm"></span></h2>
      <span><button class="linkbtn" id="mp-unduh-member">⬇ unduh CSV</button>
        ${martKasirSajaMelayani() ? '' : ` ·
        <button class="linkbtn" id="mp-kategori">⚙ kelola kategori</button> ·
        <button class="linkbtn" id="mp-tambah">+ Tambah member</button>`}</span></div>
    <p class="muted">Hanya member terdaftar yang boleh berhutang, dan hanya yang sudah diberi
      <b>plafon</b>. Plafon bawaannya nol — memberi batas hutang adalah keputusan yang harus
      diambil seseorang, bukan hak yang didapat begitu namanya terdaftar.</p>
    <div class="field" style="max-width:320px"><label>Cari</label>
      <input id="mp-cari" placeholder="nama / HP / kode"></div>
    <div class="tblwrap"><table>
      <thead><tr><th>Kode</th><th>Nama</th><th>HP</th><th>Keterangan</th>
        <th class="num">Plafon</th><th class="num">Piutang</th><th class="num">Sisa plafon</th>
        <th>Status</th><th></th></tr></thead>
      <tbody id="mp-member-body"></tbody>
    </table></div>
  </div>`;

  if ($('#mp-tambah')) $('#mp-tambah').onclick = () => martMemberModal(null);
  if ($('#mp-kategori')) $('#mp-kategori').onclick = martKategoriMemberModal;
  $('#mp-riwayat').onclick = martRiwayatCicilanModal;
  $('#mp-unduh-member').onclick = martUnduhMember;
  $('#mp-unduh-piutang').onclick = martUnduhPiutang;
  $('#mp-cari').addEventListener('input', debounce(martMuatMemberLayar, 250));
  martMuatMemberLayar();
}

async function martMuatMemberLayar() {
  const q = $('#mp-cari')?.value.trim();
  // Kasir tidak bisa menambah/mengubah member, jadi tidak pernah butuh
  // dropdown kategorinya — endpointnya pun memang admin-only di server.
  const kasir = martKasirSajaMelayani();
  const panggilan = [
    api('/api/mart/member' + (q ? '?cari=' + encodeURIComponent(q) : '')),
    api('/api/mart/piutang'),
  ];
  // Daftar kategori & pilihan keterangan ikut dimuat di sini supaya formulir
  // member bisa langsung membuka dropdown-nya tanpa menunggu satu perjalanan
  // jaringan lagi saat tombol Tambah ditekan.
  if (!kasir) panggilan.push(api('/api/mart/member-kategori'));
  const [dm, dp, dk] = await Promise.all(panggilan);
  martMem = { member: dm.member, piutang: dp.piutang, ringkas: dm.ringkas,
    kategori: dk?.kategori || [], keterangan: dk?.keterangan || [] };

  const r = dm.ringkas;
  $('#mp-ringkas').innerHTML = `
    <div class="statcard"><span class="muted">Total piutang berjalan</span><br>
      <b class="rp" style="color:var(--brand)">${rp(dp.total_sisa)}</b></div>
    <div class="statcard"><span class="muted">Member berhutang</span><br><b>${r.n_berhutang}</b></div>
    <div class="statcard"><span class="muted">Jumlah member</span><br><b>${r.n_member}</b></div>
    <div class="statcard"><span class="muted">Piutang tertua</span><br>
      <b>${dp.piutang.length ? dp.piutang[0].umur_hari + ' hari' : '—'}</b></div>`;
  $('#mp-n').textContent = dp.piutang.length ? `— ${dp.piutang.length} transaksi` : '';
  $('#mp-nm').textContent = `— ${dm.member.length}`;

  $('#mp-piutang-body').innerHTML = dp.piutang.map((p, i) => `
    <tr>
      <td>${waktuSingkat(p.waktu)}</td>
      <td><code>${esc(p.no)}</code></td>
      <td><b>${esc(p.member)}</b></td>
      <td class="num rp">${rp(p.total)}</td>
      <td class="num rp">${p.dibayar ? rp(p.dibayar) : '<span class="muted">—</span>'}</td>
      <td class="num rp"><b>${rp(p.sisa)}</b></td>
      <td class="num ${p.umur_hari >= 30 ? 'minus' : ''}">${p.umur_hari} hr</td>
      <td><button class="linkbtn" data-bayar="${i}">💵 terima pembayaran</button></td>
    </tr>`).join('') || '<tr><td colspan="8" class="muted">Tidak ada piutang berjalan. 👍</td></tr>';

  document.querySelectorAll('#mp-piutang-body [data-bayar]').forEach((b) => {
    b.onclick = () => martBayarPiutangModal(martMem.piutang[Number(b.dataset.bayar)]);
  });

  $('#mp-member-body').innerHTML = dm.member.map((m, i) => `
    <tr>
      <td><code>${esc(m.kode)}</code></td>
      <td><b>${esc(m.nama)}</b>${m.kategori_nama
        ? `<br><span class="badge ${m.boleh_hutang ? 'netral' : 'blokir'}">${esc(m.kategori_nama)}</span>` : ''}</td>
      <td>${esc(m.hp || '—')}</td>
      <td class="muted">${esc(m.keterangan || '')}</td>
      <td class="num rp">${m.boleh_hutang === false
        ? '<span class="muted">kategorinya tidak boleh hutang</span>'
        : (m.batas_hutang ? rp(m.batas_hutang) : '<span class="muted">tidak boleh hutang</span>')}</td>
      <td class="num rp">${m.sisa_piutang ? `<b>${rp(m.sisa_piutang)}</b>` : '<span class="muted">—</span>'}</td>
      <td class="num rp">${m.batas_hutang ? rp(m.sisa_plafon) : '<span class="muted">—</span>'}</td>
      <td><span class="badge ${m.aktif ? 'aktif' : 'nonaktif'}">${m.aktif ? 'aktif' : 'nonaktif'}</span></td>
      <td style="white-space:nowrap">${martKasirSajaMelayani() ? '<span class="muted">—</span>' : `
        <button class="linkbtn" data-ubah-m="${i}">ubah</button> ·
        <button class="linkbtn danger" data-hapus-m="${i}">hapus</button>`}</td>
    </tr>`).join('') || '<tr><td colspan="9" class="muted">Belum ada member.</td></tr>';

  document.querySelectorAll('#mp-member-body [data-ubah-m]').forEach((b) => {
    b.onclick = () => martMemberModal(martMem.member[Number(b.dataset.ubahM)]);
  });
  document.querySelectorAll('#mp-member-body [data-hapus-m]').forEach((b) => {
    b.onclick = async () => {
      const m = martMem.member[Number(b.dataset.hapusM)];
      if (!confirm(`Hapus member "${m.nama}"?`)) return;
      try {
        const h = await api('/api/mart/member/' + m.id, { method: 'DELETE' });
        toast(h.dinonaktifkan ? h.pesan : '✔ Member dihapus', 'ok');
        martMuatMemberLayar();
      } catch (e) { toast(e.message, 'err'); }
    };
  });
}

function martMemberModal(m) {
  openModal(`
    <h2>${m ? 'Ubah' : 'Tambah'} Member</h2>
    <div class="field"><label>Nama</label>
      <input id="mm-nama" value="${esc(m?.nama || '')}" maxlength="120" placeholder="nama lengkap"></div>
    <div class="field"><label>Nomor HP</label>
      <input id="mm-hp" value="${esc(m?.hp || '')}" maxlength="25" placeholder="08…"
        inputmode="numeric"></div>
    <div class="field"><label>Kategori</label><select id="mm-kat">
      <option value="">— tanpa kategori —</option>
      ${(martMem.kategori || []).filter((k) => k.aktif || k.id === m?.kategori_id)
        .map((k) => `<option value="${k.id}" ${m?.kategori_id === k.id ? 'selected' : ''}>
          ${esc(k.nama)}${k.boleh_hutang ? '' : ' (tidak boleh hutang)'}</option>`).join('')}
    </select>
      <span class="muted set-hint">Kategori yang ditandai "tidak boleh hutang" menolak lebih dulu
        daripada plafon. Boleh dikosongkan — hanya plafon yang menentukan.</span></div>
    <div class="field"><label>Keterangan</label>
      <select id="mm-ket-pilih">
        <option value="">— pilih dari daftar —</option>
        ${(martMem.keterangan || []).filter((k) => k.aktif).map((k) => `<option value="${esc(k.teks)}"
          ${m?.keterangan === k.teks ? 'selected' : ''}>${esc(k.teks)}</option>`).join('')}
      </select>
      <input id="mm-ket" value="${esc(m?.keterangan || '')}" maxlength="160" style="margin-top:6px"
        placeholder="atau ketik sendiri">
      <span class="muted set-hint">Memilih dari daftar akan mengisi kotak di bawahnya — isinya tetap
        boleh diketik bebas.</span></div>
    <div class="field"><label>Plafon hutang</label>
      ${inputUang({ id: 'mm-batas', nilai: m?.batas_hutang || '' })}
      <span class="muted set-hint">Kosong atau nol berarti member ini <b>tidak boleh berhutang</b>.
        Angka ini batas atas piutang yang boleh berjalan sekaligus — bukan per transaksi.
        ${m?.sisa_piutang ? `Piutang berjalan sekarang <b>${rp(m.sisa_piutang)}</b>, dan plafon
          tidak boleh diturunkan di bawahnya.` : ''}</span></div>
    ${m ? `<label class="cek"><input type="checkbox" id="mm-aktif" ${m.aktif ? 'checked' : ''}>
      aktif (boleh bertransaksi)</label>` : ''}`,
    async () => {
      const body = { nama: $('#mm-nama').value, hp: $('#mm-hp').value,
        kategori_id: $('#mm-kat').value || null,
        keterangan: $('#mm-ket').value, batas_hutang: nilaiUang('#mm-batas') || 0 };
      if (m) body.aktif = $('#mm-aktif').checked ? 1 : 0;
      if (m) await api('/api/mart/member/' + m.id, { method: 'PUT', body });
      else await api('/api/mart/member', { method: 'POST', body });
      toast('✔ Member tersimpan', 'ok');
      martMuatMemberLayar();
    });
  // Memilih dari daftar hanya MENGISI kotaknya, tidak menguncinya: keterangan
  // bebas yang sudah terlanjur dipakai tetap boleh diketik apa adanya.
  $('#mm-ket-pilih').onchange = () => {
    if ($('#mm-ket-pilih').value) $('#mm-ket').value = $('#mm-ket-pilih').value;
  };
  const n = $('#mm-nama');
  if (n) n.focus();
}

/**
 * Kelola daftar kategori member & pilihan keterangan.
 *
 * Dua daftar kecil dalam satu modal karena keduanya dipakai oleh formulir yang
 * sama — memisahkannya jadi dua menu hanya menambah tempat untuk dicari.
 */
function martKategoriMemberModal() {
  const gambar = () => `
    <h2>Kategori Member &amp; Pilihan Keterangan</h2>
    <h3 style="font-size:13.5px; margin-bottom:4px">Kategori</h3>
    <p class="muted" style="margin-top:0">Kategori yang tidak dicentang akan <b>menolak hutang</b>
      berapa pun plafon orangnya.</p>
    <div class="tblwrap"><table>
      <thead><tr><th>Nama</th><th>Boleh hutang</th><th></th></tr></thead>
      <tbody>${(martMem.kategori || []).map((k) => `<tr>
        <td>${esc(k.nama)}${k.aktif ? '' : ' <span class="badge nonaktif">nonaktif</span>'}</td>
        <td><label class="cek"><input type="checkbox" data-kat-hutang="${k.id}"
          ${k.boleh_hutang ? 'checked' : ''}> boleh</label></td>
        <td><button class="linkbtn danger" data-kat-hapus="${k.id}">hapus</button></td>
      </tr>`).join('') || '<tr><td colspan="3" class="muted">Belum ada kategori.</td></tr>'}</tbody>
    </table></div>
    <div style="display:flex; gap:8px; align-items:flex-end; margin:8px 0 16px">
      <div class="field" style="flex:1; margin:0"><label>Kategori baru</label>
        <input id="mk-nama" maxlength="40" placeholder="mis. Wali kelas"></div>
      <button class="go" id="mk-tambah" style="flex:none">+ Tambah</button>
    </div>
    <h3 style="font-size:13.5px; margin-bottom:4px">Pilihan keterangan</h3>
    <div class="tblwrap"><table>
      <thead><tr><th>Teks</th><th></th></tr></thead>
      <tbody>${(martMem.keterangan || []).map((k) => `<tr>
        <td>${esc(k.teks)}</td>
        <td><button class="linkbtn danger" data-ket-hapus="${k.id}">hapus</button></td>
      </tr>`).join('') || '<tr><td colspan="2" class="muted">Belum ada pilihan.</td></tr>'}</tbody>
    </table></div>
    <div style="display:flex; gap:8px; align-items:flex-end; margin-top:8px">
      <div class="field" style="flex:1; margin:0"><label>Pilihan baru</label>
        <input id="mk-teks" maxlength="160" placeholder="mis. warung depan"></div>
      <button class="go" id="mk-tambah-ket" style="flex:none">+ Tambah</button>
    </div>`;

  const pasang = () => {
    $('#modal-root .modal').innerHTML = gambar() +
      '<div class="actions"><button id="m-cancel">Tutup</button></div>';
    $('#m-cancel').onclick = closeModal;

    $('#mk-tambah').onclick = async () => {
      try {
        await api('/api/mart/member-kategori', { method: 'POST', body: { nama: $('#mk-nama').value } });
        await segarkan();
      } catch (e) { toast(e.message, 'err'); }
    };
    $('#mk-tambah-ket').onclick = async () => {
      try {
        await api('/api/mart/member-ket', { method: 'POST', body: { teks: $('#mk-teks').value } });
        await segarkan();
      } catch (e) { toast(e.message, 'err'); }
    };
    document.querySelectorAll('[data-kat-hutang]').forEach((c) => {
      c.onchange = async () => {
        try {
          await api('/api/mart/member-kategori/' + c.dataset.katHutang, { method: 'PUT',
            body: { nama: (martMem.kategori.find((k) => k.id === Number(c.dataset.katHutang)) || {}).nama,
              boleh_hutang: c.checked ? 1 : 0 } });
          await segarkan();
        } catch (e) { toast(e.message, 'err'); }
      };
    });
    document.querySelectorAll('[data-kat-hapus]').forEach((b) => {
      b.onclick = async () => {
        if (!confirm('Hapus kategori ini? Membernya tidak ikut terhapus — mereka kembali tanpa kategori.')) return;
        try {
          const h = await api('/api/mart/member-kategori/' + b.dataset.katHapus, { method: 'DELETE' });
          toast(h.n_member_terlepas
            ? `✔ Kategori dihapus — ${h.n_member_terlepas} member kembali tanpa kategori` : '✔ Kategori dihapus', 'ok');
          await segarkan();
        } catch (e) { toast(e.message, 'err'); }
      };
    });
    document.querySelectorAll('[data-ket-hapus]').forEach((b) => {
      b.onclick = async () => {
        try {
          await api('/api/mart/member-ket/' + b.dataset.ketHapus, { method: 'DELETE' });
          await segarkan();
        } catch (e) { toast(e.message, 'err'); }
      };
    });
  };

  const segarkan = async () => {
    const d = await api('/api/mart/member-kategori');
    martMem.kategori = d.kategori;
    martMem.keterangan = d.keterangan;
    pasang();
    martMuatMemberLayar();
  };

  openModal(gambar(), null);
  $('#modal-root .modal').classList.add('modal-lebar');
  pasang();
}

/**
 * Terima cicilan.
 *
 * Uangnya masuk HARI INI, bukan pada hari penjualannya — jadi ia akan dicari
 * tutup kas hari ini di laci. Kalimat itu ditulis apa adanya di layar supaya
 * kasir tidak bertanya-tanya kenapa lacinya bertambah tanpa ada penjualan.
 */
function martBayarPiutangModal(p) {
  openModal(`
    <h2>Terima Pembayaran</h2>
    <p class="muted"><code>${esc(p.no)}</code> · ${esc(p.member)} ·
      ${esc(waktuSingkat(p.waktu))} (${p.umur_hari} hari)</p>
    <div class="cart">
      <div class="cart-line"><span>Nilai transaksi</span><span class="rp">${rp(p.total)}</span></div>
      <div class="cart-line"><span>Sudah dibayar</span><span class="rp">${rp(p.dibayar)}</span></div>
      <div class="cart-line"><span><b>Sisa</b></span><span class="rp"><b>${rp(p.sisa)}</b></span></div>
    </div>
    <div class="k-chip-baris" style="margin:10px 0 8px">
      <button class="k-chip" id="mb-lunas">Lunasi ${rp(p.sisa)}</button>
    </div>
    <div class="field"><label>Jumlah dibayar</label>${inputUang({ id: 'mb-jumlah', nilai: '' })}
      <span class="muted set-hint">Boleh dicicil. Kelebihan uang tidak disimpan di sini —
        kembalikan saja ke pembayarnya.</span></div>
    <div class="field"><label>Diterima sebagai</label><select id="mb-metode">
      <option value="tunai">💵 Tunai — masuk laci Mart</option>
      <option value="transfer">🏦 Transfer — masuk rekening</option></select></div>
    <div id="mb-transfer" class="hidden">
      <div class="field"><label>Bank / kanal</label><input id="mb-bank" maxlength="40"
        placeholder="mis. BSI / QRIS"></div>
      <div class="field"><label>Nama pengirim</label><input id="mb-pengirim" maxlength="80"></div>
    </div>
    <div class="field"><label>Catatan (opsional)</label><input id="mb-catatan" maxlength="160"></div>
    <p class="muted">Uang ini diakui <b>hari ini</b>, bukan pada hari penjualannya — jadi tutup
      kas hari ini yang akan mencarinya di laci.</p>`,
    async () => {
      const d = await api(`/api/mart/piutang/${p.id}/bayar`, { method: 'POST', body: {
        jumlah: nilaiUang('#mb-jumlah') || 0, metode: $('#mb-metode').value,
        bank: $('#mb-bank').value, pengirim: $('#mb-pengirim').value,
        catatan: $('#mb-catatan').value,
      }});
      toast(d.lunas ? '✔ Piutang LUNAS' : `✔ Cicilan diterima — sisa ${rp(d.sisa)}`, 'ok');
      martMuatMemberLayar();
    }, 'Terima pembayaran');

  $('#mb-lunas').onclick = () => isiUang('#mb-jumlah', p.sisa);
  $('#mb-metode').onchange = () =>
    $('#mb-transfer').classList.toggle('hidden', $('#mb-metode').value !== 'transfer');
  const j = $('#mb-jumlah');
  if (j) j.focus();
}

async function martRiwayatCicilanModal() {
  const d = await api('/api/mart/piutang/bayar');
  openModal(`
    <h2>Riwayat Cicilan</h2>
    <p class="muted">Uang yang benar-benar diterima, beserta hari diterimanya —
      inilah yang dicocokkan dengan laci dan mutasi rekening.</p>
    <div class="tblwrap" style="max-height:50vh; overflow:auto"><table>
      <thead><tr><th>Waktu</th><th>No. struk</th><th>Member</th><th>Metode</th>
        <th class="num">Jumlah</th><th>Catatan</th><th>Kasir</th></tr></thead>
      <tbody>${d.bayar.map((b) => `<tr>
        <td>${waktuSingkat(b.created_at)}</td>
        <td><code>${esc(b.no)}</code></td>
        <td>${esc(b.member)}</td>
        <td>${b.metode === 'transfer' ? '🏦 ' + esc(b.bank || 'transfer') : '💵 tunai'}</td>
        <td class="num rp">${rp(b.jumlah)}</td>
        <td class="muted">${esc(b.catatan || '')}</td>
        <td>${esc(b.operator)}</td></tr>`).join('')
        || '<tr><td colspan="7" class="muted">Belum ada cicilan.</td></tr>'}</tbody>
    </table></div>
    <button class="linkbtn" id="mp-unduh-cicilan">⬇ unduh CSV</button>`);
  $('#mp-unduh-cicilan').onclick = () => unduhCsv(`cicilan-piutang-${martHariIni()}.csv`,
    ['Waktu', 'No. struk', 'Member', 'Metode', 'Bank', 'Pengirim', 'Jumlah', 'Catatan', 'Kasir'],
    d.bayar.map((b) => [b.created_at, b.no, b.member, b.metode, b.bank, b.pengirim,
      b.jumlah, b.catatan, b.operator]));
  $('#modal-root .modal').classList.add('modal-lebar');
}

function martUnduhMember() {
  unduhCsv(`member-mart-${martHariIni()}.csv`,
    ['Kode', 'Nama', 'HP', 'Keterangan', 'Plafon', 'Piutang berjalan', 'Sisa plafon', 'Status'],
    martMem.member.map((m) => [m.kode, m.nama, m.hp, m.keterangan, m.batas_hutang,
      m.sisa_piutang, m.sisa_plafon, m.aktif ? 'aktif' : 'nonaktif']));
}

function martUnduhPiutang() {
  unduhCsv(`piutang-mart-${martHariIni()}.csv`,
    ['Tanggal', 'No. struk', 'Member', 'Nilai', 'Dibayar', 'Sisa', 'Umur (hari)'],
    martMem.piutang.map((p) => [p.waktu, p.no, p.member, p.total, p.dibayar, p.sisa, p.umur_hari]));
}

// ------------------------------------------- PEMASOK & PEMBELIAN (layar)

/*
 * "Pemasok" yang berguna bukan sekadar daftar nama. Yang dibutuhkan toko
 * adalah NOTA: barang apa yang datang, berapa harganya, dari siapa, sudah
 * dibayar atau belum. Tanpa itu, harga modal di sistem hanya angka yang
 * diketik sekali lalu tidak pernah benar lagi — dan seluruh laporan laba
 * berdiri di atasnya.
 *
 * Hutang ke pemasok adalah kebalikan persis dari piutang member, dan sengaja
 * ditampilkan dengan bentuk yang sama supaya keduanya bisa dibaca dengan
 * kebiasaan berpikir yang sama.
 */
let martBeli = { supplier: [], beli: [], hutang: [], ringkas: null };

function martBeliView(el) {
  el.innerHTML = `
  <div class="panel hidden" id="mb-hutang-panel">
    <div class="kat-head"><h2 style="margin:0">Hutang ke Sales
      <span class="muted" id="mb-hutang-n"></span></h2>
      <button class="linkbtn" id="mb-unduh-hutang">⬇ unduh CSV</button></div>
    <p class="muted">Barangnya sudah ada di rak, uangnya belum dibayarkan — kebalikan dari
      piutang member. Jatuh tempo yang sudah lewat ditandai merah.</p>
    <div class="tblwrap"><table>
      <thead><tr><th>Nota</th><th>Sales</th><th>Tanggal</th><th>Jatuh tempo</th>
        <th class="num">Nilai</th><th class="num">Dibayar</th><th class="num">Sisa</th><th></th></tr></thead>
      <tbody id="mb-hutang-body"></tbody>
    </table></div>
  </div>

  <div class="panel">
    <div class="kat-head"><h2 style="margin:0">Nota Pembelian <span class="muted" id="mb-n"></span></h2>
      <span><button class="linkbtn" id="mb-unduh">⬇ unduh CSV</button> ·
        <button class="linkbtn" id="mb-catat">+ Beli</button></span></div>
    <p class="muted">Satu nota mengerjakan tiga hal sekaligus: <b>stok naik</b>,
      <b>harga modal diperbarui</b> ke harga beli terbaru, dan <b>kewajiban ke sales lahir</b>.
      Nota tunai/transfer langsung tercatat sebagai uang keluar hari ini — dan tutup kas
      memperhitungkannya, jadi laci yang berkurang tidak lagi muncul sebagai selisih.</p>
    <div id="mb-ringkas" class="cards"></div>
    <div class="filterbar">
      <div class="field"><label>Dari</label><input type="date" id="mb-dari"></div>
      <div class="field"><label>Sampai</label><input type="date" id="mb-sampai"></div>
      <div class="field"><label>Sales</label><select id="mb-f-sup"><option value="">semua</option></select></div>
      <button class="go" id="mb-go">Terapkan</button>
    </div>
    <div class="tblwrap"><table>
      <thead><tr><th>Tanggal</th><th>Nota</th><th>Sales</th><th>Metode</th>
        <th class="num">Item</th><th class="num">Nilai</th><th class="num">Sisa</th><th></th></tr></thead>
      <tbody id="mb-body"></tbody>
    </table></div>
  </div>

  <div class="panel">
    <div class="kat-head"><h2 style="margin:0">Sales <span class="muted" id="mb-ns"></span></h2>
      <span><button class="linkbtn" id="mb-unduh-sup">⬇ unduh CSV</button> ·
        <button class="linkbtn" id="mb-tambah-sup">+ Tambah sales</button></span></div>
    <div class="tblwrap"><table>
      <thead><tr><th>Kode</th><th>Nama</th><th>HP</th><th>Alamat</th>
        <th class="num">Hutang</th><th>Status</th><th></th></tr></thead>
      <tbody id="mb-sup-body"></tbody>
    </table></div>
  </div>`;

  $('#mb-catat').onclick = martNotaModal;
  $('#mb-tambah-sup').onclick = () => martSupplierModal(null);
  $('#mb-go').onclick = martMuatBeli;
  $('#mb-unduh').onclick = martUnduhNota;
  $('#mb-unduh-sup').onclick = martUnduhSupplier;
  $('#mb-unduh-hutang').onclick = martUnduhHutangPemasok;
  martMuatBeli();
}

async function martMuatBeli() {
  const p = new URLSearchParams();
  if ($('#mb-dari')?.value) p.set('dari', $('#mb-dari').value);
  if ($('#mb-sampai')?.value) p.set('sampai', $('#mb-sampai').value);
  if ($('#mb-f-sup')?.value) p.set('supplier_id', $('#mb-f-sup').value);

  const [ds, db, dh] = await Promise.all([
    api('/api/mart/supplier'), api('/api/mart/beli?' + p), api('/api/mart/beli/hutang'),
  ]);
  martBeli = { supplier: ds.supplier, beli: db.beli, hutang: dh.hutang, ringkas: db.ringkas };

  const sel = $('#mb-f-sup');
  if (sel) {
    const dipilih = sel.value;
    sel.innerHTML = '<option value="">semua</option>' +
      ds.supplier.map((s) => `<option value="${s.id}">${esc(s.nama)}</option>`).join('');
    sel.value = dipilih;
  }

  $('#mb-ringkas').innerHTML = `
    <div class="statcard"><span class="muted">Nota pada rentang ini</span><br><b>${db.ringkas.n_nota}</b></div>
    <div class="statcard"><span class="muted">Nilai pembelian</span><br>
      <b class="rp">${rp(db.ringkas.nilai)}</b></div>
    <div class="statcard" style="${dh.total_sisa ? 'border-color:var(--warn)' : ''}">
      <span class="muted">Hutang ke sales</span><br>
      <b class="rp" style="${dh.total_sisa ? 'color:var(--warn)' : ''}">${rp(dh.total_sisa)}</b></div>
    <div class="statcard"><span class="muted">Jumlah sales</span><br><b>${ds.ringkas.n_supplier}</b></div>`;
  $('#mb-n').textContent = `— ${db.beli.length}`;
  $('#mb-ns').textContent = `— ${ds.supplier.length}`;

  // ---- hutang
  $('#mb-hutang-panel').classList.toggle('hidden', !dh.hutang.length);
  $('#mb-hutang-n').textContent = dh.hutang.length ? `— ${dh.hutang.length} nota` : '';
  $('#mb-hutang-body').innerHTML = dh.hutang.map((x, i) => `
    <tr>
      <td><code>${esc(x.no_nota)}</code></td>
      <td><b>${esc(x.supplier)}</b></td>
      <td>${esc(x.tanggal)}</td>
      <td class="${x.telat_hari > 0 ? 'minus' : ''}">${x.jatuh_tempo
        ? esc(x.jatuh_tempo) + (x.telat_hari > 0 ? `<br>telat ${x.telat_hari} hr` : '')
        : '<span class="muted">—</span>'}</td>
      <td class="num rp">${rp(x.total)}</td>
      <td class="num rp">${x.dibayar ? rp(x.dibayar) : '<span class="muted">—</span>'}</td>
      <td class="num rp"><b>${rp(x.sisa)}</b></td>
      <td><button class="linkbtn" data-bayar-sup="${i}">💸 bayar</button></td>
    </tr>`).join('');
  document.querySelectorAll('[data-bayar-sup]').forEach((b) => {
    b.onclick = () => martBayarPemasokModal(martBeli.hutang[Number(b.dataset.bayarSup)]);
  });

  // ---- daftar nota
  const METODE = { tunai: '💵 tunai', transfer: '🏦 transfer', tempo: '📒 tempo' };
  $('#mb-body').innerHTML = db.beli.map((b, i) => `
    <tr${b.status === 'batal' ? ' class="rwm-batal"' : ''}>
      <td>${esc(b.tanggal)}</td>
      <td><code>${esc(b.no_nota || '#' + b.id)}</code>
        ${b.status === 'batal' ? '<br><span class="badge blokir">batal</span>' : ''}</td>
      <td>${esc(b.supplier)}</td>
      <td>${METODE[b.metode] || esc(b.metode)}</td>
      <td class="num">${b.n_item}</td>
      <td class="num rp"><b>${rp(b.total)}</b></td>
      <td class="num rp">${b.sisa ? `<b style="color:var(--warn)">${rp(b.sisa)}</b>`
        : '<span class="muted">lunas</span>'}</td>
      <td><button class="linkbtn" data-nota="${i}">rincian</button></td>
    </tr>`).join('') || '<tr><td colspan="8" class="muted">Belum ada nota pembelian.</td></tr>';
  document.querySelectorAll('#mb-body [data-nota]').forEach((b) => {
    b.onclick = () => martNotaDetail(martBeli.beli[Number(b.dataset.nota)].id);
  });

  // ---- pemasok
  $('#mb-sup-body').innerHTML = ds.supplier.map((s, i) => `
    <tr>
      <td><code>${esc(s.kode)}</code></td>
      <td><b>${esc(s.nama)}</b>${s.keterangan ? `<br><span class="muted">${esc(s.keterangan)}</span>` : ''}</td>
      <td>${esc(s.hp || '—')}</td>
      <td class="muted">${esc(s.alamat || '')}</td>
      <td class="num rp">${s.hutang ? `<b>${rp(s.hutang)}</b>` : '<span class="muted">—</span>'}</td>
      <td><span class="badge ${s.aktif ? 'aktif' : 'nonaktif'}">${s.aktif ? 'aktif' : 'nonaktif'}</span></td>
      <td style="white-space:nowrap">
        <button class="linkbtn" data-barang-s="${i}">📦 barang</button> ·
        <button class="linkbtn" data-ubah-s="${i}">ubah</button> ·
        <button class="linkbtn danger" data-hapus-s="${i}">hapus</button></td>
    </tr>`).join('') || '<tr><td colspan="7" class="muted">Belum ada sales.</td></tr>';
  document.querySelectorAll('#mb-sup-body [data-ubah-s]').forEach((b) => {
    b.onclick = () => martSupplierModal(martBeli.supplier[Number(b.dataset.ubahS)]);
  });
  document.querySelectorAll('#mb-sup-body [data-barang-s]').forEach((b) => {
    b.onclick = () => martBarangSalesModal(martBeli.supplier[Number(b.dataset.barangS)]);
  });
  document.querySelectorAll('#mb-sup-body [data-hapus-s]').forEach((b) => {
    b.onclick = async () => {
      const s = martBeli.supplier[Number(b.dataset.hapusS)];
      if (!confirm(`Hapus sales "${s.nama}"?`)) return;
      try {
        const h = await api('/api/mart/supplier/' + s.id, { method: 'DELETE' });
        toast(h.dinonaktifkan ? h.pesan : '✔ Sales dihapus', 'ok');
        martMuatBeli();
      } catch (e) { toast(e.message, 'err'); }
    };
  });
}

function martSupplierModal(s) {
  openModal(`
    <h2>${s ? 'Ubah' : 'Tambah'} Sales</h2>
    <div class="field"><label>Nama</label>
      <input id="ms-nama" value="${esc(s?.nama || '')}" maxlength="120" placeholder="mis. Toko Grosir Pringsewu"></div>
    <div class="field"><label>Nomor HP</label>
      <input id="ms-hp" value="${esc(s?.hp || '')}" maxlength="25" inputmode="numeric"></div>
    <div class="field"><label>Alamat</label>
      <input id="ms-alamat" value="${esc(s?.alamat || '')}" maxlength="200"></div>
    <div class="field"><label>Keterangan</label>
      <input id="ms-ket" value="${esc(s?.keterangan || '')}" maxlength="160"
        placeholder="mis. kirim tiap Senin & Kamis"></div>
    ${s ? `<label class="cek"><input type="checkbox" id="ms-aktif" ${s.aktif ? 'checked' : ''}>
      aktif</label>` : ''}`,
    async () => {
      const body = { nama: $('#ms-nama').value, hp: $('#ms-hp').value,
        alamat: $('#ms-alamat').value, keterangan: $('#ms-ket').value };
      if (s) body.aktif = $('#ms-aktif').checked ? 1 : 0;
      if (s) await api('/api/mart/supplier/' + s.id, { method: 'PUT', body });
      else await api('/api/mart/supplier', { method: 'POST', body });
      toast('✔ Sales tersimpan', 'ok');
      martMuatBeli();
    });
  const n = $('#ms-nama');
  if (n) n.focus();
}

/** Beli — nota pembelian. Barisnya disusun di layar, hitungannya oleh server. */
async function martNotaModal() {
  let produk;
  try { produk = (await api('/api/mart/barang')).produk; }
  catch (e) { toast(e.message, 'err'); return; }
  if (!martBeli.supplier.filter((s) => s.aktif).length) {
    toast('Tambahkan dulu sales-nya', 'err');
    martSupplierModal(null);
    return;
  }

  const baris = [];
  openModal(`
    <h2>Beli — Nota Pembelian</h2>
    <div class="row" style="gap:10px">
      <div class="field"><label>Sales</label><select id="mn-sup">
        ${martBeli.supplier.filter((s) => s.aktif)
          .map((s) => `<option value="${s.id}">${esc(s.nama)}</option>`).join('')}
      </select></div>
      <div class="field"><label>No. nota sales</label>
        <input id="mn-no" maxlength="60" placeholder="seperti tertulis di kertasnya"></div>
    </div>
    <div class="row" style="gap:10px">
      <div class="field"><label>Tanggal nota</label>
        <input type="date" id="mn-tgl" value="${martHariIni()}"></div>
      <div class="field"><label>Pembayaran</label><select id="mn-metode">
        <option value="tunai">💵 Tunai — uang keluar dari laci hari ini</option>
        <option value="transfer">🏦 Transfer — uang keluar dari rekening</option>
        <option value="tempo">📒 Tempo — dibayar nanti</option>
      </select></div>
    </div>
    <div class="row" style="gap:10px">
      <div class="field hidden" id="mn-tempo-box"><label>Jatuh tempo</label>
        <input type="date" id="mn-tempo"></div>
      <div class="field hidden" id="mn-bank-box"><label>Bank / kanal</label>
        <input id="mn-bank" maxlength="40" placeholder="mis. BSI"></div>
    </div>

    <h3 style="font-size:13.5px; margin-bottom:4px">Barang yang datang</h3>
    <div style="display:flex; gap:8px; align-items:flex-end; margin-bottom:8px">
      <div class="field" style="flex:1; margin:0"><label>Tambah barang</label>
        <input id="mn-pilih" data-scan="1" autocomplete="off"
          placeholder="pindai barcode, atau ketik SKU / nama barang">
        <div id="mn-saran" class="hidden"
          style="border:1px solid var(--line); border-radius:8px; padding:4px 10px; margin-top:4px"></div></div>
      <button class="go" id="mn-tambah" style="flex:none">+ Tambah</button>
    </div>
    <div class="tblwrap"><table>
      <thead><tr><th>Barang</th><th class="num">Modal lama</th><th style="width:110px">Satuan</th>
        <th class="num" style="width:90px">Qty</th>
        <th class="num" style="width:120px">Harga beli</th><th style="width:150px">Expired</th>
        <th class="num">Subtotal</th><th></th></tr></thead>
      <tbody id="mn-body"><tr><td colspan="8" class="muted">Belum ada barang.</td></tr></tbody>
    </table></div>
    <span class="muted set-hint">Harga modal barang akan <b>diperbarui ke harga beli di nota ini</b>.
      Angka lamanya disimpan, jadi kalau notanya dibatalkan ia kembali apa adanya.</span>
    <div class="row" style="gap:10px; margin-top:10px">
      <div class="field"><label>Diskon nota</label>${inputUang({ id: 'mn-diskon', nilai: '' })}</div>
      <div class="field"><label>Catatan</label><input id="mn-catatan" maxlength="200"></div>
    </div>
    <div id="mn-hitung" class="cart"></div>`,
    async () => {
      if (!baris.length) throw new Error('Nota kosong — tambahkan dulu barangnya');
      await api('/api/mart/beli', { method: 'POST', body: {
        supplier_id: Number($('#mn-sup').value), no_nota: $('#mn-no').value,
        metode: $('#mn-metode').value, tanggal: $('#mn-tgl').value,
        jatuh_tempo: $('#mn-tempo').value || undefined, bank: $('#mn-bank').value,
        diskon: nilaiUang('#mn-diskon') || 0, catatan: $('#mn-catatan').value,
        items: baris.map((b) => ({ produk_id: b.id, qty: Number($('#mn-q-' + b.id).value || 0),
          harga: nilaiUang('#mn-h-' + b.id) || 0,
          satuan: b.satuan || 'ecer', expired: $('#mn-e-' + b.id)?.value || '' })),
      }});
      toast('✔ Nota tercatat — stok & harga modal diperbarui', 'ok');
      martMuatBeli();
    }, 'Simpan nota', { statis: true });
  $('#modal-root .modal').classList.add('modal-lebar');

  const gambar = () => {
    $('#mn-body').innerHTML = baris.length ? baris.map((b) => `
      <tr>
        <td>${esc(b.nama)}</td>
        <td class="num muted rp">${b.hpp ? rp(b.hpp) : '—'}</td>
        <td>${b.isiGrosir >= 2
          ? `<select id="mn-sat-${b.id}">
              <option value="ecer" ${b.satuan === 'grosir' ? '' : 'selected'}>pcs</option>
              <option value="grosir" ${b.satuan === 'grosir' ? 'selected' : ''}>dus (${b.isiGrosir})</option>
            </select>`
          : '<span class="muted">pcs</span>'}</td>
        <td class="num"><input type="number" min="1" id="mn-q-${b.id}" value="${b.qty}"
          style="width:70px; text-align:right"></td>
        <td class="num">${inputUang({ id: 'mn-h-' + b.id, nilai: b.harga, kelas: 'bt-kecil' })}</td>
        <td><input type="date" id="mn-e-${b.id}" value="${esc(b.expired || '')}"></td>
        <td class="num rp" id="mn-s-${b.id}">${rp(b.harga * b.qty)}</td>
        <td><button class="linkbtn danger" data-buang-b="${b.id}">✕</button></td>
      </tr>`).join('') : '<tr><td colspan="8" class="muted">Belum ada barang.</td></tr>';
    baris.forEach((b) => {
      $('#mn-q-' + b.id).addEventListener('input', hitung);
      $('#mn-h-' + b.id).addEventListener('input', hitung);
      $('#mn-e-' + b.id).addEventListener('input', () => { b.expired = $('#mn-e-' + b.id).value; });
      // Berganti satuan mengubah apa yang dihitung "rugi": harga beli satu dus
      // wajar jauh di atas harga jual satuannya.
      $('#mn-sat-' + b.id)?.addEventListener('change', () => {
        b.satuan = $('#mn-sat-' + b.id).value;
        hitung();
      });
    });
    document.querySelectorAll('[data-buang-b]').forEach((x) => {
      x.onclick = () => {
        const i = baris.findIndex((b) => b.id === Number(x.dataset.buangB));
        if (i >= 0) baris.splice(i, 1);
        gambar();
        hitung();
      };
    });
    hitung();
  };

  const hitung = () => {
    let sub = 0;
    let rugi = [];
    baris.forEach((b) => {
      const q = Number($('#mn-q-' + b.id)?.value || 0);
      const h = nilaiUang('#mn-h-' + b.id) || 0;
      sub += q * h;
      if ($('#mn-s-' + b.id)) $('#mn-s-' + b.id).textContent = rp(q * h);
      // Pembandingnya ikut satuan: harga beli SATU DUS dibandingkan dengan
      // harga jual satu dus (harga satuan × isinya), bukan dengan harga pcs.
      const jualSetara = b.satuan === 'grosir' && b.isiGrosir >= 2 ? b.jual * b.isiGrosir : b.jual;
      if (h >= jualSetara) rugi.push(b.nama);
    });
    const dis = Math.min(nilaiUang('#mn-diskon') || 0, sub);
    $('#mn-hitung').innerHTML = `
      <div class="cart-line"><span>Subtotal nota</span><span class="rp">${rp(sub)}</span></div>
      <div class="cart-line"><span>Diskon</span><span class="rp">−${rp(dis)}</span></div>
      <div class="cart-line"><span><b>Total dibayar</b></span>
        <span class="rp"><b>${rp(sub - dis)}</b></span></div>
      ${rugi.length ? `<div class="cart-line"><span class="minus">
        ⚠ Harga beli ≥ harga jual: <b>${rugi.map(esc).join(', ')}</b> — periksa lagi, atau
        naikkan harga jualnya di Barang &amp; Stok.</span></div>` : ''}`;
  };

  /*
   * Memilih barang: dipindai atau diketik, bukan digulung dari dropdown.
   *
   * Yang dipegang petugas saat barang datang adalah kardus ber-barcode, dan
   * daftar barang toko bisa ratusan baris — dropdown memaksa mencari nama di
   * urutan alfabet untuk sesuatu yang sudah terbaca mesin dalam sekejap.
   *
   * Barcode PERSIS menang mutlak atas kecocokan nama: pindaian harus mendarat
   * di satu barang, bukan di daftar pilihan.
   */
  const aktif = produk.filter((p) => p.aktif);
  let saran = [];

  const cocokkan = (q) => {
    const t = q.trim().toLowerCase();
    if (!t) return [];
    const persis = aktif.filter((p) => String(p.barcode || '').toLowerCase() === t
      || String(p.barcode_grosir || '').toLowerCase() === t);
    if (persis.length) return persis;
    return aktif.filter((p) => p.nama.toLowerCase().includes(t)
      || String(p.barcode || '').toLowerCase().includes(t)
      || String(p.barcode_grosir || '').toLowerCase().includes(t)).slice(0, 8);
  };

  /**
   * Barang masuk per dus atau per pcs ditentukan oleh KODE YANG DIPINDAI:
   * memindai karton berarti kartonnya yang datang. Mengetik nama tidak
   * menyiratkan apa-apa, jadi jatuh ke eceran seperti sebelumnya — satuannya
   * tetap bisa diganti lewat dropdown di barisnya.
   */
  const satuanDariKode = (q, p) => {
    const t = String(q || '').trim().toLowerCase();
    return p && t && String(p.barcode_grosir || '').toLowerCase() === t
      && (p.isi_grosir || 0) >= 2 ? 'grosir' : 'ecer';
  };

  const gambarSaran = () => {
    const box = $('#mn-saran');
    box.innerHTML = saran.map((p, i) => `<button type="button" class="linkbtn" data-saran="${i}"
      style="display:block; text-align:left; padding:4px 0">${esc(p.nama)}
      <span class="muted">${p.barcode ? '· ' + esc(p.barcode) : ''}${
        p.barcode_grosir ? ' · dus ' + esc(p.barcode_grosir) : ''} · modal ${rp(p.hpp || 0)}</span>
      </button>`).join('');
    box.classList.toggle('hidden', saran.length === 0);
    box.querySelectorAll('[data-saran]').forEach((b) => {
      b.onclick = () => {
        const pil = saran[Number(b.dataset.saran)];
        tambahBarang(pil, satuanDariKode($('#mn-pilih').value, pil));
      };
    });
  };

  const tambahBarang = (p, satuan) => {
    if (!p) return;
    if (baris.some((b) => b.id === p.id)) { toast('Barang itu sudah ada di nota', 'err'); return; }
    const dus = satuan === 'grosir' && (p.isi_grosir || 0) >= 2;
    baris.push({ id: p.id, nama: p.nama, hpp: p.hpp, jual: p.harga, qty: 1,
      // Harga beli bawaan mengikuti satuan yang datang: harga modal SATU DUS
      // untuk baris dus, harga modal satu pcs untuk baris eceran.
      harga: (dus ? p.hpp_grosir : p.hpp) || 0,
      satuan: dus ? 'grosir' : 'ecer', isiGrosir: p.isi_grosir || 0, expired: p.expired || '' });
    if (dus) toast(`Barcode dus — ${p.nama} masuk per dus isi ${p.isi_grosir}`, 'ok');
    $('#mn-pilih').value = '';
    saran = [];
    gambarSaran();
    gambar();
    $('#mn-pilih').focus();
  };

  const ambilCocok = () => {
    const q = $('#mn-pilih').value;
    const c = cocokkan(q);
    if (c.length === 1) { tambahBarang(c[0], satuanDariKode(q, c[0])); return; }
    if (!c.length) { toast('Barang tidak ketemu — periksa barcode atau namanya', 'err'); return; }
    saran = c;
    gambarSaran();
    toast(`${c.length} barang cocok — pilih salah satu`, 'err');
  };

  $('#mn-pilih').addEventListener('input', () => {
    saran = cocokkan($('#mn-pilih').value);
    gambarSaran();
  });
  // Mesin pindai mengirim Enter sesudah kodenya; modal ini statis sehingga
  // Enter tidak lagi menyimpan nota, jadi aman dipakai untuk menambah baris.
  $('#mn-pilih').addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    ambilCocok();
  });
  $('#mn-tambah').onclick = ambilCocok;
  $('#mn-metode').onchange = () => {
    const m = $('#mn-metode').value;
    $('#mn-tempo-box').classList.toggle('hidden', m !== 'tempo');
    $('#mn-bank-box').classList.toggle('hidden', m !== 'transfer');
  };
  $('#mn-diskon').addEventListener('input', hitung);
  gambar();
}

async function martNotaDetail(id) {
  const d = await api('/api/mart/beli/' + id);
  const b = d.beli;
  const METODE = { tunai: '💵 Tunai', transfer: '🏦 Transfer', tempo: '📒 Tempo' };
  openModal(`
    <h2>Nota ${esc(b.no_nota || '#' + b.id)}</h2>
    ${b.status === 'batal' ? `<p class="preview" style="border-color:var(--err); color:var(--err)">
      <b>Nota ini dibatalkan.</b> Stoknya sudah ditarik kembali dan harga modal barangnya
      dikembalikan ke angka sebelum nota ini.</p>` : ''}
    <p class="muted">${esc(b.supplier)} · ${esc(b.tanggal)} ·
      ${METODE[b.metode] || esc(b.metode)}${b.jatuh_tempo ? ` · jatuh tempo ${esc(b.jatuh_tempo)}` : ''}
      · dicatat ${esc(b.operator)}${b.catatan ? `<br>${esc(b.catatan)}` : ''}</p>
    <div class="tblwrap"><table>
      <thead><tr><th>Barang</th><th class="num">Qty</th><th class="num">Harga beli</th>
        <th class="num">Subtotal</th><th class="num">Modal sebelumnya</th></tr></thead>
      <tbody>${d.item.map((i) => `<tr><td>${esc(i.nama)}</td>
        <td class="num">${i.qty}</td><td class="num rp">${rp(i.harga)}</td>
        <td class="num rp">${rp(i.subtotal)}</td>
        <td class="num rp muted">${i.hpp_lama === null ? '—' : rp(i.hpp_lama)}</td></tr>`).join('')}</tbody>
    </table></div>
    <div class="cart">
      <div class="cart-line"><span>Subtotal</span><span class="rp">${rp(b.subtotal)}</span></div>
      ${b.diskon ? `<div class="cart-line"><span>Diskon</span>
        <span class="rp">−${rp(b.diskon)}</span></div>` : ''}
      <div class="cart-line"><span><b>Total</b></span><span class="rp"><b>${rp(b.total)}</b></span></div>
      <div class="cart-line"><span>Sudah dibayar</span><span class="rp">${rp(b.dibayar)}</span></div>
      ${b.sisa ? `<div class="cart-line"><span><b>Sisa hutang</b></span>
        <span class="rp"><b style="color:var(--warn)">${rp(b.sisa)}</b></span></div>` : ''}
    </div>
    ${d.bayar.length ? `<h3 style="font-size:13.5px">Pembayaran</h3>
      <div class="tblwrap"><table>
        <thead><tr><th>Waktu</th><th>Metode</th><th class="num">Jumlah</th>
          <th>Catatan</th><th>Oleh</th></tr></thead>
        <tbody>${d.bayar.map((x) => `<tr><td>${waktuSingkat(x.created_at)}</td>
          <td>${x.metode === 'transfer' ? '🏦 ' + esc(x.bank || 'transfer') : '💵 tunai'}</td>
          <td class="num rp">${rp(x.jumlah)}</td><td class="muted">${esc(x.catatan || '')}</td>
          <td>${esc(x.operator)}</td></tr>`).join('')}</tbody>
      </table></div>` : ''}
    ${b.status === 'sah' && !d.bayar.length && !b.terkunci
      ? '<button class="linkbtn danger" id="mn-batal">✕ batalkan nota</button>'
      : b.status === 'sah' ? `<p class="muted">Nota ini tidak bisa dibatalkan lagi —
          ${d.bayar.length ? 'uangnya sudah keluar' : 'kas tanggal itu sudah ditutup'}.</p>` : ''}`);
  $('#modal-root .modal').classList.add('modal-lebar');
  if ($('#mn-batal')) $('#mn-batal').onclick = () => martBatalNotaModal(b);
}

function martBatalNotaModal(b) {
  openModal(`
    <h2>Batalkan nota ${esc(b.no_nota || '#' + b.id)}?</h2>
    <ul class="muted" style="line-height:1.9">
      <li>Stok yang masuk lewat nota ini <b>ditarik kembali</b>.</li>
      <li>Harga modal barangnya <b>dikembalikan</b> ke angka sebelum nota ini.</li>
      <li>Barisnya <b>tetap ada</b> bertanda batal — nota yang pernah dicatat tidak boleh
        hilang tanpa bekas.</li>
    </ul>
    <div class="field"><label>Alasan <b class="minus">wajib</b></label>
      <input id="mnb-alasan" maxlength="200" placeholder="mis. barang dikembalikan, kemasan rusak"></div>`,
    async () => {
      await api(`/api/mart/beli/${b.id}/batal`, { method: 'POST',
        body: { alasan: $('#mnb-alasan').value } });
      toast('✔ Nota dibatalkan — stok & harga modal dikembalikan', 'ok');
      martMuatBeli();
    }, 'Batalkan nota');
  const a = $('#mnb-alasan');
  if (a) a.focus();
}

/**
 * Barang yang datang dari satu sales, disaring periode.
 *
 * Periodenya ada di depan dan bukan sekadar hiasan: barang yang sama bisa
 * berpindah sales antarperiode, jadi "barang milik sales" tanpa rentang
 * tanggal adalah pertanyaan yang jawabannya selalu setengah benar.
 */
let martBs = null;

function martBarangSalesModal(sup) {
  if (!sup) return;
  martBs = null;
  openModal(`
    <h2>Barang dari ${esc(sup.nama)}</h2>
    <p class="muted" style="margin-top:-6px">${esc(sup.kode)}${sup.hp ? ' · ' + esc(sup.hp) : ''}</p>
    <div class="filterbar" style="margin-bottom:10px">
      <div class="field"><label>Dari</label><input type="date" id="bs-dari"></div>
      <div class="field"><label>Sampai</label><input type="date" id="bs-sampai"></div>
      <button class="go" id="bs-go">Terapkan</button>
      <span style="display:flex; gap:8px; align-items:center; margin-left:auto">
        <select id="bs-format" class="k-chip-select" style="width:auto">
          <option value="xls">Excel (.xls)</option>
          <option value="doc">Word (.doc)</option>
          <option value="pdf">PDF (lewat dialog cetak)</option>
        </select>
        <button class="linkbtn" id="bs-unduh">⬇ unduh</button> ·
        <button class="linkbtn" id="bs-cetak">🖨 cetak</button>
      </span>
    </div>
    <div id="bs-isi" class="muted">memuat…</div>`, null);
  $('#modal-root .modal').classList.add('modal-lebar');

  const qs = () => {
    const p = new URLSearchParams();
    if ($('#bs-dari').value) p.set('dari', $('#bs-dari').value);
    if ($('#bs-sampai').value) p.set('sampai', $('#bs-sampai').value);

    return p.toString();
  };
  const muat = async () => {
    $('#bs-isi').innerHTML = '<span class="muted">memuat…</span>';
    try {
      martBs = await api(`/api/mart/supplier/${sup.id}/barang?` + qs());
    } catch (e) { $('#bs-isi').innerHTML = `<span class="minus">${esc(e.message)}</span>`; return; }
    const t = martBs.total;
    $('#bs-isi').innerHTML = `
      <div class="cards">
        <div class="statcard"><span class="muted">Jenis barang</span><br><b>${t.n_barang}</b></div>
        <div class="statcard"><span class="muted">Total qty</span><br><b>${t.qty.toLocaleString('id-ID')}</b></div>
        <div class="statcard" style="border-color:var(--brand)"><span class="muted">Nilai belanja</span><br>
          <b class="rp" style="color:var(--brand)">${rp(t.nilai)}</b></div>
        <div class="statcard"><span class="muted">Jumlah nota</span><br><b>${t.n_nota}</b></div>
      </div>
      <div class="tblwrap"><table>
        <thead><tr><th>Barang</th><th class="num">Qty</th><th class="num">Nilai</th>
          <th class="num">Harga beli terakhir</th><th class="num">Nota</th>
          <th>Terakhir datang</th></tr></thead>
        <tbody>${martBs.baris.map((b) => `<tr>
          <td><b>${esc(b.nama)}</b></td>
          <td class="num">${b.qty}</td>
          <td class="num rp">${rp(b.nilai)}</td>
          <td class="num rp muted">${rp(b.harga_terakhir)}</td>
          <td class="num">${b.n_nota}</td>
          <td class="muted">${esc(b.terakhir || '')}</td></tr>`).join('')
          || '<tr><td colspan="6" class="muted">Belum ada barang dari sales ini pada rentang itu.</td></tr>'}</tbody>
      </table></div>`;
  };

  $('#bs-go').onclick = muat;
  $('#bs-unduh').onclick = () => {
    if ($('#bs-format').value === 'pdf') { martCetakBarangSales(sup); return; }
    const a = document.createElement('a');
    a.href = `/api/mart/supplier/${sup.id}/barang?${qs()}&format=${$('#bs-format').value}`;
    document.body.appendChild(a); a.click(); a.remove();
  };
  $('#bs-cetak').onclick = () => martCetakBarangSales(sup);
  muat();
}

function martCetakBarangSales(sup) {
  if (!martBs) { toast('Belum ada data untuk dicetak', 'err'); return; }
  const t = martBs.total;
  const periode = martBs.dari || martBs.sampai
    ? `${martBs.dari || 'awal'} s/d ${martBs.sampai || 'kini'}` : 'seluruh periode';
  const gaya = `body{font:12px/1.5 system-ui;margin:18px;color:#111} h1{font-size:17px;margin:0}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th,td{border:1px solid #bbb;padding:5px 8px;text-align:left} th{background:#eee}
    .n{text-align:right} .tebal td{font-weight:700;background:#f6f6f6}`;
  cetakLembar('Barang per Sales', gaya, `
    <h1>Barang dari sales ${esc(sup.nama)} (${esc(sup.kode)})</h1>
    <div>Periode: ${esc(periode)} · dicetak ${new Date().toLocaleString('id-ID')}</div>
    <table>
      <thead><tr><th>Barang</th><th class="n">Qty</th><th class="n">Nilai</th>
        <th class="n">Harga beli terakhir</th><th class="n">Nota</th><th>Terakhir datang</th></tr></thead>
      <tbody>${martBs.baris.map((b) => `<tr><td>${esc(b.nama)}</td><td class="n">${b.qty}</td>
        <td class="n">${rp(b.nilai)}</td><td class="n">${rp(b.harga_terakhir)}</td>
        <td class="n">${b.n_nota}</td><td>${esc(b.terakhir || '')}</td></tr>`).join('')}
        <tr class="tebal"><td>TOTAL</td><td class="n">${t.qty}</td><td class="n">${rp(t.nilai)}</td>
          <td></td><td class="n">${t.n_nota}</td><td></td></tr></tbody>
    </table>
    <p style="margin-top:14px;font-size:11px;color:#555">Nota yang dibatalkan tidak ikut dihitung.
      Nilai dihitung dari harga beli pada notanya, sebelum diskon nota.</p>`);
}

function martBayarPemasokModal(h) {
  openModal(`
    <h2>Bayar Sales</h2>
    <p class="muted"><code>${esc(h.no_nota)}</code> · ${esc(h.supplier)}
      ${h.jatuh_tempo ? ` · jatuh tempo ${esc(h.jatuh_tempo)}` : ''}
      ${h.telat_hari > 0 ? ` · <b class="minus">telat ${h.telat_hari} hari</b>` : ''}</p>
    <div class="cart">
      <div class="cart-line"><span>Nilai nota</span><span class="rp">${rp(h.total)}</span></div>
      <div class="cart-line"><span>Sudah dibayar</span><span class="rp">${rp(h.dibayar)}</span></div>
      <div class="cart-line"><span><b>Sisa</b></span><span class="rp"><b>${rp(h.sisa)}</b></span></div>
    </div>
    <div class="k-chip-baris" style="margin:10px 0 8px">
      <button class="k-chip" id="mbp-lunas">Lunasi ${rp(h.sisa)}</button>
    </div>
    <div class="field"><label>Jumlah dibayar</label>${inputUang({ id: 'mbp-jumlah', nilai: '' })}</div>
    <div class="field"><label>Dibayar dari</label><select id="mbp-metode">
      <option value="tunai">💵 Tunai — uang keluar dari laci</option>
      <option value="transfer">🏦 Transfer — uang keluar dari rekening</option></select></div>
    <div class="field hidden" id="mbp-bank-box"><label>Bank / kanal</label>
      <input id="mbp-bank" maxlength="40"></div>
    <div class="field"><label>Catatan (opsional)</label><input id="mbp-catatan" maxlength="160"></div>
    <p class="muted">Uang ini keluar <b>hari ini</b>, dan tutup kas hari ini yang akan
      memperhitungkannya — jadi laci yang berkurang tidak muncul sebagai selisih.</p>`,
    async () => {
      const d = await api(`/api/mart/beli/${h.id}/bayar`, { method: 'POST', body: {
        jumlah: nilaiUang('#mbp-jumlah') || 0, metode: $('#mbp-metode').value,
        bank: $('#mbp-bank').value, catatan: $('#mbp-catatan').value,
      }});
      toast(d.lunas ? '✔ Hutang ke sales LUNAS' : `✔ Dibayar — sisa ${rp(d.sisa)}`, 'ok');
      martMuatBeli();
    }, 'Bayar');
  $('#mbp-lunas').onclick = () => isiUang('#mbp-jumlah', h.sisa);
  $('#mbp-metode').onchange = () =>
    $('#mbp-bank-box').classList.toggle('hidden', $('#mbp-metode').value !== 'transfer');
  const j = $('#mbp-jumlah');
  if (j) j.focus();
}

function martUnduhNota() {
  unduhCsv(`nota-pembelian-${martHariIni()}.csv`,
    ['Tanggal', 'No. nota', 'Sales', 'Metode', 'Jatuh tempo', 'Item', 'Subtotal', 'Diskon',
      'Total', 'Dibayar', 'Sisa', 'Status', 'Catatan'],
    martBeli.beli.map((b) => [b.tanggal, b.no_nota, b.supplier, b.metode, b.jatuh_tempo || '',
      b.n_item, b.subtotal, b.diskon, b.total, b.dibayar, b.sisa, b.status, b.catatan]));
}

function martUnduhSupplier() {
  unduhCsv(`sales-mart-${martHariIni()}.csv`,
    ['Kode', 'Nama', 'HP', 'Alamat', 'Keterangan', 'Hutang', 'Status'],
    martBeli.supplier.map((s) => [s.kode, s.nama, s.hp, s.alamat, s.keterangan,
      s.hutang, s.aktif ? 'aktif' : 'nonaktif']));
}

function martUnduhHutangPemasok() {
  unduhCsv(`hutang-sales-${martHariIni()}.csv`,
    ['No. nota', 'Sales', 'Tanggal', 'Jatuh tempo', 'Telat (hari)', 'Nilai', 'Dibayar', 'Sisa'],
    martBeli.hutang.map((h) => [h.no_nota, h.supplier, h.tanggal, h.jatuh_tempo || '',
      h.telat_hari ?? '', h.total, h.dibayar, h.sisa]));
}

// ------------------------------- BETULKAN / BATALKAN TRANSAKSI (riwayat)

/** Panel transaksi tertahan di layar Riwayat. */
async function martMuatTahanPanel() {
  let d;
  try { d = await api('/api/mart/tahan'); } catch { return; }
  const panel = $('#rwm-tahan-panel');
  if (!panel) return;
  panel.classList.toggle('hidden', !d.tahan.length);
  $('#rwm-tahan-n').textContent = d.tahan.length ? `— ${d.tahan.length}` : '';
  $('#rwm-tahan-body').innerHTML = d.tahan.map((x) => `
    <tr>
      <td>${waktuSingkat(x.created_at)}</td>
      <td><b>${esc(x.catatan || '—')}</b></td>
      <td class="muted">${esc(x.ringkas || '')}</td>
      <td class="num">${x.n_item}</td>
      <td class="num rp">${rp(x.total)}</td>
      <td>${esc(x.operator || '')}</td>
      <td style="white-space:nowrap">
        <button class="linkbtn" data-lanjut-rw="${x.id}">▶ lanjutkan</button> ·
        <button class="linkbtn danger" data-buang-rw="${x.id}">buang</button></td>
    </tr>`).join('');
  document.querySelectorAll('[data-lanjut-rw]').forEach((b) => {
    b.onclick = () => martTahanLanjut(Number(b.dataset.lanjutRw));
  });
  document.querySelectorAll('[data-buang-rw]').forEach((b) => {
    b.onclick = async () => {
      if (!confirm('Buang transaksi tertahan ini?')) return;
      try {
        await api('/api/mart/tahan/' + b.dataset.buangRw, { method: 'DELETE' });
        toast('Transaksi tertahan dibuang', 'ok');
        martMuatTahanPanel();
      } catch (e) { toast(e.message, 'err'); }
    };
  });
}

/**
 * Betulkan transaksi.
 *
 * Di layar ini terasa seperti menyunting biasa — jumlah, harga, diskon. Yang
 * tidak terlihat: server menyimpan nilai lamanya utuh beserta alasan, pelaku,
 * dan waktunya, lalu menggeser stok sebanyak selisihnya. Jadi kasir dapat
 * kemudahan penuh, dan angka yang sudah dihitung tetap bisa dipertanggung-
 * jawabkan.
 *
 * Baris tidak bisa DITAMBAH dari sini: barang yang terlewat dijual sebagai
 * transaksi baru, persis seperti yang terjadi di dunia nyata.
 */
async function martBetulkanModal(b) {
  let d;
  try { d = await api(`/api/mart/riwayat/${b.jenis}/${b.id}`); }
  catch (e) { toast(e.message, 'err'); return; }
  const t = d.transaksi;

  openModal(`
    <h2>Betulkan ${esc(t.no)}</h2>
    <p class="muted">${esc(waktuSingkat(t.created_at))} ·
      ${MART_METODE_LABEL[t.metode] || esc(t.metode)} · kasir ${esc(t.operator || '-')}</p>
    <div class="tblwrap"><table>
      <thead><tr><th>Barang</th><th class="num" style="width:110px">Harga</th>
        <th class="num" style="width:90px">Jumlah</th><th class="num">Subtotal</th></tr></thead>
      <tbody>${d.item.map((i) => `<tr data-baris="${i.id}">
        <td>${esc(i.nama)}</td>
        <td class="num">${inputUang({ id: 'bt-h-' + i.id, nilai: i.harga, kelas: 'bt-kecil' })}</td>
        <td class="num"><input type="number" min="0" max="99" id="bt-q-${i.id}"
          value="${i.qty}" style="width:70px; text-align:right"></td>
        <td class="num rp" id="bt-s-${i.id}">${rp(i.subtotal)}</td>
      </tr>`).join('')}</tbody>
    </table></div>
    <span class="muted set-hint">Jumlah 0 berarti barang itu dikeluarkan dari transaksi.
      Barang baru tidak bisa ditambahkan di sini — jual sebagai transaksi tersendiri.</span>
    <div class="row" style="gap:10px; margin-top:10px">
      <div class="field"><label>Diskon</label>${inputUang({ id: 'bt-diskon', nilai: t.diskon || '' })}</div>
      ${t.metode === 'tunai' ? `<div class="field"><label>Uang diterima</label>
        ${inputUang({ id: 'bt-uang', nilai: t.uang_diterima })}</div>` : ''}
    </div>
    <div id="bt-hitung" class="cart"></div>
    <div class="field"><label>Alasan pembetulan <b class="minus">wajib</b></label>
      <input id="bt-alasan" maxlength="200" placeholder="mis. terpindai dua kali">
      <span class="muted set-hint">Alasan ini tersimpan permanen bersama nilai lamanya, dan
        bisa dibaca admin pusat. Transaksi terkunci begitu kas hari ini ditutup.</span></div>
    ${d.ubah.length ? `<h3 style="font-size:13.5px">Pernah dibetulkan ${d.ubah.length}×</h3>
      ${martJejakHtml(d.ubah)}` : ''}`,
    async () => {
      await api(`/api/mart/riwayat/jual/${t.id}`, { method: 'PUT', body: {
        alasan: $('#bt-alasan').value,
        diskon: nilaiUang('#bt-diskon') || 0,
        uang_diterima: $('#bt-uang') ? (nilaiUang('#bt-uang') || 0) : undefined,
        item: d.item.map((i) => ({ id: i.id,
          qty: Number($('#bt-q-' + i.id).value || 0),
          harga: nilaiUang('#bt-h-' + i.id) || 0 })),
      }});
      toast('✔ Transaksi dibetulkan — perubahannya tercatat', 'ok');
      martMuatRiwayat();
    }, 'Simpan pembetulan');
  // Modal lebar: tabel barang berkolom empat dan dua isian berdampingan tidak
  // muat di lebar bawaan, dan keduanya jadi menumpuk ke bawah.
  $('#modal-root .modal').classList.add('modal-lebar');

  const hitung = () => {
    let sub = 0;
    d.item.forEach((i) => {
      const q = Number($('#bt-q-' + i.id).value || 0);
      const h = nilaiUang('#bt-h-' + i.id) || 0;
      sub += q * h;
      $('#bt-s-' + i.id).textContent = rp(q * h);
    });
    const dis = Math.min(nilaiUang('#bt-diskon') || 0, sub);
    const total = sub - dis;
    const uang = $('#bt-uang') ? (nilaiUang('#bt-uang') || 0) : total;
    $('#bt-hitung').innerHTML = `
      <div class="cart-line"><span>Subtotal</span><span class="rp">${rp(sub)}</span></div>
      <div class="cart-line"><span>Diskon</span><span class="rp">−${rp(dis)}</span></div>
      <div class="cart-line"><span><b>Total baru</b></span>
        <span class="rp"><b>${rp(total)}</b></span>
        </div>
      <div class="cart-line"><span class="muted">Total semula</span>
        <span class="rp muted">${rp(t.total)}</span></div>
      ${$('#bt-uang') ? `<div class="cart-line"><span>Kembalian</span>
        <span class="rp" style="color:${uang < total ? 'var(--err)' : ''}">
          ${uang < total ? 'uang kurang ' + rp(total - uang) : rp(uang - total)}</span></div>` : ''}`;
  };
  d.item.forEach((i) => {
    $('#bt-q-' + i.id).addEventListener('input', hitung);
    $('#bt-h-' + i.id).addEventListener('input', hitung);
  });
  $('#bt-diskon').addEventListener('input', hitung);
  if ($('#bt-uang')) $('#bt-uang').addEventListener('input', hitung);
  hitung();
}

/** Daftar jejak perubahan, dipakai layar betulkan & layar rincian. */
function martJejakHtml(ubah) {
  return `<div class="tblwrap" style="max-height:30vh; overflow:auto"><table>
    <thead><tr><th>Waktu</th><th>Jenis</th><th>Yang berubah</th>
      <th class="num">Total</th><th>Alasan</th><th>Oleh</th></tr></thead>
    <tbody>${ubah.map((u) => `<tr>
      <td>${waktuSingkat(u.created_at)}</td>
      <td>${u.jenis === 'batal' ? '<span class="badge blokir">batal</span>' : 'koreksi'}</td>
      <td class="muted">${esc(u.ringkas)}</td>
      <td class="num rp">${rp(u.total_lama)} → <b>${rp(u.total_baru)}</b></td>
      <td>${esc(u.alasan)}</td>
      <td>${esc(u.operator)}</td></tr>`).join('')}</tbody>
  </table></div>`;
}

/** Batalkan transaksi — barisnya tinggal, angkanya tidak. */
function martBatalModal(b) {
  openModal(`
    <h2>Batalkan ${esc(b.no)}?</h2>
    <p>Transaksi senilai <b class="rp">${rp(b.total)}</b> akan ditandai <b>batal</b>:</p>
    <ul class="muted" style="line-height:1.9">
      <li>Stok barangnya <b>dikembalikan</b> ke rak.</li>
      <li>Nilainya <b>tidak lagi dihitung</b> sebagai omzet, laba, maupun isi laci.</li>
      <li>Barisnya <b>tetap ada</b> di riwayat bertanda batal — penjualan yang pernah terjadi
        tidak boleh hilang tanpa bekas dari catatan.</li>
    </ul>
    <div class="field"><label>Alasan pembatalan <b class="minus">wajib</b></label>
      <input id="bb-alasan" maxlength="200" placeholder="mis. pembeli berubah pikiran"></div>`,
    async () => {
      await api(`/api/mart/riwayat/jual/${b.id}/batal`, { method: 'POST',
        body: { alasan: $('#bb-alasan').value } });
      toast('✔ Transaksi dibatalkan, stok dikembalikan', 'ok');
      martMuatRiwayat();
    }, 'Batalkan transaksi');
  const a = $('#bb-alasan');
  if (a) a.focus();
}

function martUnduhRiwayatMart() {
  const r = martRw.ringkas;
  unduhCsv(`riwayat-mart-${$('#rwm-dari').value || 'awal'}-sd-${$('#rwm-sampai').value || 'kini'}.csv`,
    ['Waktu', 'No. struk', 'Metode', 'Jumlah item', 'Diskon', 'Total', 'Keterangan', 'Kasir'],
    [...martRw.baris.map((b) => [b.waktu, b.no, b.metode, b.n_item ?? '', b.diskon || 0,
      b.total, b.ket || '', b.operator || '']),
      [], ['RINGKASAN HALAMAN INI', ''],
      ['Transaksi (seluruh periode)', r?.n ?? ''], ['Omzet', r?.omzet ?? ''],
      ['Modal barang terjual (HPP)', r?.hpp ?? ''], ['Laba', r?.laba ?? ''],
      ['Tunai', r?.tunai ?? ''], ['Transfer', r?.transfer ?? ''], ['Kartu', r?.kartu ?? ''],
      ['Hutang (belum dibayar)', r?.hutang ?? ''], ['Hutang (dibayar)', r?.pelunasan ?? '']]);
}

// ╔══════════════════════════════════════════════════════════╗
// ║ TENANT: MENU (CRUD produk)                                ║
// ╚══════════════════════════════════════════════════════════╝
// Menambah, mengubah nama/harga, dan menghapus menu adalah PENGAJUAN — daftar
// yang dipakai kasir baru berubah setelah admin menyetujui. Yang langsung
// berlaku hanya tombol habis/tersedia.
const AJU_STATUS = {
  menunggu: 'menunggu putusan admin', disetujui: 'disetujui',
  ditolak: 'ditolak', batal: 'dibatalkan',
};
const AJU_JENIS = { baru: 'Menu baru', ubah: 'Ubah menu', hapus: 'Hapus menu' };

function menuView(el) {
  el.innerHTML = `<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px">
      <h2 style="margin:0">Menu ${esc(USER.tenant_nama)}</h2>
      <span>
        <button class="linkbtn" id="btn-add-menu">+ Ajukan menu baru</button> ·
        <button class="linkbtn" id="btn-impor-menu">⬆ Ajukan banyak dari Excel</button>
      </span>
    </div>
    <div class="aju-unduh hidden" id="impor-menu-box">
      <span class="muted">Untuk puluhan sampai ratusan barang sekaligus:</span>
      <button class="linkbtn" id="impor-menu-tpl">⬇ Unduh template Excel</button>
      <label class="linkbtn" style="cursor:pointer">📂 Pilih berkas yang sudah diisi
        <input type="file" id="impor-menu-berkas" accept=".xlsx,.csv" class="hidden"></label>
      <span class="muted set-hint">Berkas diperiksa dulu sebelum dikirim. Semua barangnya menjadi
        satu paket pengajuan yang diputus admin sekaligus.</span>
    </div>
    <p class="muted">Menambah menu, mengubah nama/harga, dan menghapus menu perlu
      <b>persetujuan admin</b>. Selama menunggu, kasir tetap memakai harga lama.
      Menandai menu <b>habis/tersedia</b> berlaku langsung tanpa persetujuan.</p>
    <div class="tblwrap"><table>
      <thead><tr><th>Menu</th><th class="num">Harga</th><th>Ketersediaan</th><th></th></tr></thead>
      <tbody id="menu-body"></tbody>
    </table></div>
  </div>

  <div class="panel">
    <h2>Pengajuan Saya</h2>
    <div class="tblwrap"><table>
      <thead><tr><th>Waktu</th><th>Jenis</th><th>Usulan</th><th>Status</th><th></th></tr></thead>
      <tbody id="aju-body"></tbody>
    </table></div>
  </div>`;
  $('#btn-add-menu').onclick = () => menuModal(null);
  $('#btn-impor-menu').onclick = () => $('#impor-menu-box').classList.toggle('hidden');
  $('#impor-menu-tpl').onclick = () => {
    // Berkas biner: lewat <a>, bukan fetch+JSON.
    const a = document.createElement('a');
    a.href = '/api/produk/impor/template';
    document.body.appendChild(a); a.click(); a.remove();
  };
  $('#impor-menu-berkas').onchange = async (e) => {
    const f = e.target.files[0];
    e.target.value = '';   // berkas yang sama boleh dipilih lagi sesudah diperbaiki
    if (f) imporMenuPratinjau(f);
  };
  loadMenu();
}

/** Pratinjau berkas pengajuan barang, lalu kirim sebagai satu paket. */
async function imporMenuPratinjau(file) {
  let data, d;
  try {
    data = await fileToDataUrl(file);
    d = await api('/api/produk/impor/pratinjau', { method: 'POST', body: { data, nama_berkas: file.name } });
  } catch (e) { toast(e.message, 'err'); return; }

  const r = d.ringkas;
  const baris = d.baris.map((b) => `
    <tr class="${b.status === 'siap' ? '' : 'tp-menunggu'}">
      <td class="num muted">${b.baris}</td>
      <td>${esc(b.nama || '—')}</td>
      <td class="num rp">${b.harga ? rp(b.harga) : '—'}</td>
      ${d.koperasi ? `<td style="white-space:nowrap">${lencanaKategori(b.kategori)}</td>` : ''}
      <td>${b.status === 'siap' ? '<span class="badge aktif">siap</span>'
        : `<span class="minus">${esc(b.pesan)}</span>`}</td>
    </tr>`).join('');
  openModal(`
    <h2>Pratinjau pengajuan barang</h2>
    <p><b>${esc(d.nama_berkas)}</b> — ${r.n_baris} baris:
      <b>${r.n_siap} siap diajukan</b>${r.n_gagal ? `, <span class="minus">${r.n_gagal} dilewati</span>` : ''}.</p>
    ${d.koperasi ? `<p class="muted">Kategori hanyalah usulan Anda; admin yang menetapkan akhirnya.</p>` : ''}
    ${r.n_gagal ? `<p class="muted">Baris yang dilewati tidak ikut dikirim. Perbaiki di Excel lalu
      unggah lagi kalau ingin mengajukannya juga.</p>` : ''}
    <div class="tblwrap" style="max-height:50vh; overflow:auto"><table>
      <thead><tr><th class="num">Baris</th><th>Barang</th><th class="num">Harga</th>
        ${d.koperasi ? '<th>Kategori</th>' : ''}<th>Status</th></tr></thead>
      <tbody>${baris}</tbody>
    </table></div>`,
    r.n_siap ? async () => {
      const paket = 'MNU-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
      const h = await api('/api/produk/impor/terapkan', { method: 'POST',
        body: { data, nama_berkas: d.nama_berkas, sidik: d.sidik, paket } });
      toast(`✔ ${h.n} barang diajukan sebagai satu paket — menunggu putusan admin`, 'ok');
      loadMenu();
    } : null, `Kirim ${r.n_siap} barang`);
  $('#modal-root .modal').classList.add('modal-lebar');
}

/** Ringkasan "dari → menjadi" supaya isi usulan terbaca tanpa membuka apa pun. */
function ajuanRingkas(a) {
  if (a.jenis === 'baru') return `<b>${esc(a.nama)}</b> · ${rp(a.harga)}`;
  if (a.jenis === 'hapus') return `<b>${esc(a.nama_lama)}</b> · ${rp(a.harga_lama)}`;
  const namaBeda = a.nama !== a.nama_lama;
  const hargaBeda = a.harga !== a.harga_lama;
  return [
    namaBeda ? `${esc(a.nama_lama)} → <b>${esc(a.nama)}</b>` : `<b>${esc(a.nama)}</b>`,
    hargaBeda ? `${rp(a.harga_lama)} → <b>${rp(a.harga)}</b>` : rp(a.harga),
  ].join(' · ');
}

async function loadMenu() {
  const [d, da] = await Promise.all([api('/api/produk'), api('/api/produk/ajuan')]);
  // Menu yang sedang diusulkan ditandai supaya tenant tidak mengajukan dua kali
  // lalu bingung kenapa ditolak server.
  const menunggu = new Set(da.ajuan.filter((a) => a.status === 'menunggu' && a.produk_id).map((a) => a.produk_id));
  $('#menu-body').innerHTML = d.produk.map((p) => `
    <tr>
      <td><b>${esc(p.nama)}</b>${menunggu.has(p.id) ? ' <span class="badge tp-pending">ada pengajuan</span>' : ''}</td>
      <td class="num rp">${rp(p.harga)}</td>
      <td><span class="badge ${p.aktif ? 'aktif' : 'nonaktif'}">${p.aktif ? 'tersedia' : 'habis'}</span></td>
      <td style="white-space:nowrap">
        <button class="linkbtn" data-act="edit" data-id="${p.id}">ajukan ubah</button> ·
        <button class="linkbtn" data-act="toggle" data-id="${p.id}">tandai ${p.aktif ? 'habis' : 'tersedia'}</button> ·
        <button class="linkbtn danger" data-act="del" data-id="${p.id}">ajukan hapus</button>
      </td>
    </tr>`).join('') || '<tr><td colspan="4" class="muted">Belum ada menu</td></tr>';

  $('#aju-body').innerHTML = da.ajuan.map((a) => `
    <tr class="${a.status === 'menunggu' ? 'tp-menunggu' : ''}">
      <td>${esc(waktuSingkat(a.created_at))}</td>
      <td>${esc(AJU_JENIS[a.jenis] || a.jenis)}</td>
      <td>${ajuanRingkas(a)}</td>
      <td><span class="badge tp-${a.status === 'menunggu' ? 'pending' : esc(a.status)}">${esc(AJU_STATUS[a.status] || a.status)}</span>
        ${a.catatan ? `<br><span class="muted">${esc(a.catatan)}</span>` : ''}</td>
      <td>${a.status === 'menunggu'
        ? `<button class="linkbtn danger" data-batal="${a.id}">batalkan</button>` : ''}</td>
    </tr>`).join('') || '<tr><td colspan="5" class="muted">Belum ada pengajuan</td></tr>';

  document.querySelectorAll('#menu-body .linkbtn').forEach((b) => {
    const id = Number(b.dataset.id);
    const p = d.produk.find((x) => x.id === id);
    if (b.dataset.act === 'edit') b.onclick = () => menuModal(p);
    if (b.dataset.act === 'toggle') b.onclick = async () => {
      await api(`/api/produk/${id}/ketersediaan`, { method: 'PUT', body: { aktif: !p.aktif } })
        .catch((e) => toast(e.message, 'err'));
      loadMenu();
    };
    if (b.dataset.act === 'del') b.onclick = () => {
      openModal(`<h2>Ajukan hapus menu?</h2>
        <p><b>${esc(p.nama)}</b> akan dihapus <b>setelah disetujui admin</b>.
        Sampai itu terjadi, menu ini masih dijual seperti biasa.</p>`, async () => {
        await api('/api/produk/' + id, { method: 'DELETE' });
        toast('Pengajuan hapus dikirim ke admin', 'ok');
        loadMenu();
      }, 'Ajukan hapus');
    };
  });
  document.querySelectorAll('#aju-body [data-batal]').forEach((b) => {
    b.onclick = async () => {
      await api('/api/produk/ajuan/' + b.dataset.batal, { method: 'DELETE' }).catch((e) => toast(e.message, 'err'));
      loadMenu();
    };
  });
}

function menuModal(p) {
  openModal(`
    <h2>${p ? 'Ajukan Ubah Menu' : 'Ajukan Menu Baru'}</h2>
    <p class="muted">Usulan ini dikirim ke admin. Menu ${p ? 'tetap dijual dengan nama & harga lama' : 'belum muncul di kasir'} sampai disetujui.</p>
    <div class="field"><label>Nama menu</label><input id="m-nama" value="${esc(p?.nama || '')}"></div>
    <div class="field"><label>Harga (Rp)</label>${inputUang({ id: 'm-harga', nilai: p?.harga || '' })}</div>`,
    async () => {
      const body = { nama: $('#m-nama').value, harga: nilaiUang('#m-harga') };
      if (p) await api('/api/produk/' + p.id, { method: 'PUT', body });
      else await api('/api/produk', { method: 'POST', body });
      toast('✔ Pengajuan dikirim ke admin', 'ok');
      loadMenu();
    });
}

// ╔══════════════════════════════════════════════════════════╗
// ║ SUPERADMIN: PERSETUJUAN MENU TENANT                       ║
// ╚══════════════════════════════════════════════════════════╝
let ajuanStatus = 'menunggu';

function menuAjuanView(el) {
  el.innerHTML = `<div class="panel">
    <h2>Pengajuan Menu Tenant</h2>
    <p class="muted">Tenant mengusulkan menu baru, perubahan nama/harga, dan penghapusan menu.
      Daftar menu di kasir <b>tidak berubah</b> sampai Anda menyetujui — selama menunggu,
      santri tetap membayar dengan harga lama. Menandai menu habis/tersedia adalah keputusan
      harian tenant dan tidak lewat sini.</p>
    <div class="quick" id="aju-filter">
      ${['menunggu', 'disetujui', 'ditolak', 'batal'].map((s) =>
        `<button data-status="${s}" class="${s === ajuanStatus ? 'sel' : ''}">${esc(AJU_STATUS[s])}</button>`).join('')}
    </div>
    <div class="aju-unduh">
      <span class="muted">Form untuk ditinjau pimpinan:</span>
      <a class="linkbtn" id="aju-cetak" target="_blank" rel="noopener">🖨 Cetak / PDF</a>
      <a class="linkbtn" id="aju-xls">⬇ Excel (.xls)</a>
      <a class="linkbtn" id="aju-csv">⬇ CSV</a>
      <span class="muted set-hint">Berisi baris yang sama dengan penyaring di atas, lengkap
        dengan kolom keputusan dan tanda tangan. Versi PDF lewat tombol cetak peramban.</span>
    </div>
    <div id="aju-paket"></div>
    <div class="tblwrap"><table>
      <thead><tr><th>Waktu</th><th>Tenant</th><th>Jenis</th><th>Usulan</th><th>Pengaju</th><th>Status</th><th></th></tr></thead>
      <tbody id="aju-sa-body"></tbody>
    </table></div>
  </div>

  <div class="panel" id="kat-panel">
    <div class="kat-head">
      <h2 style="margin:0">🧴 Kategori Barang Koperasi</h2>
      <button class="linkbtn" id="kat-muat">muat ulang</button>
    </div>
    <p class="muted">Hanya tenant <b>koperasi</b> yang muncul di sini. Barang bertanda
      <b>non-makanan</b> memakai jatah belanja harian tersendiri (diatur di Dashboard) dan tidak
      memotong jatah makan santri. Kategori adalah keputusan sekolah — tenant tidak bisa
      mengubahnya sendiri, termasuk lewat pengajuan menu.</p>
    <div id="kat-tenant"><p class="muted">memuat…</p></div>
  </div>

  <div class="panel" id="menu-tenant-panel">
    <div class="kat-head">
      <h2 style="margin:0">📋 Semua Menu per Tenant</h2>
      <button class="linkbtn" id="mt-muat">muat ulang</button>
    </div>
    <p class="muted">Ubah/hapus di sini <b>langsung berlaku</b> di kasir, tanpa menunggu ajuan —
      pakai untuk membetulkan data, bukan pengganti alur pengajuan tenant sehari-hari.
      Perubahannya tetap tercatat di riwayat pengajuan di atas.</p>
    <div class="field" style="max-width:320px">
      <label>Tenant</label>
      <select id="mt-pilih-tenant"></select>
    </div>
    <div class="aju-unduh">
      <span class="muted">Unduh daftar menu:</span>
      <select id="mt-kategori">
        <option value="semua">Semua tenant</option>
        <option value="koperasi">Koperasi</option>
        <option value="mart">IMBOS Mart / Swalayan</option>
        <option value="kantin">Tenant / Kantin biasa</option>
      </select>
      <a class="linkbtn" id="mt-cetak" target="_blank" rel="noopener">🖨 Cetak / PDF</a>
      <a class="linkbtn" id="mt-xls">⬇ Excel (.xls)</a>
      <a class="linkbtn" id="mt-csv">⬇ CSV</a>
    </div>
    <div id="mt-tenant"><p class="muted">memuat…</p></div>
  </div>`;
  /*
   * Tautan unduh ikut berubah setiap kali penyaringnya diganti. Kalau tidak,
   * admin menyaring "menunggu" lalu mengunduh dan mendapat seluruh riwayat —
   * dan rapat memutuskan daftar yang berbeda dari yang ada di layar.
   */
  const segarkanUnduh = () => {
    const q = 'status=' + encodeURIComponent(ajuanStatus);
    $('#aju-cetak').href = '/api/produk/ajuan/cetak?' + q;
    $('#aju-xls').href = '/api/produk/ajuan/ekspor?format=xls&' + q;
    $('#aju-csv').href = '/api/produk/ajuan/ekspor?format=csv&' + q;
  };
  segarkanUnduh();

  $('#aju-filter').onclick = (e) => {
    const b = e.target.closest('button[data-status]');
    if (!b) return;
    ajuanStatus = b.dataset.status;
    document.querySelectorAll('#aju-filter button').forEach((x) => x.classList.toggle('sel', x === b));
    segarkanUnduh();
    loadAjuanSA();
  };

  // Unduhan daftar menu disaring per KATEGORI tenant (koperasi/mart/kantin),
  // terpisah dari dropdown tenant di atas yang hanya untuk ubah/hapus satu per satu.
  const segarkanUnduhMenu = () => {
    const q = 'kategori=' + encodeURIComponent($('#mt-kategori').value);
    $('#mt-cetak').href = '/api/produk/cetak?' + q;
    $('#mt-xls').href = '/api/produk/ekspor?format=xls&' + q;
    $('#mt-csv').href = '/api/produk/ekspor?format=csv&' + q;
  };
  segarkanUnduhMenu();
  $('#mt-kategori').onchange = segarkanUnduhMenu;

  $('#kat-muat').onclick = muatKategoriProduk;
  $('#mt-muat').onclick = muatMenuTenant;
  loadAjuanSA();
  muatKategoriProduk();
  muatMenuTenant();
}

/** Daftar barang tiap tenant koperasi + tombol pindah kategori. */
async function muatKategoriProduk() {
  const box = $('#kat-tenant');
  if (!box) return;
  const { tenants } = await api('/api/tenants');
  const koperasi = tenants.filter((t) => t.jenis === 'koperasi');
  if (!koperasi.length) {
    box.innerHTML = `<p class="muted">Belum ada tenant berjenis koperasi. Ubah jenis tenant di
      menu <b>Tenant</b> lebih dulu — barang non-makanan hanya bisa ada di koperasi.</p>`;
    return;
  }
  const daftar = await Promise.all(koperasi.map((t) =>
    api('/api/produk?tenant_id=' + t.id).then((d) => ({ t, produk: d.produk }))));

  box.innerHTML = daftar.map(({ t, produk }) => {
    const nm = produk.filter((p) => p.kategori === 'non_makanan');
    return `
    <h3 style="margin:14px 0 6px">${esc(t.nama)}
      <span class="muted" style="font-weight:400">· ${produk.length} barang, ${nm.length} non-makanan</span></h3>
    <div class="tblwrap"><table>
      <thead><tr><th>Barang</th><th class="num">Harga</th><th>Kategori</th><th>Ketersediaan</th><th></th></tr></thead>
      <tbody>${produk.map((p) => `
        <tr>
          <td><b>${esc(p.nama)}</b></td>
          <td class="num rp">${rp(p.harga)}</td>
          <td>${lencanaKategori(p.kategori)}</td>
          <td>${p.aktif ? '<span class="badge aktif">tersedia</span>' : '<span class="badge blokir">habis</span>'}</td>
          <td><button class="linkbtn" data-kat="${p.id}" data-ke="${p.kategori === 'non_makanan' ? 'makanan' : 'non_makanan'}">
            jadikan ${p.kategori === 'non_makanan' ? 'makanan' : 'non-makanan'}</button></td>
        </tr>`).join('') || '<tr><td colspan="5" class="muted">Belum ada barang</td></tr>'}</tbody>
    </table></div>`;
  }).join('');

  box.querySelectorAll('[data-kat]').forEach((b) => {
    b.onclick = async () => {
      b.disabled = true;
      try {
        const d = await api(`/api/produk/${b.dataset.kat}/kategori`, {
          method: 'PUT', body: { kategori: b.dataset.ke } });
        toast(`✔ "${d.produk.nama}" kini ${KATEGORI_LABEL[d.produk.kategori].toLowerCase()}`, 'ok');
        muatKategoriProduk();
      } catch (e) { toast(e.message, 'err'); b.disabled = false; }
    };
  });
}

let mtTenantId = null;

/** Memuat daftar tenant ke dropdown, lalu tabel menu tenant yang terpilih. */
async function muatMenuTenant() {
  const sel = $('#mt-pilih-tenant');
  if (!sel) return;
  const { tenants } = await api('/api/tenants');
  if (!tenants.length) {
    sel.innerHTML = '';
    $('#mt-tenant').innerHTML = '<p class="muted">Belum ada tenant.</p>';
    return;
  }
  if (!mtTenantId || !tenants.some((t) => t.id === mtTenantId)) mtTenantId = tenants[0].id;
  sel.innerHTML = tenants.map((t) =>
    `<option value="${t.id}" ${t.id === mtTenantId ? 'selected' : ''}>${esc(t.nama)}</option>`).join('');
  sel.onchange = () => { mtTenantId = Number(sel.value); muatMenuTenantTabel(); };
  await muatMenuTenantTabel();
}

/** Tabel menu tenant yang sedang terpilih saja, dengan ubah/hapus LANGSUNG. */
async function muatMenuTenantTabel() {
  const box = $('#mt-tenant');
  if (!box || !mtTenantId) return;
  const { produk } = await api('/api/produk?tenant_id=' + mtTenantId);

  box.innerHTML = `<div class="tblwrap"><table>
    <thead><tr><th>Menu</th><th class="num">Harga</th><th>Ketersediaan</th><th></th></tr></thead>
    <tbody>${produk.map((p) => `
      <tr>
        <td><b>${esc(p.nama)}</b></td>
        <td class="num rp">${rp(p.harga)}</td>
        <td>${p.aktif ? '<span class="badge aktif">tersedia</span>' : '<span class="badge blokir">habis</span>'}</td>
        <td style="white-space:nowrap">
          <button class="linkbtn" data-mt-edit="${p.id}">ubah</button> ·
          <button class="linkbtn danger" data-mt-hapus="${p.id}">hapus</button>
        </td>
      </tr>`).join('') || '<tr><td colspan="4" class="muted">Belum ada menu</td></tr>'}</tbody>
  </table></div>`;

  box.querySelectorAll('[data-mt-edit]').forEach((b) => {
    const p = produk.find((x) => x.id === Number(b.dataset.mtEdit));
    b.onclick = () => menuLangsungModal(p);
  });
  box.querySelectorAll('[data-mt-hapus]').forEach((b) => {
    const id = Number(b.dataset.mtHapus);
    const p = produk.find((x) => x.id === id);
    b.onclick = () => {
      openModal(`<h2>Hapus menu?</h2>
        <p>Menu <b>${esc(p.nama)}</b> akan dihapus <b>langsung</b>, tanpa menunggu persetujuan.</p>`,
        async () => {
          await api(`/api/produk/${id}/langsung`, { method: 'DELETE' });
          toast('✔ Menu dihapus', 'ok');
          muatMenuTenantTabel();
        }, 'Hapus');
    };
  });
}

function menuLangsungModal(p) {
  openModal(`
    <h2>Ubah Menu Langsung</h2>
    <p class="muted">Perubahan ini <b>langsung berlaku</b> di kasir, tanpa menunggu persetujuan.</p>
    <div class="field"><label>Nama menu</label><input id="mt-nama" value="${esc(p.nama)}"></div>
    <div class="field"><label>Harga (Rp)</label>${inputUang({ id: 'mt-harga', nilai: p.harga })}</div>`,
    async () => {
      const body = { nama: $('#mt-nama').value, harga: nilaiUang('#mt-harga') };
      await api(`/api/produk/${p.id}/langsung`, { method: 'PUT', body });
      toast('✔ Menu diubah', 'ok');
      muatMenuTenantTabel();
    });
}

/**
 * Paket pengajuan dari Excel yang masih menunggu. Ditampilkan di atas daftar
 * biasa karena satu paket bisa berisi ratusan barang — menyetujuinya satu per
 * satu dari tabel di bawah hanya memindahkan pekerjaan lambat dari tenant ke admin.
 */
async function muatPaketAjuan() {
  const box = $('#aju-paket');
  if (!box) return;
  let d;
  try { d = await api('/api/produk/ajuan/paket'); } catch { box.innerHTML = ''; return; }
  if (!d.paket.length) { box.innerHTML = ''; return; }
  box.innerHTML = `
    <div class="aju-paket">
      <h3 style="margin:0 0 6px">📦 Paket dari Excel menunggu putusan</h3>
      ${d.paket.map((p) => `
        <div class="aju-paket-baris">
          <span><b>${esc(p.tenant_nama)}</b> · ${p.n} barang
            ${Number(p.n_non_makanan) ? `<span class="muted">(${p.n_non_makanan} diusulkan non-makanan)</span>` : ''}
            <br><span class="muted">diajukan ${esc(waktuSingkat(p.created_at))} oleh ${esc(p.pengaju || '-')}</span></span>
          <button class="go" data-paket="${esc(p.paket)}">Periksa & putuskan</button>
        </div>`).join('')}
    </div>`;
  box.querySelectorAll('[data-paket]').forEach((b) => { b.onclick = () => paketModal(b.dataset.paket); });
}

async function paketModal(paket) {
  let d;
  try { d = await api('/api/produk/ajuan/paket/' + encodeURIComponent(paket)); }
  catch (e) { toast(e.message, 'err'); return; }
  const kop = d.tenant?.jenis === 'koperasi';
  const menunggu = d.ajuan.filter((a) => a.status === 'menunggu');
  if (!menunggu.length) { toast('Paket ini sudah diputus seluruhnya', 'ok'); muatPaketAjuan(); return; }

  openModal(`
    <h2>Paket ${esc(d.tenant?.nama || '')} — ${menunggu.length} barang</h2>
    <p class="muted">Hapus centang barang yang belum mau disetujui — barang itu tetap menunggu
      dan bisa diputus satu per satu di daftar bawah.
      ${kop ? 'Kategori terisi usulan tenant; ubah bila perlu — keputusan akhirnya di sini.' : ''}</p>
    <p><label><input type="checkbox" id="pk-semua" checked style="width:auto"> centang semua</label></p>
    <div class="tblwrap" style="max-height:50vh; overflow:auto"><table>
      <thead><tr><th></th><th>Barang</th><th class="num">Harga</th>${kop ? '<th>Kategori</th>' : ''}</tr></thead>
      <tbody>${menunggu.map((a) => `<tr>
        <td><input type="checkbox" class="pk-cek" value="${a.id}" checked style="width:auto"></td>
        <td>${esc(a.nama)}</td>
        <td class="num rp">${rp(a.harga)}</td>
        ${kop ? `<td><select class="pk-kat" data-id="${a.id}">
          <option value="makanan" ${a.kategori_usulan !== 'non_makanan' ? 'selected' : ''}>Makanan</option>
          <option value="non_makanan" ${a.kategori_usulan === 'non_makanan' ? 'selected' : ''}>Non-makanan</option>
        </select></td>` : ''}
      </tr>`).join('')}</tbody>
    </table></div>
    <details style="margin-top:12px"><summary class="linkbtn danger">Tolak seluruh paket…</summary>
      <div class="field" style="margin-top:8px"><label>Alasan penolakan (wajib — dibaca tenant)</label>
        <input id="pk-alasan" placeholder="mis. harga belum disepakati koperasi"></div>
      <button class="linkbtn danger" id="pk-tolak">Tolak ${menunggu.length} barang</button>
    </details>`,
    async () => {
      const ids = [...document.querySelectorAll('.pk-cek:checked')].map((c) => Number(c.value));
      if (!ids.length) throw new Error('Belum ada barang yang dicentang');
      const kategori = {};
      document.querySelectorAll('.pk-kat').forEach((s) => { if (ids.includes(Number(s.dataset.id))) kategori[s.dataset.id] = s.value; });
      const h = await api(`/api/produk/ajuan/paket/${encodeURIComponent(paket)}/setujui`, {
        method: 'POST', body: { ids, kategori } });
      toast(`✔ ${h.n_disetujui} barang disetujui` +
        (h.n_kembar ? ` · ${h.n_kembar} ditolak karena namanya sudah ada` : '') +
        (h.sisa ? ` · ${h.sisa} masih menunggu` : ''), 'ok');
      loadAjuanSA();
      muatKategoriProduk();
    }, 'Setujui yang dicentang');
  $('#modal-root .modal').classList.add('modal-lebar');

  const hitung = () => {
    const n = document.querySelectorAll('.pk-cek:checked').length;
    $('#m-save').textContent = `Setujui ${n} barang`;
  };
  $('#pk-semua').onchange = (e) => {
    document.querySelectorAll('.pk-cek').forEach((c) => { c.checked = e.target.checked; });
    hitung();
  };
  document.querySelectorAll('.pk-cek').forEach((c) => { c.onchange = hitung; });
  hitung();
  $('#pk-tolak').onclick = async () => {
    try {
      const h = await api(`/api/produk/ajuan/paket/${encodeURIComponent(paket)}/tolak`, {
        method: 'POST', body: { catatan: $('#pk-alasan').value } });
      toast(`${h.n_ditolak} barang ditolak`, 'ok');
      closeModal();
      loadAjuanSA();
    } catch (e) { toast(e.message, 'err'); }
  };
}

async function loadAjuanSA() {
  muatPaketAjuan();
  // Daftar tenant hanya dipakai untuk mengenali mana yang koperasi, dan tidak
  // bergantung pada daftar ajuan — jadi keduanya diminta bersamaan.
  const [d, tenantResp] = await Promise.all([
    api('/api/produk/ajuan?status=' + ajuanStatus),
    api('/api/tenants').catch(() => ({ tenants: [] })),
  ]);
  setLencanaAjuan(d.menunggu);
  $('#aju-sa-body').innerHTML = d.ajuan.map((a) => `
    <tr class="${a.status === 'menunggu' ? 'tp-menunggu' : ''}">
      <td>${esc(waktuSingkat(a.created_at))}</td>
      <td>${esc(a.tenant_nama)}</td>
      <td>${esc(AJU_JENIS[a.jenis] || a.jenis)}${a.paket ? ' <span class="badge" title="bagian dari paket Excel">📦 Excel</span>' : ''}</td>
      <td>${ajuanRingkas(a)}</td>
      <td>${esc(a.pengaju || '-')}</td>
      <td><span class="badge tp-${a.status === 'menunggu' ? 'pending' : esc(a.status)}">${esc(AJU_STATUS[a.status] || a.status)}</span>
        ${a.penilai ? `<br><span class="muted">oleh ${esc(a.penilai)}</span>` : ''}
        ${a.catatan ? `<br><span class="muted">${esc(a.catatan)}</span>` : ''}</td>
      <td style="white-space:nowrap">${a.status === 'menunggu'
        ? `<button class="linkbtn" data-setuju="${a.id}">setujui</button> ·
           <button class="linkbtn danger" data-tolak="${a.id}">tolak</button>` : ''}</td>
    </tr>`).join('') || `<tr><td colspan="7" class="muted">Tidak ada pengajuan ${esc(AJU_STATUS[ajuanStatus])}</td></tr>`;

  // Tenant koperasi: menu baru yang disetujui perlu ditetapkan kategorinya
  // sekalian, supaya tidak ada barang yang menunggu tanpa jatah yang benar.
  const { tenants } = tenantResp;
  const koperasiId = new Set(tenants.filter((t) => t.jenis === 'koperasi').map((t) => t.id));

  document.querySelectorAll('#aju-sa-body [data-setuju]').forEach((b) => {
    const a = d.ajuan.find((x) => x.id === Number(b.dataset.setuju));
    const pilihKategori = a.jenis === 'baru' && koperasiId.has(a.tenant_id);
    b.onclick = () => openModal(`
      <h2>Setujui pengajuan?</h2>
      <p><b>${esc(a.tenant_nama)}</b> — ${esc(AJU_JENIS[a.jenis])}<br>${ajuanRingkas(a)}</p>
      <p class="muted">${a.jenis === 'hapus'
        ? 'Menu langsung hilang dari daftar kasir. Riwayat transaksi yang sudah ada tidak terpengaruh.'
        : 'Perubahan langsung berlaku di kasir.'}</p>
      ${pilihKategori ? `
      <div class="field"><label>Kategori barang koperasi</label>
        <select id="aj-kategori">
          <option value="makanan">Makanan (jatah belanja biasa)</option>
          <option value="non_makanan">Non-makanan (jatah harian khusus)</option>
        </select>
        <span class="muted set-hint">Bisa diubah kapan saja di panel Kategori Barang Koperasi.</span></div>` : ''}
      <div class="field"><label>Catatan (opsional)</label><input id="aj-catatan"></div>`,
      async () => {
        await api(`/api/produk/ajuan/${a.id}/setujui`, { method: 'POST', body: {
          catatan: $('#aj-catatan').value,
          ...(pilihKategori ? { kategori: $('#aj-kategori').value } : {}),
        }});
        toast('✔ Pengajuan disetujui', 'ok');
        loadAjuanSA();
        muatKategoriProduk();
      }, 'Setujui');
  });
  document.querySelectorAll('#aju-sa-body [data-tolak]').forEach((b) => {
    const a = d.ajuan.find((x) => x.id === Number(b.dataset.tolak));
    b.onclick = () => openModal(`
      <h2>Tolak pengajuan?</h2>
      <p><b>${esc(a.tenant_nama)}</b> — ${esc(AJU_JENIS[a.jenis])}<br>${ajuanRingkas(a)}</p>
      <div class="field"><label>Alasan penolakan (wajib — dibaca tenant)</label>
        <input id="aj-catatan" placeholder="mis. harga terlalu tinggi untuk santri"></div>`,
      async () => {
        await api(`/api/produk/ajuan/${a.id}/tolak`, { method: 'POST', body: { catatan: $('#aj-catatan').value } });
        toast('Pengajuan ditolak', 'ok');
        loadAjuanSA();
      }, 'Tolak');
  });
}

// ╔══════════════════════════════════════════════════════════╗
// ║ RIWAYAT + EXPORT (kedua role)                             ║
// ╚══════════════════════════════════════════════════════════╝
let riwayatPage = 1;
let riwayatPreselect = null;    // {id, nama} dioper dari tombol "riwayat" di tab Santri/SDM
let riwayatSiswaFilter = null;  // orang yang sedang disaring presisi di layar ini, kalau ada

function riwayatView(el) {
  riwayatPage = 1;
  riwayatSiswaFilter = riwayatPreselect;
  riwayatPreselect = null;
  const isSA = USER.role === 'superadmin';
  el.innerHTML = `<div class="panel">
    <h2>Riwayat Transaksi</h2>
    <div id="rw-siswa-badge"></div>
    <div class="filterbar">
      <div class="field"><label>Dari</label><input type="date" id="f-dari"></div>
      <div class="field"><label>Sampai</label><input type="date" id="f-sampai"></div>
      <div class="field"><label>Tipe</label><select id="f-tipe">
        <option value="">semua</option><option value="beli">beli</option>
        <option value="topup">topup</option><option value="donasi">donasi</option>
        <option value="koreksi">potong saldo</option>
        <option value="topup,koreksi">penyesuaian admin</option>
        <option value="transfer_keluar,transfer_masuk">transfer antar teman</option>
        <option value="transfer_keluar">transfer — kirim</option>
        <option value="transfer_masuk">transfer — terima</option>
        <option value="iuran_keluar,iuran_masuk">iuran ke PJ</option>
        <option value="iuran_keluar">iuran — bayar</option>
        <option value="iuran_masuk">iuran — terima (PJ)</option></select></div>
      ${isSA ? `<div class="field"><label>Tenant</label><select id="f-tenant"><option value="">semua</option></select></div>` : ''}
      <div class="field"><label>Kelas</label><select id="f-kelas"><option value="">semua</option></select></div>
      <div class="field"><label>Cari (nama/NIS/UID)</label><input id="f-cari" placeholder="cari…"></div>
      <button class="go" id="f-go">Terapkan</button>
      <button id="f-reset">Reset</button>
    </div>
    <div class="exports" style="margin-bottom:12px">
      <a href="#" id="ex-xls">⬇ Excel</a><a href="#" id="ex-doc">⬇ Word</a>
      <a href="#" id="ex-csv">⬇ CSV</a><a href="#" id="ex-pdf" target="_blank">🖨 PDF / Cetak</a>
    </div>
    <p class="muted" id="rw-summary"></p>
    <div class="tblwrap"><table>
      <thead><tr><th>Waktu</th><th>Santri</th><th>Kelas</th><th>Tipe</th><th>Tenant</th>
      <th class="num">Jumlah</th><th class="num">Saldo Akhir</th><th>Operator</th><th>Keterangan</th></tr></thead>
      <tbody id="rw-body"></tbody>
    </table></div>
    <div class="pager">
      <span class="muted" id="rw-pageinfo"></span>
      <button id="rw-prev">‹ Sebelumnya</button>
      <button id="rw-next">Berikutnya ›</button>
    </div>
  </div>`;

  api('/api/kelas').then((d) => {
    $('#f-kelas').innerHTML += d.kelas.map((k) => `<option>${esc(k)}</option>`).join('');
  });
  if (isSA) api('/api/tenants').then((d) => {
    $('#f-tenant').innerHTML += d.tenants.map((t) => `<option value="${t.id}">${esc(t.nama)}</option>`).join('');
  });

  $('#f-go').onclick = () => { riwayatPage = 1; loadRiwayat(); };
  $('#f-cari').addEventListener('keydown', (e) => { if (e.key === 'Enter') { riwayatPage = 1; loadRiwayat(); } });
  $('#f-reset').onclick = () => {
    ['f-dari', 'f-sampai', 'f-cari'].forEach((id) => $('#' + id).value = '');
    ['f-tipe', 'f-kelas'].forEach((id) => $('#' + id).value = '');
    if (isSA) $('#f-tenant').value = '';
    riwayatSiswaFilter = null;
    renderRiwayatBadge();
    riwayatPage = 1;
    loadRiwayat();
  };
  $('#rw-prev').onclick = () => { if (riwayatPage > 1) { riwayatPage--; loadRiwayat(); } };
  $('#rw-next').onclick = () => { riwayatPage++; loadRiwayat(); };
  renderRiwayatBadge();
  loadRiwayat();
}

/**
 * Filter presisi 1 orang (dioper dari tombol "riwayat" di tab Santri/SDM)
 * ditampilkan sebagai badge terkunci, BUKAN diisi ke kotak "Cari" bebas —
 * kotak cari cocok sebagian nama/NIS dan bisa mencampur dua orang yang
 * namanya mirip, padahal maksud tombol itu adalah riwayat SATU orang ini saja.
 */
function renderRiwayatBadge() {
  const box = $('#rw-siswa-badge');
  if (!box) return;
  box.innerHTML = riwayatSiswaFilter
    ? `<p class="muted" style="margin:0 0 10px">Menyaring riwayat: <b>${esc(riwayatSiswaFilter.nama)}</b>
        <button class="linkbtn" id="rw-siswa-x" style="margin-left:6px">✕ lihat semua</button></p>`
    : '';
  const x = $('#rw-siswa-x');
  if (x) x.onclick = () => { riwayatSiswaFilter = null; renderRiwayatBadge(); riwayatPage = 1; loadRiwayat(); };
}

function riwayatQs(extra) {
  const p = new URLSearchParams();
  const dari = $('#f-dari').value, sampai = $('#f-sampai').value;
  // hari operasional WIB
  if (dari) p.set('dari', new Date(dari + 'T00:00:00+07:00').toISOString());
  if (sampai) p.set('sampai', new Date(sampai + 'T23:59:59.999+07:00').toISOString());
  if ($('#f-tipe').value) p.set('tipe', $('#f-tipe').value);
  if ($('#f-kelas').value) p.set('kelas', $('#f-kelas').value);
  if ($('#f-cari').value.trim()) p.set('cari', $('#f-cari').value.trim());
  const ft = $('#f-tenant');
  if (ft && ft.value) p.set('tenant_id', ft.value);
  if (riwayatSiswaFilter) p.set('siswa_id', riwayatSiswaFilter.id);
  for (const [k, v] of Object.entries(extra || {})) p.set(k, v);
  return p.toString();
}

async function loadRiwayat() {
  const qs = riwayatQs({ page: riwayatPage, limit: 25 });
  const d = await api('/api/transaksi?' + qs);
  $('#rw-summary').innerHTML =
    `<b>${d.total.toLocaleString('id-ID')}</b> transaksi · total belanja <b>${rp(d.total_belanja)}</b>` +
    (USER.role === 'superadmin' ? ` · total top up <b>${rp(d.total_masuk)}</b>` : '');
  $('#rw-body').innerHTML = d.rows.map((t) => {
    const wkt = new Date(t.created_at).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
    const cls = t.jumlah >= 0 ? 'plus' : 'minus';
    return `<tr>
      <td style="white-space:nowrap">${wkt}</td>
      <td>${esc(t.siswa_nama)}</td><td>${esc(t.kelas)}</td><td>${esc(t.tipe)}</td>
      <td>${esc(t.tenant_nama || '-')}</td>
      <td class="num ${cls}">${t.jumlah >= 0 ? '+' : '−'}${rp(Math.abs(t.jumlah))}</td>
      <td class="num rp">${rp(t.saldo_sesudah)}</td>
      <td>${esc(t.operator)}</td><td class="muted">${esc(t.keterangan)}</td>
    </tr>`;
  }).join('') || '<tr><td colspan="9" class="muted">Tidak ada transaksi cocok filter</td></tr>';
  const maxPage = Math.max(1, Math.ceil(d.total / d.limit));
  if (riwayatPage > maxPage) riwayatPage = maxPage;
  $('#rw-pageinfo').textContent = `hal ${riwayatPage} / ${maxPage}`;
  $('#rw-prev').disabled = riwayatPage <= 1;
  $('#rw-next').disabled = riwayatPage >= maxPage;
  // link export ikut filter aktif
  $('#ex-xls').href = '/api/export?' + riwayatQs({ format: 'xls' });
  $('#ex-doc').href = '/api/export?' + riwayatQs({ format: 'doc' });
  $('#ex-csv').href = '/api/export?' + riwayatQs({ format: 'csv' });
  $('#ex-pdf').href = '/api/export-print?' + riwayatQs();
}

// ╔══════════════════════════════════════════════════════════╗
// ║ TENANT: DASHBOARD                                         ║
// ╚══════════════════════════════════════════════════════════╝
async function dashTenantView(el) {
  const d = await api('/api/dashboard-tenant');
  const st = await api('/api/settlements');
  const maxBar = Math.max(...d.harian.map((h) => h.total), 1);
  el.innerHTML = `
    <div class="cards">
      <div class="statcard"><span class="muted">Penjualan hari ini</span><br><b class="rp">${rp(d.hari_ini)}</b></div>
      <div class="statcard"><span class="muted">7 hari terakhir</span><br><b class="rp">${rp(d.minggu_ini)}</b></div>
      <div class="statcard"><span class="muted">Total penjualan</span><br><b class="rp">${rp(d.total)}</b></div>
      <div class="statcard"><span class="muted">Sudah disetor admin</span><br><b class="rp">${rp(d.disetor)}</b></div>
      <div class="statcard" style="border-color:var(--brand)"><span class="muted">Tagihan berjalan</span><br><b class="rp" style="color:var(--brand)">${rp(d.outstanding)}</b></div>
    </div>
    <div class="panel">
      <h2>Penjualan 14 hari terakhir</h2>
      <div class="bars" style="margin-bottom:26px">${d.harian.map((h) =>
        `<div class="bar" style="height:${Math.round(h.total / maxBar * 100)}%" title="${esc(h.tgl)}: ${rp(h.total)}">
         <span>${h.tgl.slice(8)}</span></div>`).join('')}</div>
    </div>
    <div class="panel">
      <h2>Riwayat Setoran dari Admin</h2>
      <div class="tblwrap"><table>
        <thead><tr><th>Tanggal</th><th class="num">Jumlah</th><th>Metode</th><th>Bukti</th><th>Keterangan</th></tr></thead>
        <tbody>${st.settlements.map((s) => `
          <tr><td>${new Date(s.created_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })}</td>
          <td class="num rp">${rp(s.jumlah)}</td><td>${esc(s.metode)}</td>
          <td>${s.bukti ? `<button class="linkbtn" data-bukti="${esc(s.bukti)}">lihat</button>` : '<span class="muted">—</span>'}</td>
          <td class="muted">${esc(s.keterangan)}</td></tr>`).join('') ||
          '<tr><td colspan="5" class="muted">Belum ada setoran</td></tr>'}</tbody>
      </table></div>
    </div>`;
  bindBuktiButtons(el);
}

function bindBuktiButtons(el) {
  el.querySelectorAll('[data-bukti]').forEach((b) => {
    b.onclick = () => openModal(
      `<h2>Bukti Setoran</h2><img src="/uploads/${esc(b.dataset.bukti)}" style="max-width:100%; border-radius:8px">`);
  });
}

// ╔══════════════════════════════════════════════════════════╗
// ║ SUPERADMIN: DASHBOARD + SETORAN                           ║
// ╚══════════════════════════════════════════════════════════╝
/**
 * Lencana "ada yang menunggu" pada satu tab.
 *
 * Tombolnya sering duduk di dalam submenu, jadi lencananya tidak terlihat
 * sampai grupnya disorot — padahal seluruh gunanya justru supaya pekerjaan
 * tidak menganggur tanpa ada yang melihat. Induk grupnya ikut diberi titik
 * penanda.
 */
function setLencana(view, label, n) {
  const b = document.querySelector(`#nav button[data-v="${view}"]`);
  if (!b) return;
  // Ikon tab ikut digambar ulang — isi tombol diganti utuh di sini.
  const ikon = IKON_TAB[view] ? `<svg class="ik" viewBox="0 0 24 24" aria-hidden="true">${IKON_TAB[view]}</svg>` : '';
  b.innerHTML = ikon + esc(label) + (n > 0 ? ` <span class="navbadge">${n}</span>` : '');
  const grup = b.closest('.nav-grup');
  if (grup) {
    // Satu grup bisa memuat beberapa tab berlencana; titiknya menyala selama
    // MASIH ADA yang menunggu di dalamnya, bukan mengikuti tab terakhir saja.
    const adaLagi = [...grup.querySelectorAll('button[data-v] .navbadge')].length > 0;
    grup.classList.toggle('ada-lencana', adaLagi);
  }
}
const setLencanaAjuan = (n) => setLencana('menu-ajuan', 'Menu Tenant', n);
const setLencanaTopup = (n) => setLencana('topup', 'Topup Ortu', n);

/* ══════════════════════════════════════════════════════════════════
   PEMBERITAHUAN: ada pengajuan menunggu
   ══════════════════════════════════════════════════════════════════

   Wali sudah benar-benar mentransfer uang lalu menunggu. Sebelum ini admin
   baru tahu ada antrean SESUDAH membuka layar Topup Ortu — kalau ia sedang di
   layar lain, tidak ada apa pun yang memberi tahu.

   Pengecekannya sengaja murah: jawabannya dua angka, di bawah 40 byte, jauh
   lebih ringan daripada sekali memuat halaman. Dan ia BERHENTI saat tabnya
   tidak dilihat — admin yang meninggalkan layar ini semalaman tidak boleh
   menghabiskan kuota untuk menanyakan sesuatu yang tidak sedang ia lihat.
*/
const NOTIF_JEDA_MS = 30000;
let notifTimer = null;
let notifTerakhir = null;      // null = belum pernah; jangan bunyikan bacaan pertama

async function periksaNotif() {
  if (USER?.role !== 'superadmin' || document.hidden) return;
  try {
    const d = await api('/api/notif');
    setLencanaTopup(d.topup);
    setLencanaAjuan(d.menu);

    /*
     * Bunyi hanya saat angkanya NAIK, bukan saat masih ada sisa antrean.
     * Kalau tidak, admin yang menunda satu pengajuan akan dibunyikan terus
     * setiap setengah menit sampai ia menyerah dan mematikan suaranya —
     * dan ikut kehilangan pemberitahuan yang sebenarnya berguna.
     */
    if (notifTerakhir !== null) {
      const naikTopup = d.topup > notifTerakhir.topup;
      const naikMenu = d.menu > notifTerakhir.menu;
      if (naikTopup || naikMenu) {
        bunyiNotif();
        const baruTopup = d.topup - notifTerakhir.topup;
        const baruMenu = d.menu - notifTerakhir.menu;
        // Modal notif bisa menimpa modal LAIN yang sedang diisi admin (form
        // belum tersimpan) — jatuhkan ke toast kalau ada modal lain terbuka.
        const modalLain = $('#modal-root').innerHTML.trim() !== '';
        if (notifGaya() === 'modal' && !modalLain) {
          tampilkanNotifModal({ topup: d.topup, menu: d.menu, baruTopup, baruMenu });
        } else {
          const bagian = [];
          if (naikTopup) bagian.push(`${baruTopup} pengajuan top up`);
          if (naikMenu) bagian.push(`${baruMenu} usulan menu`);
          toast('🔔 Baru masuk: ' + bagian.join(' & '), 'ok');
        }
      }
    }
    notifTerakhir = { topup: d.topup, menu: d.menu };
  } catch {
    // Sesi habis atau jaringan putus bukan hal yang perlu diteriakkan tiap
    // 30 detik. Dibiarkan diam; percobaan berikutnya akan berhasil sendiri,
    // dan kalau sesinya benar-benar habis, tindakan admin berikutnya yang
    // akan mengatakannya.
  }
}

/*
 * Pendengar visibilitychange dipasang SEKALI seumur halaman, bukan di dalam
 * mulaiNotif(). Keluar lalu masuk lagi tanpa memuat ulang halaman memanggil
 * mulaiNotif() sekali lagi, dan pendengar yang dipasang di sana akan menumpuk
 * — tiap kali masuk menambah satu, sampai satu kali berpindah tab memicu
 * belasan permintaan sekaligus.
 */
document.addEventListener('visibilitychange', () => {
  if (!document.hidden && notifTimer) periksaNotif();
});

function mulaiNotif() {
  if (USER?.role !== 'superadmin') return;
  clearInterval(notifTimer);
  notifTimer = setInterval(periksaNotif, NOTIF_JEDA_MS);
  periksaNotif();
}

/* ---- bunyi, gaya & nada — Web Audio, bukan berkas. Tiga preferensi ini
   PER PERANGKAT (localStorage), bukan server: HP & desktop bisa beda pola. */
const NOTIF_SUARA_KUNCI = 'kantin-notif-suara';
const NOTIF_GAYA_KUNCI = 'kantin-notif-gaya';
const NOTIF_NADA_KUNCI = 'kantin-notif-nada';
const suaraNyala = () => localStorage.getItem(NOTIF_SUARA_KUNCI) !== 'mati';
const notifGaya = () => (localStorage.getItem(NOTIF_GAYA_KUNCI) === 'modal' ? 'modal' : 'toast');
const notifNada = () => {
  const v = localStorage.getItem(NOTIF_NADA_KUNCI);
  return ['lembut', 'standar', 'tegas'].includes(v) ? v : 'standar';
};

// [hz, mulai detik, puncak volume, panjang detik]. "standar" = bunyi lama,
// dipertahankan persis supaya tidak berubah tanpa dipilih.
const POLA_NADA = {
  lembut: [[660, 0, 0.11, 0.22]],
  standar: [[880, 0, 0.18, 0.12], [1175, 0.12, 0.18, 0.12]],
  tegas: [[740, 0, 0.22, 0.09], [988, 0.09, 0.22, 0.09], [1318, 0.18, 0.22, 0.1]],
};

/** Memutar nada tanpa melihat sakelar nyala/mati — dipakai tombol "Coba". */
function putarNada(nada) {
  try {
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return;
    const ac = new AC();
    (POLA_NADA[nada] || POLA_NADA.standar).forEach(([hz, mulai, puncak, panjang]) => {
      const osc = ac.createOscillator();
      const vol = ac.createGain();
      osc.type = 'sine';
      osc.frequency.value = hz;
      // Peredupan halus: nada yang dipotong mendadak berbunyi seperti "klik".
      vol.gain.setValueAtTime(0.0001, ac.currentTime + mulai);
      vol.gain.exponentialRampToValueAtTime(puncak, ac.currentTime + mulai + 0.02);
      vol.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + mulai + panjang);
      osc.connect(vol).connect(ac.destination);
      osc.start(ac.currentTime + mulai);
      osc.stop(ac.currentTime + mulai + panjang);
    });
    setTimeout(() => ac.close().catch(() => {}), 600);
  } catch {
    // Peramban yang menolak memutar bunyi sebelum ada interaksi pengguna
    // bukan alasan untuk menggagalkan apa pun — lencananya tetap muncul.
  }
}

function bunyiNotif() {
  if (suaraNyala()) putarNada(notifNada());
}

function gambarTombolSuara() {
  const el = $('#hd-suara');
  if (!el) return;
  const on = suaraNyala();
  el.textContent = on ? '🔔' : '🔕';
  el.title = 'Pengaturan notifikasi — bunyi ' + (on ? 'menyala' : 'mati');
  el.setAttribute('aria-label', el.title);
  el.classList.toggle('mati', !on);
}

/** Panel preferensi notifikasi admin: gaya tampilan, bunyi, nada — per perangkat. */
function bukaPengaturanNotif() {
  let gaya = notifGaya();
  let suara = suaraNyala();
  let nada = notifNada();

  const gambar = () => {
    $('#notif-g-toast').classList.toggle('sel', gaya === 'toast');
    $('#notif-g-modal').classList.toggle('sel', gaya === 'modal');
    $('#notif-s-nyala').classList.toggle('sel', suara);
    $('#notif-s-mati').classList.toggle('sel', !suara);
    ['lembut', 'standar', 'tegas'].forEach((n) => $('#notif-n-' + n).classList.toggle('sel', nada === n));
    $('#notif-nada-wrap').classList.toggle('hidden', !suara);
  };

  openModal(`
    <h2>Pengaturan Notifikasi</h2>
    <p class="muted">Pengajuan top up wali &amp; usulan menu tenant. Berlaku di perangkat ini saja.</p>
    <div class="field"><label>Saat pengajuan baru masuk</label>
      <div class="segswitch">
        <button type="button" id="notif-g-toast">Toast sekilas</button>
        <button type="button" id="notif-g-modal">Jendela popup</button>
      </div>
    </div>
    <div class="field"><label>Bunyi</label>
      <div class="segswitch">
        <button type="button" id="notif-s-nyala">🔔 Nyala</button>
        <button type="button" id="notif-s-mati">🔕 Mati</button>
      </div>
    </div>
    <div class="field" id="notif-nada-wrap"><label>Nada</label>
      <div class="segswitch">
        <button type="button" id="notif-n-lembut">Lembut</button>
        <button type="button" id="notif-n-standar">Standar</button>
        <button type="button" id="notif-n-tegas">Tegas</button>
      </div>
      <button type="button" class="linkbtn" id="notif-coba" style="margin-top:8px">🔊 Coba nada ini</button>
    </div>`,
    async () => {
      localStorage.setItem(NOTIF_GAYA_KUNCI, gaya);
      localStorage.setItem(NOTIF_SUARA_KUNCI, suara ? 'nyala' : 'mati');
      localStorage.setItem(NOTIF_NADA_KUNCI, nada);
      gambarTombolSuara();
    }, 'Simpan');

  $('#notif-g-toast').onclick = () => { gaya = 'toast'; gambar(); };
  $('#notif-g-modal').onclick = () => { gaya = 'modal'; gambar(); };
  $('#notif-s-nyala').onclick = () => { suara = true; gambar(); };
  $('#notif-s-mati').onclick = () => { suara = false; gambar(); };
  ['lembut', 'standar', 'tegas'].forEach((n) => {
    $('#notif-n-' + n).onclick = () => { nada = n; gambar(); };
  });
  $('#notif-coba').onclick = () => putarNada(nada);
  gambar();
}

/** Jendela popup saat ada pengajuan baru (gaya "modal") — baris hanya tampil
 *  untuk kategori yang NAIK. */
function tampilkanNotifModal({ topup, menu, baruTopup, baruMenu }) {
  const baris = (tipe, judul, total, baru, warna, svg) => baru > 0 ? `
    <div style="display:flex; align-items:center; gap:12px; padding:10px 0; border-top:1px solid var(--line)">
      <div style="width:34px; height:34px; border-radius:10px; flex:none; display:flex; align-items:center;
        justify-content:center; background:${warna === 'ok' ? '#e3f4ea' : '#fff6e0'};
        color:${warna === 'ok' ? 'var(--ok)' : '#92650a'}">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${svg}</svg>
      </div>
      <div style="flex:1; display:flex; flex-direction:column">
        <b style="font-size:13.5px">${esc(judul)}</b>
        <span class="muted">${total} menunggu diperiksa · ${baru} baru</span>
      </div>
      <button type="button" class="linkbtn" onclick="location.hash='${tipe}'; closeModal();">Lihat</button>
    </div>` : '';

  openModal(`
    <div style="text-align:center; margin-bottom:6px">
      <div style="width:52px; height:52px; border-radius:14px; background:#fff6e0; margin:0 auto 10px;
        display:flex; align-items:center; justify-content:center; font-size:24px">🔔</div>
      <h2 style="margin-bottom:2px">Ada pengajuan baru</h2>
      <p class="muted">Menunggu diperiksa sejak terakhir Anda buka</p>
    </div>
    ${baris('topup', 'Pengajuan top up', topup, baruTopup, 'ok',
      '<path d="M12 19V5M5 12l7-7 7 7"/>')}
    ${baris('menu-ajuan', 'Usulan menu baru', menu, baruMenu, 'warn',
      '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M8 15h4"/>')}`);
}

async function dashSAView(el) {
  /*
   * Ketiga permintaan ini DIJALANKAN BERSAMAAN, bukan berurutan.
   *
   * Sebelumnya masing-masing ditunggu satu per satu. Servernya cepat (35–75 ms),
   * jadi di kantor perbedaannya tak terasa — tapi yang menentukan di sambungan
   * buruk bukan lamanya server berpikir, melainkan berapa kali harus bolak-balik.
   * Pada latensi 400 ms, tiga permintaan berurutan berarti menunggu lebih dari
   * sedetik sebelum layar mulai tergambar; bersamaan, cukup sepertiganya.
   *
   * Tidak satu pun dari ketiganya memerlukan hasil yang lain, jadi tidak ada
   * yang dikorbankan. Neraca dibiarkan boleh gagal sendiri — panelnya opsional.
   */
  const [d, cfg, nr] = await Promise.all([
    api('/api/dashboard'),
    api('/api/settings'),
    api('/api/neraca').catch(() => null),
  ]);
  setLencanaAjuan(d.menu_menunggu);
  el.innerHTML = `
    ${nr ? neracaPanel(nr) : ''}
    <div class="cards">
      <div class="statcard"><span class="muted">Belanja hari ini</span><br><b class="rp">${rp(d.belanja_hari_ini)}</b></div>
      <div class="statcard"><span class="muted">Top up hari ini</span><br><b class="rp">${rp(d.topup_hari_ini)}</b></div>
      <div class="statcard"><span class="muted">Total saldo santri (kewajiban)</span><br><b class="rp">${rp(d.total_saldo_siswa)}</b></div>
      <div class="statcard"><span class="muted">Jumlah santri</span><br><b>${d.jumlah_siswa.toLocaleString('id-ID')}</b></div>
    </div>
    <div class="panel">
      <h2>⚙️ Pengaturan Limit</h2>
      <p class="muted">Berlaku untuk seluruh sistem. Ditegakkan di server — kasir/top up otomatis menolak yang melewati batas.</p>
      ${gridPengaturan(cfg.labels, cfg.settings)}
      <button class="savebtn" data-simpan="limit">Simpan Pengaturan</button>
    </div>
    <div class="panel">
      <h2>💰 Saldo Titipan & Smart Top Up</h2>
      <p class="muted">Kelebihan setoran orang tua yang tidak tertampung kartu disimpan sebagai
        <b>saldo titipan</b> — tidak bisa dibelanjakan, dan otomatis mengisi kartu begitu saldo
        santri turun sampai ambang. Setelan per santri ada di menu <b>Saldo Santri</b>.</p>
      ${gridPengaturan(cfg.labels_titipan, cfg.settings)}
      <button class="savebtn" data-simpan="titipan">Simpan Pengaturan Titipan</button>
    </div>
    <div class="panel">
      <h2>🧴 Batas Belanja Non-Makanan (Koperasi)</h2>
      <p class="muted">Barang koperasi yang bukan makanan (sabun, sikat gigi, handuk) memakai jatah
        harian <b>tersendiri</b> — tidak memotong jatah belanja makanan, dan sebaliknya. Kategori
        tiap barang ditetapkan di menu <b>Menu Tenant</b>, dan hanya tenant berjenis koperasi yang
        boleh punya barang non-makanan.</p>
      ${gridPengaturan(cfg.labels_nonmakanan, cfg.settings)}
      <button class="savebtn" data-simpan="nonmakanan">Simpan Batas Non-Makanan</button>
    </div>
    <div class="panel">
      <h2>🤝 Transfer Saldo Antar Teman</h2>
      <p class="muted">Santri mengirim sebagian saldonya lewat layar <b>Kios</b> dengan menyebut
        <b>NIS</b> temannya — tanpa uang tunai berpindah tangan. Batas di bawah ini sengaja jauh
        lebih ketat daripada batas belanja: fitur ini memindahkan uang tanpa ada barang yang
        diterima, jadi ia juga jalur yang paling mudah dipakai memeras uang jajan. Batas
        <b>terima</b> menahan satu kartu dipakai menampung setoran dari banyak anak.</p>
      ${gridPengaturan(cfg.labels_transfer || {}, cfg.settings)}
      <button class="savebtn" data-simpan="transfer">Simpan Pengaturan Transfer</button>
    </div>
    <div class="panel">
      <h2>🧾 Iuran ke PJ (SDM)</h2>
      <p class="muted">Santri membayar iuran kelompok/kelas lewat layar <b>Kios</b> — memilih PJ dari
        daftar (bukan mengetik NIY), lalu wajib mengisi <b>deskripsi</b> untuk apa iuran itu. Siapa
        yang boleh jadi PJ penerima ditandai per orang di menu <b>SDM &amp; Personil</b> ("Boleh
        terima iuran"), bukan di sini. Batasnya sengaja terpisah dari batas transfer pertemanan di
        atas — PJ yang jelas identitasnya wajar menerima nominal lebih besar.</p>
      ${gridPengaturan(cfg.labels_iuran || {}, cfg.settings)}
      <button class="savebtn" data-simpan="iuran">Simpan Pengaturan Iuran</button>
    </div>
    <div class="panel">
      <h2>👪 Kunci Masuk Portal SIMCASHTREN</h2>
      <p class="muted">Wali santri dan pegawai masuk ke <b>/ortu</b>: wali dengan <b>NIS + tanggal lahir</b>
        anak, pegawai dengan <b>NIY + tanggal lahir</b> sendiri.
        Kunci ketiga berupa 4 digit terakhir nomor HP bisa dimatikan di sini (berlaku untuk keduanya).</p>
      <p class="muted"><b>Pertimbangkan sebelum mematikannya:</b> NIS dan tanggal lahir
        sama-sama diketahui teman sekelas, jadi tanpa kunci ketiga portal ini praktis terbuka
        bagi siapa pun yang mengenal anaknya. Halamannya memang baca-saja, tetapi saldo dan pola
        jajan tetap data pribadi. Yang menahan tebak-tebakan dari luar adalah rem percobaan gagal
        (5 kali salah → terkunci 15 menit), bukan kerahasiaan nomornya.</p>
      ${gridPengaturan(cfg.labels_ortu || {}, cfg.settings)}
      <button class="savebtn" data-simpan="ortu">Simpan Kunci Portal</button>
    </div>`;

  // Semua panel memakai satu mekanisme yang sama; kelompoknya hanya menentukan
  // kunci mana yang dikirim saat tombol simpannya ditekan.
  const GRUP = {
    limit: cfg.labels, titipan: cfg.labels_titipan, nonmakanan: cfg.labels_nonmakanan,
    transfer: cfg.labels_transfer || {}, iuran: cfg.labels_iuran || {}, ortu: cfg.labels_ortu || {},
  };
  // Jenjang Kelas tidak lagi di sini: ia tinggal di panel Kenaikan Kelas
  // (Pemegang Kartu → Santri), satu-satunya tempat angka itu dipakai.
  const semua = { ...cfg.labels, ...cfg.labels_titipan, ...cfg.labels_nonmakanan,
    ...GRUP.transfer, ...GRUP.iuran, ...GRUP.ortu };
  bindPengaturan(semua);
  el.querySelectorAll('[data-simpan]').forEach((btn) => {
    btn.onclick = async () => {
      const labels = GRUP[btn.dataset.simpan];
      const body = {};
      // Isian rupiah bertitik ribuan, isian lain (kali/onoff) tetap angka polos.
      for (const [k, m] of Object.entries(labels))
        body[k] = m.unit === 'rp' ? nilaiUang('#set-' + k) : Number($('#set-' + k).value);
      btn.disabled = true;
      try {
        await api('/api/settings', { method: 'PUT', body });
        toast('✔ Pengaturan tersimpan dan langsung berlaku', 'ok');
      } catch (e) { toast(e.message, 'err'); }
      btn.disabled = false;
    };
  });
  if (nr) bindNeraca(nr);
}

// ---------------------------------------------------- neraca kas (superadmin)
// Panel paling atas dashboard: membuktikan tiap rupiah yang masuk masih bisa
// ditunjukkan tempatnya. Dua kolom sengaja dibaca berpasangan — kiri uang yang
// diterima & dikeluarkan sekolah, kanan uang yang bukan milik sekolah — dan
// barisnya bertemu di satu angka selisih yang harus nol.

/** Satu baris angka di kolom neraca. */
function nrBaris(label, nilai, opsi = {}) {
  const { tanda = '', tebal = false, catatan = '', warna = '' } = opsi;
  return `<div class="nr-baris${tebal ? ' nr-total' : ''}">
    <span>${label}${catatan ? `<br><span class="nr-catatan">${catatan}</span>` : ''}</span>
    <b class="rp ${warna}">${tanda}${rp(Math.abs(nilai))}</b>
  </div>`;
}

function neracaPanel(n) {
  const seimbang = n.selisih === 0;
  const m = n.masuk;
  const k = n.kewajiban;
  const belumDitetapkan = n.awal.pada === 0 && n.awal.terukur !== 0;

  return `
  <div class="panel nr-panel ${seimbang ? 'nr-ok' : 'nr-alarm'}">
    <div class="kat-head">
      <h2 style="margin:0">🧮 Neraca Kas — Uang Masuk vs Kewajiban</h2>
      <button class="linkbtn" id="nr-muat">muat ulang</button>
    </div>
    <p class="muted">Setiap rupiah yang masuk hanya bisa berada di empat tempat: saldo kartu santri,
      saldo titipan, hutang ke tenant, atau donasi terkumpul — sisanya sudah keluar sebagai setoran
      tenant, penarikan, atau potongan. Karena itu kedua kolom di bawah <b>wajib</b> bertemu di angka
      yang sama. Semua angka dihitung ulang dari buku besar setiap panel ini dibuka.</p>

    <div class="nr-grid">
      <div class="nr-kolom">
        <h3>Uang yang diterima &amp; dikeluarkan sekolah</h3>
        ${nrBaris('Uang masuk terverifikasi', m.total, { tanda: '+', warna: 'plus',
          catatan: `top up ${rp(m.topup)} + setoran titipan ${rp(m.titipan)}` +
            (m.biaya_admin > 0 ? ` + biaya admin ${rp(m.biaya_admin)}` : '') +
            (m.internal_isi_ulang > 0
              ? `<br>isi ulang otomatis dari titipan ${rp(m.internal_isi_ulang)} tidak dihitung sebagai uang masuk`
              : '') })}
        ${nrBaris('Sudah disetor ke tenant', n.keluar.tenant, { tanda: '−', warna: 'minus' })}
        ${n.keluar.dana_admin > 0
          ? nrBaris('Ditarik dari Dana Admin', n.keluar.dana_admin, { tanda: '−', warna: 'minus',
              catatan: 'uang milik sekolah yang sudah benar-benar diambil' })
          : ''}
        ${nrBaris('Penarikan titipan (dikembalikan ke wali)', n.keluar.titipan, { tanda: '−', warna: 'minus' })}
        ${nrBaris('Potongan saldo oleh admin', n.keluar.koreksi, { tanda: '−', warna: 'minus',
          catatan: 'penarikan tunai, koreksi salah input, pembayaran di luar kantin' })}
        ${nrBaris('Kas yang seharusnya dipegang sekolah', n.kas, { tebal: true })}
        ${n.awal.diakui !== 0
          ? nrBaris('Saldo awal yang diakui', n.awal.diakui, { tanda: '+', tebal: true,
              catatan: 'saldo yang sudah ada sebelum sistem mencatat mutasi' })
          : ''}
      </div>
      <div class="nr-kolom">
        <h3>Kewajiban — uang yang bukan milik sekolah</h3>
        ${nrBaris('Saldo di kartu santri', k.saldo_kartu)}
        ${nrBaris('Saldo cadangan santri (titipan)', k.saldo_titipan,
          { catatan: 'sudah diterima, belum masuk kartu karena batas saldo' })}
        ${nrBaris('Hutang belum disetor ke tenant', k.hutang_tenant,
          { catatan: `penjualan tenant ${rp(n.penjualan_tenant)} − sudah disetor ${rp(n.keluar.tenant)}` })}
        ${nrBaris('Donasi terkumpul', k.donasi, { catatan: 'belum ada fitur penyaluran' })}
        ${nrBaris('Total kewajiban', k.total, { tebal: true })}
        ${n.dana_admin.saldo !== 0 || n.dana_admin.masuk !== 0
          ? nrBaris('Dana Admin (milik sekolah)', n.dana_admin.saldo, { tebal: true,
              catatan: `terkumpul ${rp(n.dana_admin.masuk)} − ditarik ${rp(n.dana_admin.tarik)}` +
                '<br>bukan kewajiban, tapi tetap klaim atas kas yang dipegang — ' +
                'kas harus sama dengan kewajiban ditambah dana ini' })
          : ''}
      </div>
    </div>

    <div class="nr-hasil ${seimbang ? 'ok' : 'alarm'}">
      <div>
        <span class="nr-hasil-label">${seimbang ? '✔ Neraca seimbang' : '⚠️ Neraca TIDAK seimbang'}</span>
        <b class="rp">${n.selisih === 0 ? 'Rp0' : (n.selisih > 0 ? '+' : '−') + rp(Math.abs(n.selisih))}</b>
        <span class="nr-catatan">${seimbang
          ? 'Seluruh uang yang masuk bisa ditunjukkan tempatnya.'
          : n.selisih < 0
            ? 'Kewajiban lebih besar dari uang yang pernah masuk — ada saldo tanpa uang di belakangnya.'
            : 'Uang masuk lebih besar dari kewajiban yang tercatat.'}</span>
      </div>
      ${n.cukup !== null ? `<div class="nr-rasio">
        <span class="muted">Kecukupan kas</span><b>${n.cukup}%</b>
        <span class="nr-catatan">kas + saldo awal terhadap total kewajiban</span></div>` : ''}
    </div>

    ${belumDitetapkan ? `
      <div class="nr-saran">
        <p><b>Sistem ini dipasang di atas data yang sudah ada.</b> Ada <b class="rp">${rp(n.awal.terukur)}</b>
          saldo santri yang tidak punya catatan mutasi — hampir pasti data dummy/seed atau saldo
          bawaan dari sistem sebelumnya. Selama ini belum diakui sebagai <i>saldo awal</i>, neraca
          akan selalu tampak tidak seimbang sebesar angka itu dan penyimpangan baru jadi sulit terlihat.</p>
        <button class="go" id="nr-tetapkan">Tetapkan ${rp(n.awal.terukur)} sebagai saldo awal</button>
      </div>` : ''}

    <div class="nr-info">
      ${nrBaris('Top up tertunda (belum jadi uang)', n.tertunda.rp,
        { catatan: `${n.tertunda.n} pengajuan orang tua menunggu diperiksa — tidak masuk neraca sampai disetujui` })}
      ${(m.per_metode.tunai + m.per_metode.transfer + m.per_metode.qris) > 0 ? `
        <div class="nr-baris"><span>Rincian metode uang masuk yang tercatat
          <br><span class="nr-catatan">hanya dari impor massal &amp; portal orang tua; top up manual tidak menyimpan metode</span></span>
          <b>${['tunai', 'transfer', 'qris'].map((x) =>
            `<span class="nr-metode">${x}: ${rp(m.per_metode[x])}</span>`).join('')}</b></div>` : ''}
    </div>

    <h3 style="margin:16px 0 6px">Pemeriksaan keutuhan data</h3>
    <div class="nr-periksa">
      ${n.periksa.map((p) => `
        <div class="nr-cek ${p.ok ? 'ok' : 'gagal'}">
          <span class="nr-cek-ikon">${p.ok ? '✔' : '⚠️'}</span>
          <div><b>${esc(p.label)}</b>
            ${p.ok ? '' : `<br><span class="nr-catatan">${esc(p.catatan ||
              (p.jumlah_siswa !== undefined ? `${p.jumlah_siswa} santri, selisih ${rp(p.nilai)}`
                : `nilai: ${typeof p.nilai === 'number' && Math.abs(p.nilai) > 1000 ? rp(p.nilai) : p.nilai}`))}</span>`}
          </div>
        </div>`).join('')}
    </div>
  </div>`;
}

function bindNeraca(n) {
  const muat = $('#nr-muat');
  if (muat) muat.onclick = () => renderView('dashboard');
  const tetap = $('#nr-tetapkan');
  if (tetap) tetap.onclick = () => openModal(`
    <h2>Tetapkan saldo awal?</h2>
    <p>Saldo santri sebesar <b class="rp">${rp(n.awal.terukur)}</b> yang tidak punya catatan mutasi
      akan diakui sebagai <b>saldo awal</b> sistem.</p>
    <p class="muted">Gunanya: neraca kembali seimbang, sehingga selisih yang muncul <b>setelah ini</b>
      benar-benar berarti ada yang salah. Angkanya dihitung server dari buku besar — tidak bisa
      diketik. Lakukan <b>sekali</b> saat sistem mulai dipakai; kalau data dummy nanti dihapus,
      tetapkan ulang.</p>`,
    async () => {
      const d = await api('/api/neraca/saldo-awal', { method: 'POST', body: { konfirmasi: true } });
      toast(`✔ Saldo awal ditetapkan: ${rp(d.awal_saldo + d.awal_titipan)}`, 'ok');
      renderView('dashboard');
    }, 'Ya, tetapkan saldo awal');
}

/**
 * Kisi input pengaturan. Kunci bersatuan 'onoff' digambar sebagai pilihan
 * nyala/mati — kalau dibiarkan sebagai kotak angka, admin akan mengetik 2 atau 10
 * lalu bingung mengapa ditolak server.
 */
function gridPengaturan(labels, settings) {
  return `<div class="settings-grid">${Object.entries(labels).map(([k, m]) => `
    <div class="field"><label>${esc(m.label)}</label>
    ${m.unit === 'onoff'
      ? `<select id="set-${k}">
           <option value="1" ${settings[k] === 1 ? 'selected' : ''}>nyala</option>
           <option value="0" ${settings[k] === 0 ? 'selected' : ''}>mati</option>
         </select>`
      : m.unit === 'rp'
        ? inputUang({ id: `set-${k}`, nilai: settings[k] })
        : `<input type="number" min="1" id="set-${k}" value="${settings[k]}">`}
    <span class="muted set-hint" id="hint-${k}"></span></div>`).join('')}
  </div>`;
}

/** Petunjuk satuan di bawah tiap input (labelnya sengaja dibuat ringkas). */
function bindPengaturan(labels) {
  const gambar = () => {
    for (const [k, m] of Object.entries(labels)) {
      const inp = $('#set-' + k);
      const hint = $('#hint-' + k);
      if (!inp || !hint) continue;
      const v = m.unit === 'rp' ? (nilaiUang(inp) ?? 0) : Number(inp.value);
      hint.textContent = m.unit === 'onoff' ? (v === 1 ? 'berlaku' : 'tidak berlaku')
        : v > 0 ? (m.unit === 'tingkat' ? `kelas ${v}`
          : m.unit === 'kali' ? `${v.toLocaleString('id-ID')} kali / hari` : rp(v))
        : '—';
    }
  };
  for (const k of Object.keys(labels)) {
    const inp = $('#set-' + k);
    if (inp) inp.addEventListener(inp.tagName === 'SELECT' ? 'change' : 'input', gambar);
  }
  gambar();
}

function setorModal(tenantId, nama, outstanding) {
  openModal(`
    <h2>Setor ke ${esc(nama)}</h2>
    <p class="muted">Tagihan berjalan: <b class="rp">${rp(outstanding)}</b>.
    Pembayaran dilakukan di luar sistem (cash / transfer bank), di sini hanya dicatat + bukti.</p>
    <div class="field"><label>Jumlah setoran (Rp)</label>
      ${inputUang({ id: 'm-jumlah', nilai: outstanding })}</div>
    <div class="field"><label>Metode</label>
      <select id="m-metode"><option value="cash">Cash</option><option value="transfer">Transfer bank</option></select></div>
    <div class="field"><label>Keterangan</label><input id="m-ket" placeholder="mis. setoran minggu ke-2 Agustus"></div>
    <div class="field"><label>Bukti (opsional, bisa diupload nanti)</label>
      <input type="file" id="m-file" accept="image/*"></div>`,
    async () => {
      const body = {
        tenant_id: tenantId,
        jumlah: nilaiUang('#m-jumlah'),
        metode: $('#m-metode').value,
        keterangan: $('#m-ket').value,
      };
      const f = $('#m-file').files[0];
      if (f) body.bukti_data = (await kompresGambar(f)).dataUrl;
      await api('/api/settlements', { method: 'POST', body });
      toast('✔ Setoran tercatat', 'ok');
      // Panelnya kini tinggal di layar Tenant; hanya bagian uangnya yang
      // digambar ulang supaya tabel pengelolaan tenant tidak berkedip.
      muatUangTenant();
    }, 'Catat Setoran');
}

// ╔══════════════════════════════════════════════════════════╗
// ║ ANALITIK — tren & pola transaksi (kedua role)             ║
// ╚══════════════════════════════════════════════════════════╝
const PRESET_RENTANG = [
  ['juli', 'Juli 2026', '2026-07-01', '2026-07-31'],
  ['30h', '30 hari terakhir', null, null],
  ['7h', '7 hari terakhir', null, null],
  ['semua', 'Semua data', '', ''],
];

function tglMinus(n) {
  return new Date(Date.now() + 7 * 3600 * 1000 - n * 86400000).toISOString().slice(0, 10);
}

function analitikView(el) {
  const isSA = USER.role === 'superadmin';
  el.innerHTML = `
    <div class="panel">
      <h2>📈 Analitik Transaksi</h2>
      <p class="muted">Tren nilai, frekuensi, dan jam ramai${isSA ? ', serta kontribusi tiap tenant' : ''}. Semua grafik mengikuti filter di bawah.</p>
      <div class="filterbar">
        <div class="field"><label>Rentang cepat</label><select id="a-preset">
          ${PRESET_RENTANG.map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}
        </select></div>
        <div class="field"><label>Dari</label><input type="date" id="a-dari"></div>
        <div class="field"><label>Sampai</label><input type="date" id="a-sampai"></div>
        <div class="field"><label>Granularitas</label><select id="a-gran">
          <option value="harian">Harian</option><option value="mingguan">Mingguan</option>
          <option value="bulanan">Bulanan</option></select></div>
        ${isSA ? `<div class="field"><label>Tenant</label><select id="a-tenant"><option value="">Semua tenant</option></select></div>` : ''}
        <div class="field"><label>Kelas</label><select id="a-kelas"><option value="">Semua kelas</option></select></div>
        <button class="go" id="a-go">Terapkan</button>
      </div>
      <div id="a-note"></div>
    </div>
    <div id="a-isi"><p class="muted">Memuat data…</p></div>`;

  if (isSA) api('/api/tenants').then((d) => {
    $('#a-tenant').innerHTML += d.tenants.map((t) => `<option value="${t.id}">${esc(t.nama)}</option>`).join('');
  });
  api('/api/kelas').then((d) => {
    $('#a-kelas').innerHTML += d.kelas.map((k) => `<option>${esc(k)}</option>`).join('');
  });

  const terapkanPreset = () => {
    const k = $('#a-preset').value;
    const p = PRESET_RENTANG.find((x) => x[0] === k);
    if (k === '30h') { $('#a-dari').value = tglMinus(29); $('#a-sampai').value = tglMinus(0); }
    else if (k === '7h') { $('#a-dari').value = tglMinus(6); $('#a-sampai').value = tglMinus(0); }
    else { $('#a-dari').value = p[2]; $('#a-sampai').value = p[3]; }
    $('#a-gran').value = k === 'semua' ? 'mingguan' : 'harian';
  };
  $('#a-preset').onchange = () => { terapkanPreset(); loadAnalitik(); };
  $('#a-go').onclick = loadAnalitik;
  ['a-dari', 'a-sampai', 'a-gran', 'a-kelas'].forEach((id) => {
    const n = $('#' + id); if (n) n.onchange = loadAnalitik;
  });
  if (isSA) $('#a-tenant').onchange = loadAnalitik;

  terapkanPreset();       // default: Juli 2026
  loadAnalitik();
}

// =========================== PENJUALAN PER MENU ===========================
// Pertanyaan yang dijawab panel ini: menu mana yang laku, dan menu mana yang
// sudah lama tidak disentuh siapa pun. Yang kedua itulah yang dicari penjual
// saat memutuskan mengganti dagangan — dan justru itu yang paling mudah luput,
// karena menu tanpa penjualan tidak pernah muncul di laporan berbasis transaksi.
// Karena itu daftar "belum laku" digambar dari daftar MENU, bukan dari penjualan.

async function loadAnalitikMenu(paramsUtama, labelPeriode, tipLabel) {
  const wadah = $('#a-menu');
  if (!wadah) return;
  let d, tren;
  try {
    [d, tren] = await Promise.all([
      api('/api/analitik/menu?' + paramsUtama),
      api('/api/analitik/menu-tren?' + paramsUtama),
    ]);
  } catch (e) {
    wadah.innerHTML = `<div class="panel"><p class="muted">${esc(e.message)}</p></div>`;
    return;
  }
  const r = d.ringkasan;
  const isSA = USER.role === 'superadmin';

  const barisMenu = (m, i) => `
    <tr>
      <td class="num muted">${i + 1}</td>
      <td><b>${esc(m.nama)}</b>${m.sepi ? ' <span class="badge blokir">sepi</span>' : ''}
        ${m.kategori === 'non_makanan' ? ' <span class="badge kat-nm">non-makanan</span>' : ''}</td>
      ${isSA ? `<td>${esc(m.tenant_nama || '—')}</td>` : ''}
      <td class="num">${m.qty.toLocaleString('id-ID')}</td>
      <td class="num rp">${rp(m.total)}</td>
      <td class="num">${m.porsi}%</td>
      <td class="num">${m.n_pembeli.toLocaleString('id-ID')}</td>
      <td class="num rp">${rp(m.harga_akhir)}</td>
      <td class="muted">${waktuSingkat(m.terakhir)}</td>
    </tr>`;

  const barisBelum = (p) => `
    <tr>
      <td><b>${esc(p.nama)}</b>${p.aktif ? '' : ' <span class="badge blokir">ditandai habis</span>'}</td>
      ${isSA ? `<td>${esc(p.tenant_nama)}</td>` : ''}
      <td class="num rp">${rp(p.harga)}</td>
      <td class="muted">${p.terakhir
        ? 'terakhir laku ' + waktuSingkat(p.terakhir)
        : '<b>belum pernah terjual</b>'}</td>
    </tr>`;

  wadah.innerHTML = `
    <div class="panel">
      <h2>🍽️ Penjualan per Menu</h2>
      <p class="muted">Dihitung dari rincian tiap keranjang belanja, bukan dari total transaksi —
        jadi satu transaksi berisi tiga barang terhitung sebagai tiga baris di sini.</p>
      <div class="cards">
        <div class="statcard"><span class="muted">Menu terjual</span><br><b>${r.n_menu}</b>
          <br><span class="muted">dari ${r.n_menu_terdaftar} menu terdaftar</span></div>
        <div class="statcard"><span class="muted">Porsi terjual</span><br><b>${r.qty.toLocaleString('id-ID')}</b></div>
        <div class="statcard"><span class="muted">Nilai penjualan berincian</span><br><b>${rp(r.total)}</b></div>
        <div class="statcard"><span class="muted">Menu tanpa penjualan</span><br><b>${r.n_belum_laku}</b>
          <br><span class="muted">pada rentang ini</span></div>
      </div>
      ${r.tanpa_rincian > 0 ? `<p class="muted">ℹ️ ${rp(r.tanpa_rincian)} belanja pada rentang ini
        <b>tidak punya rincian barang</b> — transaksi yang tercatat sebelum fitur ini ada, atau
        yang dikirim kasir versi lama. Angkanya sengaja disebut supaya total per menu di bawah
        bisa dicocokkan dengan total penjualan ${rp(r.belanja_total)}.</p>` : ''}
      <div id="ch-menu"></div>
      <div class="tblwrap" style="margin-top:12px"><table>
        <thead><tr><th class="num">#</th><th>Menu</th>${isSA ? '<th>Tenant</th>' : ''}
          <th class="num">Porsi terjual</th><th class="num">Nilai</th><th class="num">Porsi nilai</th>
          <th class="num">Pembeli</th><th class="num">Harga kini</th><th>Terakhir laku</th></tr></thead>
        <tbody>${d.menu.map(barisMenu).join('') ||
          `<tr><td colspan="${isSA ? 9 : 8}" class="muted">Belum ada penjualan berincian pada rentang ini</td></tr>`}</tbody>
      </table></div>
    </div>

    <div class="panel">
      <h2>😴 Menu yang Tidak Laku</h2>
      <p class="muted">Menu yang masih terdaftar tetapi <b>tidak terjual sama sekali</b> pada rentang
        terpilih. Kolom terakhir dihitung dari seluruh riwayat, bukan hanya rentang ini — “belum
        pernah terjual” dan “terakhir laku dua bulan lalu” adalah dua keputusan yang berbeda.</p>
      <div class="tblwrap"><table>
        <thead><tr><th>Menu</th>${isSA ? '<th>Tenant</th>' : ''}<th class="num">Harga</th><th>Riwayat</th></tr></thead>
        <tbody>${d.belum_laku.map(barisBelum).join('') ||
          `<tr><td colspan="${isSA ? 4 : 3}" class="muted">Semua menu terdaftar laku pada rentang ini 🎉</td></tr>`}</tbody>
      </table></div>
    </div>

    <div class="panel">
      <h2>📉 Tren Menu</h2>
      <p class="muted">Sepuluh menu terlaris pada rentang ini, diikuti dari waktu ke waktu${isSA
        ? ' — nama menu disertai tenantnya, karena menu bernama sama bisa dijual lebih dari satu tenant' : ''}.
        Menu yang garisnya terus menurun adalah kandidat untuk diganti.</p>
      <div id="ch-menu-tren"></div>
      <div class="tblwrap" style="margin-top:12px"><table>
        <thead><tr><th>Menu</th><th class="num">Nilai pada rentang</th></tr></thead>
        <tbody>${tren.seri.map((m) => `<tr>
          <td>${esc(m.nama)}${isSA ? ` <span class="muted">· ${esc(m.tenant_nama || '—')}</span>` : ''}</td>
          <td class="num rp">${rp(m.total)}</td></tr>`).join('') ||
          '<tr><td colspan="2" class="muted">Tidak ada data</td></tr>'}</tbody>
      </table></div>
    </div>`;

  vizHBar($('#ch-menu'), {
    data: d.menu.slice(0, 12).map((m) => ({
      // charts.js yang meng-escape — jangan di-escape lagi di sini, nanti
      // nama ber-"&" tampil sebagai "&amp;".
      label: m.nama + (isSA ? ` (${m.tenant_nama || '—'})` : ''),
      nilai: m.total,
      sub: `${m.qty.toLocaleString('id-ID')} porsi · ${m.porsi}% dari penjualan berincian`,
    })),
    warnaPer: true,
  });

  // Grafiknya dibatasi enam garis — lebih dari itu warnanya mulai sulit
  // dibedakan dan grafik justru menyembunyikan yang seharusnya ditunjukkan.
  // Sepuluh menu teratas tetap lengkap di tabel di bawahnya.
  const titikX = tren.periode.map((pp) => ({ label: labelPeriode(pp), tooltipLabel: tipLabel(pp) }));
  vizLine($('#ch-menu-tren'), {
    data: titikX,
    series: tren.seri.slice(0, VIZ.deret.length).map((m, i) => ({
      nama: m.nama + (isSA ? ` · ${m.tenant_nama || '—'}` : ''),
      warna: VIZ.deret[i],
      nilai: m.titik,
    })),
    tinggi: 300,
  });
}

async function loadAnalitik() {
  const p = new URLSearchParams();
  const dari = $('#a-dari').value, sampai = $('#a-sampai').value;
  if (dari) p.set('dari', new Date(dari + 'T00:00:00+07:00').toISOString());
  if (sampai) p.set('sampai', new Date(sampai + 'T23:59:59.999+07:00').toISOString());
  p.set('granularitas', $('#a-gran').value);
  if ($('#a-kelas').value) p.set('kelas', $('#a-kelas').value);
  const ft = $('#a-tenant');
  if (ft && ft.value) p.set('tenant_id', ft.value);

  let d;
  try { d = await api('/api/analitik?' + p); }
  catch (e) { $('#a-isi').innerHTML = `<div class="panel"><p class="muted">${esc(e.message)}</p></div>`; return; }

  const r = d.ringkasan;
  const gran = d.granularitas;
  const labelPeriode = (s) => {
    if (gran === 'harian') { const [, m, dd] = s.split('-'); return `${Number(dd)}/${Number(m)}`; }
    if (gran === 'mingguan') return 'P' + s.split('-W')[1];
    return ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Ags','Sep','Okt','Nov','Des'][Number(s.split('-')[1]) - 1] + ' ' + s.split('-')[0];
  };
  const tipLabel = (s) => gran === 'harian'
    ? new Date(s + 'T00:00:00Z').toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
    : (gran === 'mingguan' ? 'Pekan ke-' + s.split('-W')[1] + ' ' + s.split('-W')[0] : labelPeriode(s));

  $('#a-note').innerHTML = d.scoped_tenant
    ? `<p class="muted">ℹ️ Filter tenant aktif — deret <b>top up</b> disembunyikan karena top up dilakukan admin dan tidak terikat tenant mana pun.</p>`
    : '';

  $('#a-isi').innerHTML = `
    <div class="cards">
      <div class="statcard"><span class="muted">Total belanja</span><br><b>${rp(r.belanja)}</b></div>
      ${d.scoped_tenant ? '' : `<div class="statcard"><span class="muted">Total top up</span><br><b>${rp(r.topup)}</b></div>`}
      <div class="statcard"><span class="muted">Jumlah transaksi</span><br><b>${r.n_beli.toLocaleString('id-ID')}</b></div>
      <div class="statcard"><span class="muted">Rata-rata / transaksi</span><br><b>${rp(r.rata2)}</b></div>
      <div class="statcard"><span class="muted">Santri bertransaksi</span><br><b>${r.siswa_unik.toLocaleString('id-ID')}</b></div>
      <div class="statcard"><span class="muted">Periode tertinggi</span><br><b>${r.hari_puncak ? esc(labelPeriode(r.hari_puncak)) : '—'}</b>
        ${r.hari_puncak ? `<br><span class="muted">${rp(r.belanja_puncak)}</span>` : ''}</div>
    </div>

    <div class="panel">
      <h2>Tren nilai transaksi</h2>
      <p class="muted">Rupiah per periode${d.scoped_tenant ? '' : ' — belanja santri dibanding top up dari admin'}.</p>
      <div id="ch-nilai"></div>
    </div>

    <div class="panel">
      <h2>Tren frekuensi transaksi</h2>
      <p class="muted">Banyaknya transaksi pembelian per periode (satuan: kali).</p>
      <div id="ch-frek"></div>
    </div>

    <div class="row">
      <div class="panel">
        <h2>Jam ramai</h2>
        <p class="muted">Sebaran pembelian menurut jam (WIB).</p>
        <div id="ch-jam"></div>
      </div>
      <div class="panel${d.scoped_tenant ? ' hidden' : ''}">
        <h2>Kontribusi tenant</h2>
        <p class="muted">Nilai penjualan per tenant pada rentang terpilih.</p>
        <div id="ch-tenant"></div>
        <div class="tblwrap" style="margin-top:12px"><table>
          <thead><tr><th>Tenant</th><th class="num">Transaksi</th><th class="num">Nilai</th><th class="num">Porsi</th></tr></thead>
          <tbody>${d.per_tenant.map((t) => `<tr><td>${esc(t.nama)}</td>
            <td class="num">${t.n.toLocaleString('id-ID')}</td><td class="num rp">${rp(t.total)}</td>
            <td class="num">${r.belanja ? Math.round(t.total / r.belanja * 100) : 0}%</td></tr>`).join('') ||
            '<tr><td colspan="4" class="muted">Tidak ada data</td></tr>'}</tbody>
        </table></div>
      </div>
    </div>

    <div class="row">
      <div class="panel">
        <h2>Kelas paling aktif</h2>
        <div id="ch-kelas"></div>
      </div>
      <div class="panel">
        <h2>Santri belanja terbanyak</h2>
        <div class="tblwrap"><table>
          <thead><tr><th>Santri</th><th>Kelas</th><th class="num">Transaksi</th><th class="num">Total</th></tr></thead>
          <tbody>${d.top_siswa.map((s) => `<tr><td>${esc(s.siswa_nama)}</td><td>${esc(s.kelas)}</td>
            <td class="num">${s.n}</td><td class="num rp">${rp(s.total)}</td></tr>`).join('') ||
            '<tr><td colspan="4" class="muted">Tidak ada data</td></tr>'}</tbody>
        </table></div>
      </div>
    </div>

    <div id="a-menu"><div class="panel"><p class="muted">Memuat penjualan per menu…</p></div></div>

    <div class="panel">
      <h2>Tabel data tren</h2>
      <p class="muted">Angka di balik grafik di atas — bisa disalin ke Excel, atau gunakan menu Riwayat untuk unduhan lengkap.</p>
      <div class="tblwrap"><table>
        <thead><tr><th>Periode</th><th class="num">Belanja</th>${d.scoped_tenant ? '' : '<th class="num">Top up</th>'}
        <th class="num">Jumlah transaksi</th><th class="num">Santri aktif</th></tr></thead>
        <tbody>${d.series.map((s) => `<tr><td>${esc(tipLabel(s.periode))}</td>
          <td class="num rp">${rp(s.belanja)}</td>${d.scoped_tenant ? '' : `<td class="num rp">${rp(s.topup)}</td>`}
          <td class="num">${s.n_beli.toLocaleString('id-ID')}</td>
          <td class="num">${s.siswa_aktif.toLocaleString('id-ID')}</td></tr>`).join('') ||
          '<tr><td colspan="5" class="muted">Tidak ada data pada rentang ini</td></tr>'}</tbody>
      </table></div>
    </div>`;

  // Panel menu memakai endpoint tersendiri (tabel rincian baris, bukan tabel
  // transaksi) dan dimuat menyusul supaya grafik utama tidak menunggunya.
  loadAnalitikMenu(p, labelPeriode, tipLabel);

  const dataX = d.series.map((s) => ({ label: labelPeriode(s.periode), tooltipLabel: tipLabel(s.periode) }));

  vizLine($('#ch-nilai'), {
    data: dataX,
    series: d.scoped_tenant
      ? [{ nama: 'Belanja', warna: VIZ.s1, nilai: d.series.map((s) => s.belanja) }]
      : [{ nama: 'Belanja santri', warna: VIZ.s1, nilai: d.series.map((s) => s.belanja) },
         { nama: 'Top up', warna: VIZ.s2, nilai: d.series.map((s) => s.topup) }],
    tinggi: 280,
  });

  vizBar($('#ch-frek'), {
    data: d.series.map((s, i) => ({
      label: dataX[i].label, tooltipLabel: dataX[i].tooltipLabel, nilai: s.n_beli,
      tooltipExtra: `${s.siswa_aktif.toLocaleString('id-ID')} santri aktif · ${rp(s.belanja)}`,
    })),
    namaDeret: 'Transaksi', warna: VIZ.s1, tinggi: 220,
  });

  const jamMap = new Map(d.per_jam.map((j) => [j.jam, j]));
  vizBar($('#ch-jam'), {
    data: Array.from({ length: 12 }, (_, k) => {
      const jam = k + 6;                       // 06:00 – 17:00
      const j = jamMap.get(jam) || { n: 0, total: 0 };
      return { label: String(jam).padStart(2, '0'), tooltipLabel: `Pukul ${String(jam).padStart(2, '0')}.00–${String(jam).padStart(2, '0')}.59 WIB`,
               nilai: j.n, tooltipExtra: rp(j.total) };
    }),
    namaDeret: 'Transaksi', warna: VIZ.s1, tinggi: 200, minLabelPx: 20,
  });

  vizHBar($('#ch-tenant'), {
    data: d.per_tenant.map((t) => ({ label: t.nama, nilai: t.total, sub: `${t.n.toLocaleString('id-ID')} transaksi` })),
    warnaPer: true,
  });

  vizHBar($('#ch-kelas'), {
    data: d.per_kelas.map((k) => ({ label: k.kelas || '—', nilai: k.total,
      sub: `${k.n.toLocaleString('id-ID')} transaksi · ${k.siswa} santri` })),
    warna: VIZ.s1,
  });
}

// ╔══════════════════════════════════════════════════════════╗
// ║ SUPERADMIN: SALDO SANTRI — top up & potong tanpa tap kartu ║
// ╚══════════════════════════════════════════════════════════╝
// Santri dipilih lewat pencarian (nama/NIS/kelas/UID), jadi admin tidak perlu
// memegang kartu maupun reader. Tap kartu tetap jalan sebagai jalan pintas.
const PRESET_TOPUP = [10000, 20000, 50000, 100000];
const PRESET_POTONG = [5000, 10000, 20000, 50000];
// Kategori alasan potongan kini datang dari server (bisa disunting admin lewat
// tombol "kelola" di menu Saldo Santri), bukan lagi daftar tetap di kode.
let ALASAN_POTONG = [];

let saldo = { siswa: null, mode: 'topup', nominal: null, batch: [], pilih: new Set() };
let saldoPreselect = null;     // santri yang dioper dari tab Santri

function saldoView(el) {
  saldo = { siswa: saldoPreselect, mode: 'topup', nominal: null, batch: [], pilih: new Set() };
  saldoPreselect = null;
  el.innerHTML = `
  <div class="row">
    <div class="panel">
      <h2 id="sd-target-title">1 · Pilih Santri</h2>
      <div id="sd-target"></div>
    </div>
    <div class="panel">
      <h2>2 · Penyesuaian Saldo</h2>
      <div class="modeswitch" id="sd-mode">
        <button data-mode="topup" class="sel plus-mode">＋ Top Up</button>
        <button data-mode="potong" class="minus-mode">− Potong</button>
        <button data-mode="kolektif" class="minus-mode">👥 Potong Kolektif</button>
      </div>
      <p class="muted hidden" id="sd-impor-ajak" style="margin:0 0 10px">
        Nominalnya berbeda-beda tiap santri?
        <button class="linkbtn" id="sd-impor-buka">⬆ Potong via Excel</button> ·
        <button class="linkbtn" id="sd-impor-riwayat">riwayat</button></p>
      <div class="aju-unduh hidden" id="sd-impor-box">
        <span class="muted">Satu berkas, nominal berbeda tiap santri:</span>
        <select id="sd-impor-kelas" class="k-chip-select" style="width:auto; margin:0 4px">
          <option value="">template kosong</option>
        </select>
        <button class="linkbtn" id="sd-impor-tpl">⬇ Unduh template</button>
        <label class="linkbtn" style="cursor:pointer">📂 Pilih berkas yang sudah diisi
          <input type="file" id="sd-impor-berkas" accept=".xlsx,.csv" class="hidden"></label>
        <span class="muted set-hint">Berkasnya diperiksa dulu di layar — <b>tidak ada satu rupiah
          pun yang berpindah</b> sebelum Anda menekan terapkan. Baris yang namanya tidak cocok,
          NIS-nya tidak terdaftar, atau saldonya kurang akan dilewati beserta sebabnya.</span>
      </div>
      <div class="field"><label id="sd-jumlah-label">Nominal</label>
        <div class="quick" id="sd-quick"></div>
        <button class="linkbtn hidden" id="sd-kosongkan" style="margin-bottom:6px">
          🧹 Kosongkan seluruh saldo</button>
        ${inputUang({ id: 'sd-jumlah', placeholder: 'Nominal lain (Rp)' })}</div>
      <div class="field hidden" id="sd-sumber-field"><label>Sumber potongan</label>
        <select id="sd-sumber">
          <option value="kartu">Kartu saja — titipan tidak disentuh</option>
          <option value="kartu_titipan">Kartu dulu, sisanya dari titipan</option>
          <option value="titipan_kartu">Titipan dulu, sisanya dari kartu</option>
        </select>
        <span class="muted set-hint">Titipan adalah uang yang sengaja diparkir wali —
          ia tidak pernah ikut terambil kecuali dipilih di sini.</span></div>
      <div class="field hidden" id="sd-kurang-field"><label>Kalau saldo santri kurang</label>
        <select id="sd-kurang">
          <option value="lewati">Lewati santri itu (saldo tidak berubah)</option>
          <option value="sebisanya">Potong sebisanya sampai saldo 0</option>
        </select></div>
      <div class="field" id="sd-biaya-field"><label>Biaya admin</label>
        <select id="sd-biaya">
          <option value="free" selected>Free — tanpa biaya admin</option>
          <option value="biaya">Biaya admin</option>
        </select>
        <span class="hint-lembut" style="margin-left:0">Biaya dipotong dari nominal dan masuk Dana Admin.
          Santri yang dibebaskan biaya tetap tidak dipotong.</span>
      </div>
      <div class="field hidden" id="sd-titipan-field">
        <label><input type="checkbox" id="sd-ke-titipan" style="width:auto"> Simpan kelebihan sebagai saldo titipan</label>
        <span class="hint-lembut">Kartu diisi sebanyak yang tertampung; sisanya menunggu di
          titipan dan otomatis masuk kartu saat saldo santri menipis.</span>
      </div>
      <div class="field"><label id="sd-ket-label">Keterangan</label>
        <div class="hidden" id="sd-kategori-wrap">
          <div class="kat-head">
            <span class="muted">Kategori potongan — klik untuk mengisi</span>
            <button class="linkbtn" id="sd-kelola-kat">⚙ kelola</button>
          </div>
          <div class="quick kat-list" id="sd-kategori"></div>
        </div>
        <input id="sd-ket" list="sd-alasan" autocomplete="off">
        <datalist id="sd-alasan"></datalist>
      </div>
      <div class="preview" id="sd-preview"></div>
      <button class="paybtn" id="btn-saldo" disabled>Top Up</button>
    </div>
  </div>
  <div class="panel hidden" id="sd-titipan-panel"></div>
  <div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px">
      <h2 style="margin:0">Penyesuaian Saldo Terakhir</h2>
      <button class="linkbtn" id="sd-refresh">muat ulang</button>
    </div>
    <p class="muted">Top up dan pemotongan oleh admin, lengkap dengan operator dan alasannya.
      Riwayat penuh beserta ekspornya ada di menu Riwayat (filter tipe “penyesuaian admin”).</p>
    <div class="tblwrap"><table>
      <thead><tr><th>Waktu</th><th>Santri</th><th>Kelas</th><th>Tipe</th>
      <th class="num">Jumlah</th><th class="num">Saldo Akhir</th><th>Oleh</th><th>Keterangan</th></tr></thead>
      <tbody id="sd-log"><tr><td colspan="8" class="muted">memuat…</td></tr></tbody>
    </table></div>
  </div>`;

  el.querySelectorAll('#sd-mode button').forEach((b) => {
    b.onclick = () => {
      saldo.mode = b.dataset.mode;
      saldo.nominal = null;
      $('#sd-jumlah').value = '';
      $('#sd-ket').value = '';
      el.querySelectorAll('#sd-mode button').forEach((x) => x.classList.toggle('sel', x === b));
      $('#sd-kurang-field').classList.toggle('hidden', !sdKolektif());
      // Sumber potongan berlaku untuk kedua mode potong, bukan hanya kolektif.
      $('#sd-sumber-field').classList.toggle('hidden', saldo.mode === 'topup');
      $('#sd-kosongkan').classList.toggle('hidden', saldo.mode === 'topup');
      // Jalur Excel hanya ditawarkan di mode kolektif — di sanalah "nominal
      // berbeda tiap santri" menjadi pertanyaan yang wajar muncul.
      $('#sd-impor-ajak').classList.toggle('hidden', !sdKolektif());
      if (!sdKolektif()) $('#sd-impor-box').classList.add('hidden');
      sdRenderTarget();
      sdRenderQuick();
      sdRenderKategori();
      sdSync();
      muatPanelTitipan();      // panel titipan tidak berlaku pada mode kolektif
    };
  });
  $('#sd-ke-titipan').addEventListener('change', sdSync);
  $('#sd-biaya').addEventListener('change', sdSync);
  api('/api/topup/tarif').then((d) => {
    saldo.tarif = d.tarif;
    // Tarif 0 bukan kesalahan, tapi pilihan "biaya admin" lalu tidak memotong
    // apa pun — itu harus terbaca di labelnya, bukan ketahuan belakangan.
    $('#sd-biaya').options[1].textContent = d.tarif > 0
      ? `Biaya admin (${rp(d.tarif)})` : 'Biaya admin (tarif sekolah belum diatur — Rp0)';
    sdSync();
  }).catch(() => {});
  $('#sd-kelola-kat').onclick = kelolaKategoriModal;
  $('#sd-jumlah').addEventListener('input', () => {
    saldo.nominal = nilaiUang('#sd-jumlah');
    document.querySelectorAll('#sd-quick button').forEach((x) => x.classList.remove('sel'));
    sdSync();
  });
  // mengetik manual ikut memperbarui sorotan kategori (mis. jadi tidak ada
  // yang tersorot begitu teksnya menyimpang dari kategori mana pun)
  $('#sd-ket').addEventListener('input', () => { sdRenderKategori(); sdSync(); });
  $('#sd-kurang').addEventListener('change', () => { sdRenderBatch(); sdSync(); });
  $('#sd-sumber').addEventListener('change', () => { sdRenderBatch(); sdSync(); });
  $('#sd-kosongkan').onclick = sdKosongkan;

  $('#sd-impor-buka').onclick = () => $('#sd-impor-box').classList.toggle('hidden');
  $('#sd-impor-riwayat').onclick = potongRiwayatModal;
  $('#sd-impor-tpl').onclick = () => {
    // Berkas biner: lewat <a>, bukan fetch+JSON.
    const kelas = $('#sd-impor-kelas').value;
    const a2 = document.createElement('a');
    a2.href = '/api/potong-massal/template' + (kelas ? `?isi=1&kelas=${encodeURIComponent(kelas)}` : '');
    document.body.appendChild(a2); a2.click(); a2.remove();
  };
  $('#sd-impor-berkas').onchange = (e) => {
    const f = e.target.files[0];
    e.target.value = '';   // berkas yang sama boleh dipilih lagi sesudah diperbaiki
    if (f) potongImporPratinjau(f);
  };
  // Daftar kelas untuk template terisi — dipakai juga oleh saringan di panel 1.
  api('/api/kelas').then((d) => {
    const sel = $('#sd-impor-kelas');
    if (!sel || !d.kelas) return;
    sel.innerHTML = '<option value="">template kosong</option>'
      + d.kelas.map((k) => `<option value="${esc(k)}">terisi kelas ${esc(k)}</option>`).join('');
  }).catch(() => {});
  $('#btn-saldo').onclick = doSaldo;
  $('#sd-refresh').onclick = loadPenyesuaian;

  sdRenderTarget();
  sdRenderQuick();
  sdSync();
  muatKategori();
  loadPenyesuaian();
}

// ---------------------------------------------- kategori alasan potongan
async function muatKategori() {
  const d = await api('/api/alasan-potong').catch(() => ({ alasan: [] }));
  ALASAN_POTONG = d.alasan.map((a) => a.teks);
  sdRenderKategori();
}

/**
 * Tombol kategori hanya muncul pada mode potong & kolektif — di mode top up
 * keterangannya lain sama sekali, jadi menampilkannya di sana justru menyesatkan.
 */
function sdRenderKategori() {
  const wrap = $('#sd-kategori-wrap');
  if (!wrap) return;
  const tampil = sdPotong() || sdKolektif();
  wrap.classList.toggle('hidden', !tampil || !ALASAN_POTONG.length);
  $('#sd-alasan').innerHTML = ALASAN_POTONG.map((a) => `<option value="${esc(a)}">`).join('');
  if (!tampil) return;

  const kotak = $('#sd-kategori');
  const terpilih = $('#sd-ket').value.trim();
  kotak.innerHTML = ALASAN_POTONG.map((a) =>
    `<button type="button" class="${a === terpilih ? 'sel' : ''}">${esc(a)}</button>`).join('');
  kotak.querySelectorAll('button').forEach((b, i) => {
    b.onclick = () => {
      const teks = ALASAN_POTONG[i];
      // klik lagi pada kategori yang sama = batalkan pilihan
      $('#sd-ket').value = ($('#sd-ket').value.trim() === teks) ? '' : teks;
      sdRenderKategori();
      sdSync();
    };
  });
}

function kelolaKategoriModal() {
  api('/api/alasan-potong?semua=1').then((d) => {
    let baris = d.alasan.map((a) => ({ id: a.id, teks: a.teks, aktif: a.aktif === 1 }));

    const gambar = () => {
      $('#kat-baris').innerHTML = baris.map((r, i) => `
        <div class="kat-row">
          <input class="kat-teks" data-i="${i}" value="${esc(r.teks)}" maxlength="100"
                 placeholder="nama kategori">
          <label class="kat-aktif" title="Tampilkan di halaman pemotongan">
            <input type="checkbox" data-i="${i}" ${r.aktif ? 'checked' : ''}> tampil
          </label>
          <button class="linkbtn" data-naik="${i}" ${i === 0 ? 'disabled' : ''} title="naikkan">↑</button>
          <button class="linkbtn" data-turun="${i}" ${i === baris.length - 1 ? 'disabled' : ''} title="turunkan">↓</button>
          <button class="linkbtn danger" data-hapus="${i}" title="hapus">✕</button>
        </div>`).join('') || '<p class="muted">Belum ada kategori. Tambahkan di bawah.</p>';

      $('#kat-baris').querySelectorAll('.kat-teks').forEach((inp) => {
        inp.oninput = () => { baris[Number(inp.dataset.i)].teks = inp.value; };
      });
      $('#kat-baris').querySelectorAll('.kat-aktif input').forEach((c) => {
        c.onchange = () => { baris[Number(c.dataset.i)].aktif = c.checked; };
      });
      $('#kat-baris').querySelectorAll('[data-naik]').forEach((b) => {
        b.onclick = () => { const i = Number(b.dataset.naik); [baris[i - 1], baris[i]] = [baris[i], baris[i - 1]]; gambar(); };
      });
      $('#kat-baris').querySelectorAll('[data-turun]').forEach((b) => {
        b.onclick = () => { const i = Number(b.dataset.turun); [baris[i + 1], baris[i]] = [baris[i], baris[i + 1]]; gambar(); };
      });
      $('#kat-baris').querySelectorAll('[data-hapus]').forEach((b) => {
        b.onclick = () => { baris.splice(Number(b.dataset.hapus), 1); gambar(); };
      });
    };

    openModal(`
      <h2>Kategori Potongan</h2>
      <p class="muted">Pilihan cepat saat memotong saldo. Yang tersimpan di riwayat tetap
        teks alasannya — mengubah atau menghapus kategori tidak mengubah transaksi
        yang sudah tercatat. Hilangkan centang <b>tampil</b> untuk menyembunyikan
        kategori tanpa menghapusnya.</p>
      <div id="kat-baris"></div>
      <button class="linkbtn" id="kat-tambah">+ Tambah kategori</button>`,
      async () => {
        const bersih = baris.map((r) => ({ id: r.id, teks: r.teks.trim(), aktif: r.aktif }))
          .filter((r) => r.teks.length);
        if (bersih.some((r) => r.teks.length < 3)) throw new Error('Setiap kategori minimal 3 huruf');
        await api('/api/alasan-potong', { method: 'PUT', body: { alasan: bersih } });
        toast('✔ Kategori potongan disimpan', 'ok');
        muatKategori();
      });

    gambar();
    $('#kat-tambah').onclick = () => {
      baris.push({ id: null, teks: '', aktif: true });
      gambar();
      const kotak = $('#kat-baris').querySelectorAll('.kat-teks');
      kotak[kotak.length - 1]?.focus();
    };
  }).catch((e) => toast(e.message, 'err'));
}

const sdPotong = () => saldo.mode === 'potong';
const sdKolektif = () => saldo.mode === 'kolektif';

function sdRenderQuick() {
  const list = saldo.mode === 'topup' ? PRESET_TOPUP : PRESET_POTONG;
  const q = $('#sd-quick');
  q.innerHTML = list.map((n) => `<button data-n="${n}" class="rp">${rp(n)}</button>`).join('')
    + (sdPotong() ? '<button data-n="semua">Semua saldo</button>' : '');
  $('#sd-jumlah-label').textContent = sdKolektif() ? 'Nominal per santri' : 'Nominal';
  q.querySelectorAll('button').forEach((b) => {
    b.onclick = () => {
      if (b.dataset.n === 'semua') {
        if (!saldo.siswa) { toast('Pilih santri dulu', 'err'); return; }
        saldo.nominal = saldo.siswa.saldo;
      } else {
        saldo.nominal = Number(b.dataset.n);
      }
      $('#sd-jumlah').value = '';
      q.querySelectorAll('button').forEach((x) => x.classList.toggle('sel', x === b));
      if (sdKolektif()) sdRenderBatch();
      sdSync();
    };
  });
}

// Panel kiri berganti isi mengikuti mode: satu santri (cari/tap) atau satu kelas.
function sdRenderTarget() {
  const t = $('#sd-target');
  $('#sd-target-title').textContent = sdKolektif() ? '1 · Pilih Kelas/Unit' : '1 · Pilih Orang';
  if (!sdKolektif()) {
    t.innerHTML = `
      <p class="muted">Cari langsung tanpa kartu — ketik nama, NIS/NIY, kelas/unit, atau UID.
        Berlaku untuk santri maupun SDM/tamu/VIP. Kalau reader tersedia, tap kartu di
        halaman ini juga memilih orangnya.</p>
      <div class="field"><input id="sd-cari" autocomplete="off"
        placeholder="mis. Andi · 2024001 · 7A · 04A1B2C3"></div>
      <div id="sd-hasil" class="reslist"></div>
      <div id="sd-box"></div>`;
    $('#sd-cari').addEventListener('input', debounce(sdCari, 250));
    $('#sd-cari').addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      const first = $('#sd-hasil').querySelector('button');
      if (first) first.click();
    });
    if (saldo.siswa) renderSaldoSiswa();
    return;
  }
  t.innerHTML = `
    <p class="muted">Pilih kelas atau unit SDM — seluruh isinya masuk daftar, lalu hilangkan
      centang yang dikecualikan. Beberapa kelas/unit boleh digabung dalam satu pemotongan.
      Yang berstatus blokir tidak dicentang otomatis.</p>
    <div class="filterbar">
      <div class="field"><label>Kelas / Unit</label><select id="sd-kelas"><option value="">memuat…</option></select></div>
      <button class="go" id="sd-tambah">+ Tambahkan kelas/unit</button>
      <button id="sd-kosongkan">Kosongkan daftar</button>
    </div>
    <div id="sd-batch"></div>`;
  api('/api/kelas').then((d) => {
    const sel = $('#sd-kelas');
    // Digabung dengan Unit SDM: Potong Kolektif sekarang berlaku untuk
    // golongan apa pun, jadi admin juga harus bisa memilih unitnya, bukan
    // cuma kelas santri.
    const pilihan = (d.kelas || []).concat(d.unit_sdm || []);
    if (sel) sel.innerHTML = pilihan.map((k) => `<option>${esc(k)}</option>`).join('')
      || '<option value="">(belum ada kelas/unit)</option>';
  }).catch(() => {});
  $('#sd-tambah').onclick = sdTambahKelas;
  $('#sd-kosongkan').onclick = () => {
    saldo.batch = []; saldo.pilih = new Set();
    sdRenderBatch(); sdSync();
  };
  sdRenderBatch();
}

async function sdTambahKelas() {
  const kelas = $('#sd-kelas').value;
  if (!kelas) return;
  const btn = $('#sd-tambah');
  btn.disabled = true;
  try {
    // golongan=semua: tanpa ini server hanya mengembalikan golongan siswa —
    // "kelas" dipakai juga sebagai "unit" oleh golongan sdm/tamu/vip, jadi
    // Potong Kolektif per kelas/unit ini berlaku untuk mereka juga.
    const d = await api('/api/siswa?limit=500&golongan=semua&kelas=' + encodeURIComponent(kelas));
    const adaId = new Set(saldo.batch.map((s) => s.id));
    // hanya yang benar-benar di kelas/unit itu (parameter kelas dicocokkan persis di server)
    const baru = d.siswa.filter((s) => !adaId.has(s.id));
    saldo.batch = saldo.batch.concat(baru);
    for (const s of baru) if (s.status === 'aktif') saldo.pilih.add(s.id);
    toast(baru.length ? `${baru.length} orang di kelas/unit ${kelas} ditambahkan` : `Kelas/unit ${kelas} sudah ada di daftar`,
      baru.length ? 'ok' : '');
    sdRenderBatch();
    sdSync();
  } catch (e) { toast(e.message, 'err'); }
  btn.disabled = false;
}

/**
 * Berapa yang benar-benar terpotong dari satu santri — dan dari kantong mana.
 *
 * Cerminan dari Services\PotongSaldo::bagi() di server. Ditulis dua kali
 * dengan sadar: layar harus bisa memperlihatkan pecahannya SEBELUM apa pun
 * dikirim, sedangkan yang menentukan tetap server. Kalau keduanya berbeda,
 * pratinjau berbohong — jadi uji menyandingkan hasil keduanya.
 */
function sdBagi(s) {
  const n = saldo.nominal || 0;
  const sumber = $('#sd-sumber')?.value || 'kartu';
  const sebisanya = $('#sd-kurang')?.value === 'sebisanya';
  const adaKartu = Math.max(0, s.saldo || 0);
  const adaTitipan = sumber === 'kartu' ? 0 : Math.max(0, s.saldo_titipan || 0);
  if (!n) return { kartu: 0, titipan: 0, total: 0, cukup: false };

  let sisa = n, kartu = 0, titipan = 0;
  if (sumber === 'titipan_kartu') {
    titipan = Math.min(sisa, adaTitipan); sisa -= titipan;
    kartu = Math.min(sisa, adaKartu); sisa -= kartu;
  } else {
    kartu = Math.min(sisa, adaKartu); sisa -= kartu;
    titipan = Math.min(sisa, adaTitipan); sisa -= titipan;
  }
  const cukup = sisa === 0;
  if (!cukup && !sebisanya) return { kartu: 0, titipan: 0, total: 0, cukup: false };
  return { kartu, titipan, total: kartu + titipan, cukup };
}

// Berapa yang benar-benar terpotong dari satu santri dengan nominal & mode saat ini.
function sdPotongEfektif(s) {
  return sdBagi(s).total;
}

/** Isi nominal dengan angka yang mengosongkan seluruh uang yang terpilih. */
function sdKosongkan() {
  const sumber = $('#sd-sumber')?.value || 'kartu';
  const punya = (s) => Math.max(0, s.saldo || 0)
    + (sumber === 'kartu' ? 0 : Math.max(0, s.saldo_titipan || 0));

  let n = 0;
  if (sdKolektif()) {
    const dipilih = saldo.batch.filter((x) => saldo.pilih.has(x.id));
    if (!dipilih.length) { toast('Belum ada yang dicentang', 'err'); return; }
    // Nominal disetel sebesar isi TERBANYAK, lalu "sebisanya" membuat tiap
    // santri terpotong sebesar isinya sendiri — bukan sebesar angka itu.
    n = Math.max(0, ...dipilih.map(punya));
    $('#sd-kurang').value = 'sebisanya';
  } else {
    if (!saldo.siswa) { toast('Pilih santrinya dulu', 'err'); return; }
    n = punya(saldo.siswa);
  }
  if (!n) { toast('Tidak ada saldo yang bisa dikosongkan', 'err'); return; }
  isiUang('#sd-jumlah', n);
  saldo.nominal = n;
  if (sdKolektif()) sdRenderBatch();
  sdSync();
}

function sdRenderBatch() {
  const box = $('#sd-batch');
  if (!box) return;
  if (!saldo.batch.length) {
    box.innerHTML = '<p class="muted">Daftar masih kosong — pilih kelas/unit lalu tekan “Tambahkan kelas/unit”.</p>';
    return;
  }
  const n = saldo.nominal || 0;
  const rows = saldo.batch.map((s) => {
    const dicentang = saldo.pilih.has(s.id);
    const bagi = sdBagi(s);
    const efektif = bagi.total;
    const kurang = n > 0 && efektif < n;
    const pakaiTitipan = ($('#sd-sumber')?.value || 'kartu') !== 'kartu';
    return `<tr class="${dicentang ? '' : 'off'}">
      <td><input type="checkbox" data-id="${s.id}" ${dicentang ? 'checked' : ''} style="width:auto"></td>
      <td><b>${esc(s.nama)}</b>${s.status === 'blokir' ? ' <span class="badge blokir">blokir</span>' : ''}</td>
      <td>${esc(s.kelas)}</td>
      <td class="num rp">${rp(s.saldo)}${pakaiTitipan
        ? `<br><span class="muted" style="font-size:11px">titipan ${rp(s.saldo_titipan || 0)}</span>` : ''}</td>
      <td class="num ${kurang ? 'minus' : 'muted'}">${!n ? '—'
        : efektif > 0 ? '−' + rp(efektif) + (kurang ? ' (sebagian)' : '')
          + (bagi.titipan > 0 ? `<br><span class="muted" style="font-size:11px">${rp(bagi.kartu)} kartu + ${rp(bagi.titipan)} titipan</span>` : '')
        : 'dilewati'}</td>
    </tr>`;
  }).join('');
  const dipilih = saldo.batch.filter((s) => saldo.pilih.has(s.id));
  box.innerHTML = `
    <div class="batchbar">
      <label><input type="checkbox" id="sd-all" style="width:auto"
        ${dipilih.length === saldo.batch.length ? 'checked' : ''}> pilih semua</label>
      <span class="muted">${dipilih.length} dari ${saldo.batch.length} dicentang</span>
    </div>
    <div class="tblwrap batchlist"><table>
      <thead><tr><th></th><th>Nama</th><th>Kelas</th><th class="num">Saldo</th><th class="num">Dipotong</th></tr></thead>
      <tbody>${rows}</tbody>
    </table></div>`;
  box.querySelectorAll('tbody input[type=checkbox]').forEach((c) => {
    c.onchange = () => {
      const id = Number(c.dataset.id);
      if (c.checked) saldo.pilih.add(id); else saldo.pilih.delete(id);
      sdRenderBatch();
      sdSync();
    };
  });
  $('#sd-all').onchange = (e) => {
    saldo.pilih = e.target.checked ? new Set(saldo.batch.map((s) => s.id)) : new Set();
    sdRenderBatch();
    sdSync();
  };
}

async function sdCari() {
  const q = $('#sd-cari').value.trim();
  const box = $('#sd-hasil');
  if (q.length < 2) { box.innerHTML = ''; return; }
  let d;
  // golongan=semua: tanpa ini server hanya mengembalikan golongan siswa (lihat
  // SiswaController::saring) — kotak cari ini dipakai bersama mode Topup dan
  // Potong satuan, jadi berlaku untuk SDM/tamu/VIP juga, bukan cuma santri.
  try { d = await api('/api/siswa?limit=8&golongan=semua&cari=' + encodeURIComponent(q)); }
  catch (e) { box.innerHTML = `<p class="muted">${esc(e.message)}</p>`; return; }
  if (!d.siswa.length) { box.innerHTML = '<p class="muted">Tidak ada yang cocok</p>'; return; }
  box.innerHTML = d.siswa.map((s) => `
    <button data-id="${s.id}">
      ${avatarHtml(s, true)}
      <span class="res-info"><b>${esc(s.nama)}</b><br>
        <span class="muted">${esc(s.kelas || '—')} · NIS ${esc(s.nis || '—')}${s.status === 'blokir' ? ' · BLOKIR' : ''}</span></span>
      <span class="rp res-saldo">${rp(s.saldo)}</span>
    </button>`).join('');
  box.querySelectorAll('button').forEach((b) => {
    b.onclick = () => {
      saldo.siswa = d.siswa.find((s) => s.id === Number(b.dataset.id));
      box.innerHTML = '';
      $('#sd-cari').value = '';
      renderSaldoSiswa();
      sdSync();
    };
  });
}

async function saldoLookup(uid) {
  try {
    const d = await api('/api/card-lookup', { method: 'POST', body: { uid } });
    if (!d.siswa) { toast('Kartu belum terdaftar — daftarkan di tab Santri', 'err'); return; }
    if (sdKolektif()) {                      // tap kartu = tambahkan ke daftar batch
      if (!saldo.batch.some((s) => s.id === d.siswa.id)) saldo.batch.push(d.siswa);
      saldo.pilih.add(d.siswa.id);
      sdRenderBatch();
      toast(`${d.siswa.nama} ditambahkan ke daftar`, 'ok');
    } else {
      saldo.siswa = d.siswa;
      renderSaldoSiswa();
    }
    sdSync();
  } catch (e) { toast(e.message, 'err'); }
}

function renderSaldoSiswa() {
  const s = saldo.siswa;
  if (!s || !$('#sd-box')) return;
  $('#sd-box').innerHTML = `
    <div class="stucard">${avatarHtml(s)}
      <div><b>${esc(s.nama)}</b><br><span class="muted">${esc(s.kelas)} · NIS ${esc(s.nis)}</span><br>
      <span class="badge ${s.status}">${s.status.toUpperCase()}</span></div>
      <div class="saldo"><span class="muted">Saldo</span><br><b class="rp">${rp(s.saldo)}</b><br>
        <button class="linkbtn" id="sd-ganti">ganti santri</button></div>
    </div>`;
  $('#sd-ganti').onclick = () => {
    saldo.siswa = null;
    $('#sd-box').innerHTML = '';
    $('#sd-cari').focus();
    sdSync();
    muatPanelTitipan();
  };
  muatPanelTitipan();
}

// ------------------------------------------------- panel saldo titipan
// Titipan adalah uang santri yang dipegang sekolah tetapi belum ada di kartu.
// Panel ini satu-satunya tempat admin memindahkannya, dan tiap tombolnya
// berpasangan dengan satu jalur di server yang mencatat mutasinya.
const TITIPAN_JENIS = {
  setor: 'setoran masuk', isi_saldo: 'isi ulang ke kartu',
  tarik: 'ditarik/dikembalikan', koreksi: 'koreksi',
};

async function muatPanelTitipan() {
  const panel = $('#sd-titipan-panel');
  if (!panel) return;
  const s = saldo.siswa;
  if (!s || sdKolektif()) { panel.classList.add('hidden'); panel.innerHTML = ''; return; }

  panel.classList.remove('hidden');
  panel.innerHTML = '<p class="muted">memuat saldo titipan…</p>';
  let d;
  try { d = await api(`/api/siswa/${s.id}/titipan`); }
  catch (e) { panel.innerHTML = `<p class="muted">Gagal memuat titipan: ${esc(e.message)}</p>`; return; }
  // Santri bisa saja sudah diganti selagi permintaan berjalan.
  if (!saldo.siswa || saldo.siswa.id !== d.siswa.id) return;

  const t = d.siswa;
  const b = d.berlaku;
  const asal = (nilai) => nilai === null ? ' <span class="muted">(ikut sekolah)</span>' : '';
  panel.innerHTML = `
    <div class="kat-head">
      <h2 style="margin:0">💰 Saldo Titipan — ${esc(t.nama)}</h2>
      <button class="linkbtn" id="tt-muat">muat ulang</button>
    </div>
    <div class="cards" style="margin:8px 0 12px">
      <div class="statcard"><span class="muted">Saldo kartu</span><br><b class="rp">${rp(t.saldo)}</b></div>
      <div class="statcard"><span class="muted">Saldo titipan</span><br><b class="rp">${rp(t.saldo_titipan)}</b></div>
      <div class="statcard"><span class="muted">Smart top up</span><br>
        <b>${b.nyala ? 'nyala' : 'mati'}</b><br>
        <span class="muted" style="font-size:12px">isi ulang saat saldo ≤ ${rp(b.ambang)}${asal(t.smart_ambang)}
          → sampai ${rp(b.isi)}${asal(t.smart_isi)}</span></div>
    </div>
    <div class="quick">
      <button id="tt-setor">＋ Setor ke titipan</button>
      <button id="tt-cair" ${t.saldo_titipan > 0 ? '' : 'disabled'}>⇢ Cairkan ke kartu</button>
      <button id="tt-tarik" ${t.saldo_titipan > 0 ? '' : 'disabled'}>− Tarik titipan</button>
      <button id="tt-smart">⚙ Setelan smart top up</button>
    </div>
    <div class="tblwrap" style="margin-top:10px"><table>
      <thead><tr><th>Waktu</th><th>Mutasi</th><th class="num">Jumlah</th>
      <th class="num">Titipan Akhir</th><th>Oleh</th><th>Keterangan</th></tr></thead>
      <tbody>${d.mutasi.map((m) => `
        <tr><td>${esc(waktuSingkat(m.created_at))}</td>
        <td>${esc(TITIPAN_JENIS[m.jenis] || m.jenis)}</td>
        <td class="num ${m.jumlah < 0 ? 'minus' : 'plus'}">${m.jumlah < 0 ? '−' : '+'}${rp(Math.abs(m.jumlah))}</td>
        <td class="num rp">${rp(m.titipan_sesudah)}</td>
        <td>${esc(m.operator)}</td><td class="muted">${esc(m.keterangan)}</td></tr>`).join('')
        || '<tr><td colspan="6" class="muted">Belum ada mutasi titipan untuk santri ini</td></tr>'}</tbody>
    </table></div>`;

  const segarkan = async () => {
    // Saldo kartu bisa berubah oleh tindakan di panel ini (pencairan), jadi
    // kartu santri di panel atas ikut disegarkan.
    const lagi = await api(`/api/siswa/${t.id}/titipan`);
    saldo.siswa = { ...saldo.siswa, saldo: lagi.siswa.saldo, saldo_titipan: lagi.siswa.saldo_titipan };
    renderSaldoSiswa();
    sdSync();
    loadPenyesuaian();
  };

  $('#tt-muat').onclick = muatPanelTitipan;
  $('#tt-setor').onclick = () => openModal(`
    <h2>Setor ke titipan</h2>
    <p class="muted"><b>${esc(t.nama)}</b> · titipan sekarang ${rp(t.saldo_titipan)}.
      Uang ini tercatat atas nama santri tetapi <b>belum bisa dibelanjakan</b> —
      smart top up yang akan memindahkannya ke kartu sedikit-sedikit.</p>
    <div class="field"><label>Nominal (Rp)</label>
      ${inputUang({ id: 'm-jumlah', placeholder: 'mis. 500.000' })}</div>
    <div class="field"><label>Keterangan</label>
      <input id="m-ket" placeholder="mis. setoran sebulan dari orang tua (tunai)"></div>`,
    async () => {
      const d2 = await api('/api/titipan/setor', { method: 'POST', body: {
        siswa_id: t.id, jumlah: nilaiUang('#m-jumlah'), keterangan: $('#m-ket').value,
        ref: `adm-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
      }});
      toast(d2.smart
        ? `✔ Titipan tersimpan — ${rp(d2.smart.isi)} langsung mengisi kartu`
        : '✔ Titipan tersimpan', 'ok');
      await segarkan();
      muatPanelTitipan();
    }, 'Setor');

  $('#tt-cair').onclick = () => openModal(`
    <h2>Cairkan titipan ke kartu</h2>
    <p class="muted"><b>${esc(t.nama)}</b> · titipan ${rp(t.saldo_titipan)}, saldo kartu ${rp(t.saldo)}.
      Kosongkan nominal untuk mencairkan sebanyak yang masih tertampung kartu.</p>
    <div class="field"><label>Nominal (Rp, opsional)</label>
      ${inputUang({ id: 'm-jumlah', placeholder: 'sebanyak mungkin' })}</div>`,
    async () => {
      const nilai = nilaiUang('#m-jumlah');
      const d2 = await api('/api/titipan/cairkan', { method: 'POST', body: {
        siswa_id: t.id, ...(nilai > 0 ? { jumlah: nilai } : {}) }});
      toast(`✔ ${rp(d2.isi)} dipindahkan ke kartu — saldo kini ${rp(d2.siswa.saldo)}`, 'ok');
      await segarkan();
      muatPanelTitipan();
    }, 'Cairkan');

  $('#tt-tarik').onclick = () => openModal(`
    <h2>Tarik titipan</h2>
    <p class="muted">Untuk uang yang benar-benar dikembalikan ke orang tua (mis. santri keluar/lulus).
      Titipan <b>${esc(t.nama)}</b> sekarang ${rp(t.saldo_titipan)}.</p>
    <div class="field"><label>Nominal (Rp)</label>
      ${inputUang({ id: 'm-jumlah', nilai: t.saldo_titipan })}</div>
    <div class="field"><label>Alasan (wajib, tercatat di buku besar titipan)</label>
      <input id="m-ket" placeholder="mis. dikembalikan tunai kepada wali"></div>`,
    async () => {
      await api('/api/titipan/tarik', { method: 'POST', body: {
        siswa_id: t.id, jumlah: nilaiUang('#m-jumlah'), keterangan: $('#m-ket').value }});
      toast('✔ Titipan ditarik', 'ok');
      await segarkan();
      muatPanelTitipan();
    }, 'Tarik titipan');

  $('#tt-smart').onclick = () => openModal(`
    <h2>Setelan smart top up — ${esc(t.nama)}</h2>
    <p class="muted">Kosongkan kedua nominal untuk mengikuti angka sekolah
      (${rp(b.ambang)} → ${rp(b.isi)}).</p>
    <div class="field"><label>Mode</label><select id="m-mode">
      <option value="ikut" ${t.smart_mode === 'ikut' ? 'selected' : ''}>ikut pengaturan sekolah</option>
      <option value="aktif" ${t.smart_mode === 'aktif' ? 'selected' : ''}>selalu nyala untuk santri ini</option>
      <option value="mati" ${t.smart_mode === 'mati' ? 'selected' : ''}>matikan untuk santri ini</option>
    </select></div>
    <div class="field"><label>Isi ulang saat saldo turun sampai (Rp)</label>
      ${inputUang({ id: 'm-ambang', nilai: t.smart_ambang ?? '',
        placeholder: `ikut sekolah (${b.ambang.toLocaleString('id-ID')})` })}</div>
    <div class="field"><label>Isi ulang sampai saldo menjadi (Rp)</label>
      ${inputUang({ id: 'm-isi', nilai: t.smart_isi ?? '',
        placeholder: `ikut sekolah (${b.isi.toLocaleString('id-ID')})` })}</div>`,
    async () => {
      // Kosong berarti "ikut sekolah", bukan nol — nilaiUang sudah membedakannya.
      const d2 = await api(`/api/siswa/${t.id}/smart`, { method: 'PUT', body: {
        mode: $('#m-mode').value, ambang: nilaiUang('#m-ambang'), isi: nilaiUang('#m-isi') }});
      toast(d2.smart ? `✔ Setelan tersimpan — kartu langsung terisi ${rp(d2.smart.isi)}`
        : '✔ Setelan tersimpan', 'ok');
      await segarkan();
      muatPanelTitipan();
    }, 'Simpan setelan');
}

/**
 * Biaya admin yang akan dipotong server bila "Biaya admin" dipilih — sama
 * dengan BiayaAdmin::untukManual: penimpa per santri menang, selain itu tarif
 * sekolah. Hanya pratinjau; angka pastinya tetap dihitung server.
 */
function sdBiaya(s, n) {
  if (!s || !n || $('#sd-biaya')?.value !== 'biaya') return 0;
  const tarif = s.biaya_admin != null ? Number(s.biaya_admin) : (saldo.tarif || 0);
  return Math.min(Math.max(0, tarif), n);
}

// Satu tempat untuk semua aturan tampilan: label, pratinjau, dan tombol.
function sdSync() {
  const potong = sdPotong() || sdKolektif();
  const s = saldo.siswa;
  const n = saldo.nominal;
  const ket = $('#sd-ket').value.trim();

  $('#sd-ket-label').textContent = potong ? 'Alasan pemotongan (wajib)' : 'Keterangan (opsional)';
  $('#sd-ket').placeholder = potong
    ? (sdKolektif() ? 'mis. Iuran kegiatan kelas' : 'mis. Penarikan tunai saldo')
    : 'mis. Setoran orang tua';
  // Pilihan titipan dan biaya admin hanya masuk akal pada top up satu santri.
  $('#sd-titipan-field').classList.toggle('hidden', potong);
  $('#sd-biaya-field').classList.toggle('hidden', potong);

  if (sdKolektif()) return sdSyncKolektif(n, ket);

  const biaya = potong ? 0 : sdBiaya(s, n);
  let pesan = '', ok = false;
  if (!s) pesan = 'Pilih santri dulu di panel sebelah kiri.';
  else if (!n || n <= 0) pesan = 'Isi nominal — pilih nominal cepat atau ketik sendiri.';
  else if (biaya > 0 && biaya >= n) pesan = `⚠️ Nominal harus lebih besar dari biaya admin (${rp(biaya)}).`;
  else if (potong && n > s.saldo) pesan = `⚠️ Melebihi saldo ${esc(s.nama)} (${rp(s.saldo)}).`;
  else if (potong && ket.length < 3) pesan = 'Alasan pemotongan wajib diisi (minimal 3 huruf).';
  else {
    ok = true;
    const masuk = n - biaya;
    const akhir = potong ? s.saldo - n : s.saldo + masuk;
    pesan = `<b>${esc(s.nama)}</b><br>Saldo ${rp(s.saldo)}
      <span class="${potong ? 'minus' : 'plus'}">${potong ? '−' : '+'}${rp(potong ? n : masuk)}</span>
      → <b class="rp">${rp(akhir)}</b>`;
    if (biaya > 0) pesan += `<br><span class="muted">Top up ${rp(n)} → masuk kartu ${rp(masuk)},
      biaya admin ${rp(biaya)}</span>`;
    else if (!potong && $('#sd-biaya').value === 'biaya' && s.biaya_admin === 0)
      pesan += `<br><span class="muted">${esc(s.nama)} dibebaskan dari biaya admin.</span>`;
    // Angka pastinya dihitung server (jatah harian ikut menentukan), jadi di sini
    // hanya diberi ancang-ancang berdasarkan batas saldo kartu.
    if (!potong && $('#sd-ke-titipan')?.checked) pesan +=
      '<br><span class="muted">Yang tidak tertampung kartu akan mengendap di saldo titipan.</span>';
  }
  $('#sd-preview').innerHTML = pesan;
  $('#sd-preview').className = 'preview' + (ok ? ' ok' : '');

  const btn = $('#btn-saldo');
  btn.textContent = potong ? `Potong Saldo${n ? ' ' + rp(n) : ''}`
    : `Top Up${n ? ' ' + rp(n) : ''}${biaya > 0 ? ` (biaya ${rp(biaya)})` : ''}`;
  btn.classList.toggle('danger', potong);
  btn.disabled = !ok;
}

// Ringkasan batch: siapa terpotong penuh, siapa sebagian, siapa dilewati.
function sdRingkasBatch() {
  const dipilih = saldo.batch.filter((s) => saldo.pilih.has(s.id));
  const kena = [], sebagian = [], lewat = [];
  for (const s of dipilih) {
    const e = sdPotongEfektif(s);
    if (e <= 0) lewat.push(s);
    else if (e < saldo.nominal) sebagian.push(s);
    else kena.push(s);
  }
  const total = dipilih.reduce((a, s) => a + sdPotongEfektif(s), 0);
  return { dipilih, kena, sebagian, lewat, total };
}

function sdSyncKolektif(n, ket) {
  const r = sdRingkasBatch();
  const kelas = [...new Set(r.dipilih.map((s) => s.kelas).filter(Boolean))];
  let pesan = '', ok = false;
  if (!saldo.batch.length) pesan = 'Tambahkan kelas/unit dulu di panel sebelah kiri.';
  else if (!r.dipilih.length) pesan = 'Belum ada yang dicentang.';
  else if (!n || n <= 0) pesan = 'Isi nominal yang dipotong dari tiap orang.';
  else if (r.total <= 0) pesan = '⚠️ Tidak ada saldo yang bisa dipotong — semua yang terpilih saldonya kurang.';
  else if (ket.length < 3) pesan = 'Alasan pemotongan wajib diisi (minimal 3 huruf).';
  else {
    ok = true;
    const kartu = r.dipilih.reduce((x, s) => x + sdBagi(s).kartu, 0);
    const titipan = r.dipilih.reduce((x, s) => x + sdBagi(s).titipan, 0);
    pesan = `<b>${r.dipilih.length} orang</b>${kelas.length ? ' · kelas/unit ' + esc(kelas.join(', ')) : ''}<br>
      ${rp(n)} × ${r.kena.length + r.sebagian.length} orang →
      total <b class="minus">−${rp(r.total)}</b>` +
      (titipan > 0 ? `<br><b>${rp(kartu)}</b> dari kartu · <b>${rp(titipan)}</b> dari titipan` : '') +
      (r.sebagian.length ? `<br><span class="muted">${r.sebagian.length} orang dipotong sebagian (saldo habis)</span>` : '') +
      (r.lewat.length ? `<br><span class="muted">${r.lewat.length} orang dilewati karena saldonya kurang</span>` : '');
  }
  $('#sd-preview').innerHTML = pesan;
  $('#sd-preview').className = 'preview' + (ok ? ' ok' : '');
  const btn = $('#btn-saldo');
  btn.textContent = ok ? `Potong ${r.kena.length + r.sebagian.length} Orang · ${rp(r.total)}` : 'Potong Kolektif';
  btn.classList.add('danger');
  btn.disabled = !ok;
}

async function doSaldo() {
  if (sdKolektif()) return doSaldoKolektif();
  const s = saldo.siswa;
  if (!s || !saldo.nominal) return;
  if (sdPotong()) {                       // pemotongan selalu dikonfirmasi dulu
    openModal(`
      <h2>Potong saldo ${esc(s.nama)}?</h2>
      <p>Saldo dipotong <b class="minus">${rp(saldo.nominal)}</b>, dari <b>${rp(s.saldo)}</b>
         menjadi <b>${rp(s.saldo - saldo.nominal)}</b>.</p>
      <p class="muted">Alasan: “${esc($('#sd-ket').value.trim())}”.<br>
        Tercatat atas nama <b>${esc(USER.nama)}</b> dan tidak bisa dihapus — pembetulan
        dilakukan dengan top up balik.</p>`,
      () => kirimSaldo(), 'Ya, potong saldo');
    return;
  }
  $('#btn-saldo').disabled = true;
  try { await kirimSaldo(); } catch (e) { toast(e.message, 'err'); sdSync(); }
}

// Melempar error supaya modal konfirmasi tetap terbuka saat gagal.
async function kirimSaldo() {
  const potong = sdPotong();
  const nominal = saldo.nominal;
  const keTitipan = !potong && !!$('#sd-ke-titipan')?.checked;
  const pungut = !potong && $('#sd-biaya').value === 'biaya';
  const d = await api(potong ? '/api/koreksi' : '/api/topup', { method: 'POST', body: {
    siswa_id: saldo.siswa.id,
    jumlah: nominal,
    keterangan: $('#sd-ket').value.trim(),
    ...(potong ? { sumber: $('#sd-sumber')?.value || 'kartu' } : {}),
    ...(keTitipan ? { ke_titipan: true } : {}),
    ...(pungut ? { biaya_admin: true } : {}),
    tx_id: 'adm-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8),
  }});
  saldo.siswa = d.siswa;
  saldo.nominal = null;
  $('#sd-jumlah').value = '';
  $('#sd-ket').value = '';
  document.querySelectorAll('#sd-quick button').forEach((x) => x.classList.remove('sel'));
  renderSaldoSiswa();
  sdSync();
  loadPenyesuaian();
  if (d.ke_titipan > 0) muatPanelTitipan();
  const rincian = (d.ke_titipan > 0
    ? ` (${rp(d.ke_saldo)} ke kartu, ${rp(d.ke_titipan)} ke titipan)` : '') +
    (d.biaya > 0 ? ` · biaya admin ${rp(d.biaya)}` : '');
  toast(`✔ ${potong ? 'Saldo dipotong' : 'Top up'} ${rp(nominal)}${rincian} — ` +
    `saldo ${d.siswa.nama} kini ${rp(d.siswa.saldo)}`, 'ok');
}

function doSaldoKolektif() {
  const r = sdRingkasBatch();
  const n = saldo.nominal;
  if (!r.dipilih.length || !n || r.total <= 0) return;
  const kelas = [...new Set(r.dipilih.map((s) => s.kelas).filter(Boolean))];
  const alasan = $('#sd-ket').value.trim();
  const sebisanya = $('#sd-kurang').value === 'sebisanya';
  openModal(`
    <h2>Potong saldo ${r.kena.length + r.sebagian.length} orang?</h2>
    <p>${rp(n)} per orang${kelas.length ? ` · kelas/unit <b>${esc(kelas.join(', '))}</b>` : ''} —
       total <b class="minus">${rp(r.total)}</b> ditarik dari saldonya.</p>
    ${r.sebagian.length ? `<p class="muted">${r.sebagian.length} orang saldonya kurang dan dipotong sebagian (sampai saldo 0).</p>` : ''}
    ${r.lewat.length ? `<p class="muted">${r.lewat.length} orang dilewati karena saldonya kurang — saldonya tidak berubah.</p>` : ''}
    <p class="muted">Alasan: “${esc(alasan)}”.<br>
      Tercatat satu per satu atas nama <b>${esc(USER.nama)}</b> di riwayat tiap orang
      dan tidak bisa dihapus — pembetulan dilakukan dengan top up balik.</p>`,
    async () => {
      const d = await api('/api/koreksi-massal', { method: 'POST', body: {
        siswa_ids: r.dipilih.map((s) => s.id),
        jumlah: n,
        sumber: $('#sd-sumber')?.value || 'kartu',
        keterangan: (kelas.length ? `[Kolektif ${kelas.join(', ')}] ` : '[Kolektif] ') + alasan,
        saldo_kurang: sebisanya ? 'sebisanya' : 'lewati',
        batch_id: 'btc-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8),
      }});
      // saldo lokal disegarkan dari hasil server, bukan dihitung ulang di layar
      const baru = new Map(d.berhasil.map((x) => [x.id, x.saldo_sesudah]));
      saldo.batch = saldo.batch.map((s) => (baru.has(s.id) ? { ...s, saldo: baru.get(s.id) } : s));
      saldo.nominal = null;
      $('#sd-jumlah').value = '';
      $('#sd-ket').value = '';
      document.querySelectorAll('#sd-quick button').forEach((x) => x.classList.remove('sel'));
      sdRenderBatch();
      sdSync();
      loadPenyesuaian();
      toast(`✔ ${d.berhasil.length} orang dipotong — total ${rp(d.total_dipotong)}`, 'ok');
      setTimeout(() => hasilKolektifModal(d), 250);
    }, 'Ya, potong kolektif');
}

function hasilKolektifModal(d) {
  openModal(`
    <h2>Hasil Pemotongan Kolektif</h2>
    <div class="cards" style="margin-bottom:12px">
      <div class="statcard"><span class="muted">Berhasil dipotong</span><br><b>${d.berhasil.length} orang</b></div>
      <div class="statcard"><span class="muted">Total ditarik</span><br><b class="rp">${rp(d.total_dipotong)}</b></div>
    </div>
    ${d.dilewati.length ? `
      <p class="muted">${d.dilewati.length} orang dilewati — saldonya tidak berubah:</p>
      <div class="tblwrap" style="max-height:220px; overflow:auto"><table>
        <thead><tr><th>Nama</th><th>Kelas/Unit</th><th class="num">Saldo</th><th>Alasan</th></tr></thead>
        <tbody>${d.dilewati.map((s) => `<tr><td>${esc(s.nama)}</td><td>${esc(s.kelas)}</td>
          <td class="num rp">${rp(s.saldo)}</td><td class="muted">${esc(s.alasan)}</td></tr>`).join('')}</tbody>
      </table></div>`
    : '<p class="muted">Semua yang terpilih berhasil dipotong.</p>'}`);
  $('#m-cancel').textContent = 'Tutup';     // modal ini hanya melaporkan hasil
}

async function loadPenyesuaian() {
  const body = $('#sd-log');
  if (!body) return;
  try {
    const d = await api('/api/transaksi?tipe=topup,koreksi&limit=10');
    body.innerHTML = d.rows.map((t) => {
      const wkt = new Date(t.created_at).toLocaleString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
      return `<tr>
        <td style="white-space:nowrap">${wkt}</td>
        <td>${esc(t.siswa_nama)}</td><td>${esc(t.kelas)}</td>
        <td>${t.tipe === 'topup' ? 'top up' : 'potong'}</td>
        <td class="num ${t.jumlah >= 0 ? 'plus' : 'minus'}">${t.jumlah >= 0 ? '+' : '−'}${rp(Math.abs(t.jumlah))}</td>
        <td class="num rp">${rp(t.saldo_sesudah)}</td>
        <td>${esc(t.operator)}</td><td class="muted">${esc(t.keterangan)}</td>
      </tr>`;
    }).join('') || '<tr><td colspan="8" class="muted">Belum ada penyesuaian saldo</td></tr>';
  } catch (e) {
    body.innerHTML = `<tr><td colspan="8" class="muted">${esc(e.message)}</td></tr>`;
  }
}

// ╔══════════════════════════════════════════════════════════╗
// ║ SUPERADMIN: DONASI (jenis, besaran, kios, rekap)           ║
// ╚══════════════════════════════════════════════════════════╝
// Yang diatur di sini menentukan apa yang muncul di layar kios siswa:
// jenis donasi beserta PILIHAN BESARANNYA. Santri tidak pernah bisa mengetik
// nominal sendiri — server hanya menerima angka yang ada di daftar ini.
let donasiRows = [];        // salinan yang sedang disunting di layar

function donasiView(el) {
  el.innerHTML = `
  <div class="panel">
    <div class="kat-head">
      <h2>Rekap Donasi</h2>
      <div class="quick" id="dn-rentang">
        <button data-hari="7">7 hari</button>
        <button data-hari="30" class="sel">30 hari</button>
        <button data-hari="365">1 tahun</button>
      </div>
    </div>
    <div class="cards" id="dn-kartu"></div>
    <div class="tblwrap"><table>
      <thead><tr><th>Jenis donasi</th><th class="num">Periode</th><th class="num">Hari ini</th>
        <th class="num">Total</th><th class="num">Donatur</th><th class="num">Transaksi</th></tr></thead>
      <tbody id="dn-perprogram"></tbody>
    </table></div>
  </div>

  <div class="row">
    <div class="panel">
      <div class="kat-head">
        <h2>Jenis &amp; Besaran Donasi</h2>
        <button class="linkbtn" id="dn-tambah">+ Tambah jenis donasi</button>
      </div>
      <p class="muted">Yang tampil di kios santri hanya yang dicentang <b>tampil</b>.
        Nominal ditulis dipisah koma — itulah tombol yang bisa dipilih santri.
        Jenis donasi yang sudah pernah menerima donasi tidak bisa dihapus
        (jejak keuangan), tapi boleh disembunyikan.</p>
      <div id="dn-baris"></div>
      <button class="paybtn" id="dn-simpan">Simpan daftar donasi</button>
    </div>

    <div class="panel">
      <h2>Kios Santri</h2>
      <p class="muted">Layar kios dibuka di alamat <a href="/kios" target="_blank"><code>/kios</code></a> —
        pasang di perangkat yang tercolok reader. Santri cukup tap untuk cek saldo,
        dan tap + PIN untuk berdonasi. PIN bawaan setiap santri adalah NIS-nya;
        santri yang lupa PIN harus direset lewat menu <b>Santri</b>.</p>
      <div class="field"><label>Layanan kios</label>
        <select id="dn-aktif"><option value="1">Aktif — kios melayani tap</option>
          <option value="0">Tutup — kios menolak semua tap</option></select></div>
      <div class="field"><label>Nominal donasi terkecil yang boleh didaftarkan</label>
        ${inputUang({ id: 'dn-min' })}</div>
      <div class="field"><label>Maksimal donasi per santri per hari</label>
        ${inputUang({ id: 'dn-maks' })}</div>
      <button class="paybtn" id="dn-simpan-set">Simpan pengaturan kios</button>

      <hr>
      <h3>Sandi mode layar penuh</h3>
      <p class="muted">Dipakai staf untuk keluar dari mode kios di layar
        <code>/kios</code>. Sandi bawaan <b>rahasia123</b> — ganti sebelum kios
        dipakai sungguhan. Sandinya tidak pernah dikirim ke layar kios, jadi tidak
        bisa dibaca dari sumber halaman.
        <b>Catatan:</b> tombol Esc/F11 tetap milik peramban dan tidak bisa ditahan
        halaman; kunci sungguhan dipasang lewat cara peramban dijalankan
        (<code>chrome --kiosk</code>).</p>
      <p id="dn-sandi-status" class="muted"></p>
      <div class="field"><label>Sandi baru (min. 6 karakter)</label>
        <input id="dn-sandi" type="password" autocomplete="new-password" placeholder="sandi baru"></div>
      <button class="paybtn" id="dn-simpan-sandi">Ganti sandi kios</button>
    </div>
  </div>

  <div class="panel">
    <h2>20 Donasi Terakhir</h2>
    <div class="tblwrap"><table>
      <thead><tr><th>Waktu</th><th>Santri</th><th>Kelas</th><th>Jenis</th><th class="num">Jumlah</th></tr></thead>
      <tbody id="dn-terakhir"></tbody>
    </table></div>
  </div>`;

  $('#dn-rentang').onclick = (e) => {
    const b = e.target.closest('button[data-hari]');
    if (!b) return;
    document.querySelectorAll('#dn-rentang button').forEach((x) => x.classList.toggle('sel', x === b));
    muatRekapDonasi(Number(b.dataset.hari));
  };
  $('#dn-tambah').onclick = () => {
    donasiRows.push({ id: null, nama: '', deskripsi: '', nominal: '', aktif: true, terpakai: 0 });
    dnGambarBaris();
    const kotak = document.querySelectorAll('#dn-baris .dn-nama');
    kotak[kotak.length - 1]?.focus();
  };
  $('#dn-simpan').onclick = simpanProgramDonasi;
  $('#dn-simpan-set').onclick = simpanPengaturanKios;
  $('#dn-simpan-sandi').onclick = simpanSandiKios;

  muatProgramDonasi();
  muatPengaturanKios();
  muatRekapDonasi(30);
}

async function muatProgramDonasi() {
  try {
    const d = await api('/api/donasi/program?semua=1');
    donasiRows = d.program.map((p) => ({
      id: p.id, nama: p.nama, deskripsi: p.deskripsi,
      nominal: teksDaftarUang(p.nominal), aktif: p.aktif === 1,
      terpakai: p.terpakai, terkumpul: p.terkumpul,
    }));
    dnGambarBaris();
  } catch (e) { toast(e.message, 'err'); }
}

function dnGambarBaris() {
  $('#dn-baris').innerHTML = donasiRows.map((r, i) => `
    <div class="dn-row">
      <div class="dn-row-atas">
        <input class="dn-nama" data-i="${i}" value="${esc(r.nama)}" maxlength="60" placeholder="nama jenis donasi">
        <label class="kat-aktif" title="Tampilkan di kios santri">
          <input type="checkbox" class="dn-aktif" data-i="${i}" ${r.aktif ? 'checked' : ''}> tampil
        </label>
        <button class="linkbtn" data-naik="${i}" ${i === 0 ? 'disabled' : ''} title="naikkan">↑</button>
        <button class="linkbtn" data-turun="${i}" ${i === donasiRows.length - 1 ? 'disabled' : ''} title="turunkan">↓</button>
        <button class="linkbtn danger" data-hapus="${i}" title="${r.terpakai ? 'sudah ada donasi tercatat — tidak bisa dihapus' : 'hapus'}"
          ${r.terpakai ? 'disabled' : ''}>✕</button>
      </div>
      <input class="dn-desk" data-i="${i}" value="${esc(r.deskripsi)}" maxlength="200"
        placeholder="keterangan singkat (tampil di kios)">
      <input class="dn-nominal uang-daftar" data-i="${i}" value="${esc(r.nominal)}" inputmode="numeric"
        autocomplete="off" placeholder="pilihan nominal, mis. 2.000 / 5.000 / 10.000">
      ${r.terpakai ? `<span class="muted">sudah menerima ${r.terpakai} donasi · terkumpul ${rp(r.terkumpul)}</span>` : ''}
    </div>`).join('') || '<p class="muted">Belum ada jenis donasi. Tambahkan di atas.</p>';

  const ikat = (sel, isi) => $('#dn-baris').querySelectorAll(sel).forEach((inp) => {
    inp.oninput = () => isi(donasiRows[Number(inp.dataset.i)], inp);
  });
  ikat('.dn-nama', (r, inp) => { r.nama = inp.value; });
  ikat('.dn-desk', (r, inp) => { r.deskripsi = inp.value; });
  ikat('.dn-nominal', (r, inp) => { r.nominal = inp.value; });
  $('#dn-baris').querySelectorAll('.dn-aktif').forEach((c) => {
    c.onchange = () => { donasiRows[Number(c.dataset.i)].aktif = c.checked; };
  });
  $('#dn-baris').querySelectorAll('[data-naik]').forEach((b) => {
    b.onclick = () => { const i = Number(b.dataset.naik); [donasiRows[i - 1], donasiRows[i]] = [donasiRows[i], donasiRows[i - 1]]; dnGambarBaris(); };
  });
  $('#dn-baris').querySelectorAll('[data-turun]').forEach((b) => {
    b.onclick = () => { const i = Number(b.dataset.turun); [donasiRows[i + 1], donasiRows[i]] = [donasiRows[i], donasiRows[i + 1]]; dnGambarBaris(); };
  });
  $('#dn-baris').querySelectorAll('[data-hapus]').forEach((b) => {
    b.onclick = () => { donasiRows.splice(Number(b.dataset.hapus), 1); dnGambarBaris(); };
  });
}

async function simpanProgramDonasi() {
  const btn = $('#dn-simpan');
  btn.disabled = true;
  try {
    const kirim = donasiRows
      .filter((r) => r.nama.trim() || r.nominal.trim())
      .map((r) => ({
        id: r.id, nama: r.nama.trim(), deskripsi: r.deskripsi.trim(), aktif: r.aktif,
        nominal: daftarUang(r.nominal),
      }));
    for (const p of kirim) {
      if (p.nama.length < 3) throw new Error('Nama jenis donasi minimal 3 huruf');
      if (!p.nominal.length) throw new Error(`"${p.nama}" belum punya pilihan nominal`);
      if (p.nominal.some((n) => !n)) throw new Error(`Ada nominal yang tidak terbaca di "${p.nama}"`);
    }
    await api('/api/donasi/program', { method: 'PUT', body: { program: kirim } });
    toast('✔ Daftar donasi disimpan', 'ok');
    muatProgramDonasi();
    muatRekapDonasi(Number(document.querySelector('#dn-rentang .sel')?.dataset.hari) || 30);
  } catch (e) { toast(e.message, 'err'); }
  btn.disabled = false;
}

async function muatPengaturanKios() {
  try {
    const d = await api('/api/donasi/pengaturan');
    $('#dn-aktif').value = String(d.pengaturan.kios_aktif);
    isiUang('#dn-min', d.pengaturan.donasi_min);
    isiUang('#dn-maks', d.pengaturan.donasi_maks_harian);
    $('#dn-sandi-status').innerHTML = d.sandi_bawaan
      ? '⚠️ Masih memakai sandi bawaan <b>rahasia123</b>.'
      : '✔ Sandi sudah diganti dari bawaan.';
  } catch (e) { toast(e.message, 'err'); }
}

async function simpanSandiKios() {
  const btn = $('#dn-simpan-sandi');
  btn.disabled = true;
  try {
    await api('/api/kios/sandi-layar', { method: 'PUT', body: { sandi_baru: $('#dn-sandi').value } });
    $('#dn-sandi').value = '';
    toast('✔ Sandi kios diganti', 'ok');
    muatPengaturanKios();
  } catch (e) { toast(e.message, 'err'); }
  btn.disabled = false;
}

async function simpanPengaturanKios() {
  const btn = $('#dn-simpan-set');
  btn.disabled = true;
  try {
    await api('/api/donasi/pengaturan', { method: 'PUT', body: {
      kios_aktif: Number($('#dn-aktif').value),
      donasi_min: nilaiUang('#dn-min'),
      donasi_maks_harian: nilaiUang('#dn-maks'),
    } });
    toast('✔ Pengaturan kios disimpan', 'ok');
  } catch (e) { toast(e.message, 'err'); }
  btn.disabled = false;
}

async function muatRekapDonasi(hari) {
  try {
    const d = await api('/api/donasi/rekap?hari=' + hari);
    $('#dn-kartu').innerHTML = [
      ['Terkumpul seluruhnya', rp(d.total)],
      [`Terkumpul ${hari} hari`, rp(d.periode)],
      ['Hari ini', rp(d.hari_ini)],
      ['Santri berdonasi', `${d.donatur} santri · ${d.n} transaksi`],
    ].map(([l, v]) => `<div class="statcard"><span class="muted">${l}</span><br><b class="rp">${v}</b></div>`).join('');

    $('#dn-perprogram').innerHTML = d.per_program.map((p) => `
      <tr>
        <td><b>${esc(p.nama)}</b> ${p.aktif ? '' : '<span class="badge nonaktif">disembunyikan</span>'}</td>
        <td class="num rp">${rp(p.periode)}</td><td class="num rp">${rp(p.hari_ini)}</td>
        <td class="num rp">${rp(p.total)}</td><td class="num">${p.donatur}</td><td class="num">${p.n}</td>
      </tr>`).join('') || '<tr><td colspan="6" class="muted">Belum ada jenis donasi</td></tr>';

    $('#dn-terakhir').innerHTML = d.terakhir.map((t) => `
      <tr>
        <td style="white-space:nowrap">${new Date(t.created_at).toLocaleString('id-ID',
          { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}</td>
        <td>${esc(t.siswa_nama)}</td><td>${esc(t.kelas)}</td>
        <td class="muted">${esc(t.keterangan)}</td>
        <td class="num rp">${rp(-t.jumlah)}</td>
      </tr>`).join('') || '<tr><td colspan="5" class="muted">Belum ada donasi masuk</td></tr>';
  } catch (e) { toast(e.message, 'err'); }
}

// ╔══════════════════════════════════════════════════════════╗
// ║ SUPERADMIN: TABUNGAN (jenis & target, setor/cairkan, rekap) ║
// ╚══════════════════════════════════════════════════════════╝
// Beda dari Donasi: uang di sini tetap milik santri yang sama dan harus bisa
// kembali ke kartunya — menabung bisa santri sendiri lewat kios (kartu saja),
// tapi menyetor besar & mencairkan SENGAJA hanya lewat admin di layar ini.
let tabunganRows = [];
let tabunganSantri = null;    // santri yang sedang dipilih di panel Setor & Cairkan

function tabunganView(el) {
  el.innerHTML = `
  <div class="panel">
    <div class="kat-head">
      <h2>Rekap Tabungan</h2>
    </div>
    <div class="cards" id="tb-kartu"></div>
    <div class="tblwrap"><table>
      <thead><tr><th>Jenis tabungan</th><th class="num">Target</th><th class="num">Terkumpul</th>
        <th class="num">Penabung</th></tr></thead>
      <tbody id="tb-perjenis"></tbody>
    </table></div>
  </div>

  <div class="row">
    <div class="panel">
      <div class="kat-head">
        <h2>Jenis &amp; Target Tabungan</h2>
        <button class="linkbtn" id="tb-tambah">+ Tambah jenis tabungan</button>
      </div>
      <p class="muted">Yang tampil di kios santri hanya yang dicentang <b>tampil</b>.
        Target boleh dikosongkan (bebas tanpa batas) atau diisi (tampil progres
        terkumpul/target di kios). Jenis yang sudah punya riwayat tidak bisa
        dihapus, hanya disembunyikan.</p>
      <div id="tb-baris"></div>
      <button class="paybtn" id="tb-simpan">Simpan daftar tabungan</button>

      <hr>
      <h3>Pengaturan menabung lewat kios</h3>
      <div class="field"><label>Nominal menabung terkecil (kios)</label>
        ${inputUang({ id: 'tb-min' })}</div>
      <div class="field"><label>Maksimal menabung per santri per hari (kios)</label>
        ${inputUang({ id: 'tb-maks' })}</div>
      <button class="paybtn" id="tb-simpan-set">Simpan pengaturan</button>
    </div>

    <div class="panel">
      <h2>Setor &amp; Cairkan (dibantu admin)</h2>
      <p class="muted">Untuk kontribusi besar sekali waktu (boleh diambil dari kartu
        <i>dan</i> titipan), atau mencairkan tabungan kembali ke kartu. Menabung
        rutin kecil-kecil cukup lewat kios — santri tap kartu sendiri.</p>
      <div class="field"><input id="tb-cari" placeholder="cari nama/NIS santri…" autocomplete="off"></div>
      <div id="tb-hasil-cari"></div>
      <div id="tb-panel-santri"></div>
    </div>
  </div>`;

  $('#tb-tambah').onclick = () => {
    tabunganRows.push({ id: null, nama: '', deskripsi: '', target: '', aktif: true, terpakai: 0 });
    tbGambarBaris();
    const kotak = document.querySelectorAll('#tb-baris .tb-nama');
    kotak[kotak.length - 1]?.focus();
  };
  $('#tb-simpan').onclick = simpanJenisTabungan;
  $('#tb-simpan-set').onclick = simpanPengaturanTabungan;
  $('#tb-cari').addEventListener('input', debounce(tbCariSantri, 300));

  muatJenisTabungan();
  muatPengaturanTabungan();
  muatRekapTabungan();
}

async function muatJenisTabungan() {
  try {
    const d = await api('/api/tabungan/jenis?semua=1');
    tabunganRows = d.jenis.map((j) => ({
      id: j.id, nama: j.nama, deskripsi: j.deskripsi,
      target: j.target === null ? '' : String(j.target), aktif: j.aktif === 1,
      terpakai: j.terpakai, terkumpul: j.terkumpul,
    }));
    tbGambarBaris();
  } catch (e) { toast(e.message, 'err'); }
}

function tbGambarBaris() {
  $('#tb-baris').innerHTML = tabunganRows.map((r, i) => `
    <div class="dn-row">
      <div class="dn-row-atas">
        <input class="tb-nama" data-i="${i}" value="${esc(r.nama)}" maxlength="60" placeholder="nama jenis tabungan">
        <label class="kat-aktif" title="Tampilkan di kios santri">
          <input type="checkbox" class="tb-aktif" data-i="${i}" ${r.aktif ? 'checked' : ''}> tampil
        </label>
        <button class="linkbtn danger" data-hapus="${i}"
          title="${r.terpakai ? 'sudah ada riwayat tabungan — tidak bisa dihapus' : 'hapus'}"
          ${r.terpakai ? 'disabled' : ''}>✕</button>
      </div>
      <input class="tb-desk" data-i="${i}" value="${esc(r.deskripsi)}" maxlength="200"
        placeholder="keterangan singkat (tampil di kios)">
      <input class="tb-target uang-daftar" data-i="${i}" value="${esc(r.target)}" inputmode="numeric"
        autocomplete="off" placeholder="target (opsional, mis. 500.000 — kosongkan untuk bebas)">
      ${r.terpakai ? `<span class="muted">sudah dipakai ${r.terpakai} santri · terkumpul ${rp(r.terkumpul)}</span>` : ''}
    </div>`).join('') || '<p class="muted">Belum ada jenis tabungan. Tambahkan di atas.</p>';

  const ikat = (sel, isi) => $('#tb-baris').querySelectorAll(sel).forEach((inp) => {
    inp.oninput = () => isi(tabunganRows[Number(inp.dataset.i)], inp);
  });
  ikat('.tb-nama', (r, inp) => { r.nama = inp.value; });
  ikat('.tb-desk', (r, inp) => { r.deskripsi = inp.value; });
  ikat('.tb-target', (r, inp) => { r.target = inp.value; });
  $('#tb-baris').querySelectorAll('.tb-aktif').forEach((c) => {
    c.onchange = () => { tabunganRows[Number(c.dataset.i)].aktif = c.checked; };
  });
  $('#tb-baris').querySelectorAll('[data-hapus]').forEach((b) => {
    b.onclick = () => { tabunganRows.splice(Number(b.dataset.hapus), 1); tbGambarBaris(); };
  });
}

async function simpanJenisTabungan() {
  const btn = $('#tb-simpan');
  btn.disabled = true;
  try {
    const kirim = tabunganRows
      .filter((r) => r.nama.trim())
      .map((r) => ({
        id: r.id, nama: r.nama.trim(), deskripsi: r.deskripsi.trim(), aktif: r.aktif,
        target: r.target.trim() === '' ? null : Number(digitUang(r.target)),
      }));
    for (const j of kirim) {
      if (j.nama.length < 3) throw new Error('Nama jenis tabungan minimal 3 huruf');
    }
    await api('/api/tabungan/jenis', { method: 'PUT', body: { jenis: kirim } });
    toast('✔ Daftar tabungan disimpan', 'ok');
    muatJenisTabungan();
    muatRekapTabungan();
  } catch (e) { toast(e.message, 'err'); }
  btn.disabled = false;
}

async function muatPengaturanTabungan() {
  try {
    const d = await api('/api/tabungan/pengaturan');
    isiUang('#tb-min', d.pengaturan.tabungan_min);
    isiUang('#tb-maks', d.pengaturan.tabungan_maks_harian);
  } catch (e) { toast(e.message, 'err'); }
}

async function simpanPengaturanTabungan() {
  const btn = $('#tb-simpan-set');
  btn.disabled = true;
  try {
    await api('/api/tabungan/pengaturan', { method: 'PUT', body: {
      tabungan_min: nilaiUang('#tb-min'),
      tabungan_maks_harian: nilaiUang('#tb-maks'),
    } });
    toast('✔ Pengaturan tabungan disimpan', 'ok');
  } catch (e) { toast(e.message, 'err'); }
  btn.disabled = false;
}

async function muatRekapTabungan() {
  try {
    const d = await api('/api/tabungan/rekap');
    $('#tb-kartu').innerHTML = [
      ['Terkumpul seluruhnya (semua jenis)', rp(d.total)],
    ].map(([l, v]) => `<div class="statcard"><span class="muted">${l}</span><br><b class="rp">${v}</b></div>`).join('');

    $('#tb-perjenis').innerHTML = d.per_jenis.map((j) => `
      <tr>
        <td><b>${esc(j.nama)}</b> ${j.aktif ? '' : '<span class="badge nonaktif">disembunyikan</span>'}</td>
        <td class="num rp">${j.target === null ? '<span class="muted">bebas</span>' : rp(j.target)}</td>
        <td class="num rp">${rp(j.total)}</td>
        <td class="num">${j.n_penabung}</td>
      </tr>`).join('') || '<tr><td colspan="4" class="muted">Belum ada jenis tabungan</td></tr>';
  } catch (e) { toast(e.message, 'err'); }
}

async function tbCariSantri() {
  const q = $('#tb-cari').value.trim();
  const box = $('#tb-hasil-cari');
  if (q.length < 2) { box.innerHTML = ''; return; }
  try {
    const d = await api('/api/siswa?limit=8&golongan=semua&cari=' + encodeURIComponent(q));
    box.innerHTML = d.siswa.map((s) => `
      <button class="linkbtn" style="display:block; padding:3px 0" data-id="${s.id}">
        ${esc(s.nama)} (${esc(s.kelas || '—')})</button>`).join('') || '<p class="muted">Tidak ada yang cocok</p>';
    box.querySelectorAll('button').forEach((b) => {
      b.onclick = () => {
        tabunganSantri = d.siswa.find((s) => s.id === Number(b.dataset.id));
        $('#tb-cari').value = '';
        box.innerHTML = '';
        tbRenderPanelSantri();
      };
    });
  } catch (e) { toast(e.message, 'err'); }
}

async function tbRenderPanelSantri() {
  const box = $('#tb-panel-santri');
  if (!tabunganSantri) { box.innerHTML = ''; return; }
  box.innerHTML = '<p class="muted">memuat…</p>';
  let d;
  try { d = await api('/api/tabungan/saldo/' + tabunganSantri.id); }
  catch (e) { toast(e.message, 'err'); return; }

  const jenisAktif = tabunganRows.filter((r) => r.aktif && r.id);
  box.innerHTML = `
    <div class="stucard" style="margin:10px 0">
      <div><b>${esc(d.siswa.nama)}</b><br><span class="muted">${esc(d.siswa.kelas || '—')}</span></div>
      <div class="saldo"><span class="muted">Saldo kartu</span><br><b class="rp">${rp(d.siswa.saldo)}</b>
        ${d.siswa.saldo_titipan > 0 ? `<br><span class="muted" style="font-size:11px">titipan ${rp(d.siswa.saldo_titipan)}</span>` : ''}</div>
    </div>
    ${d.tabungan.length ? `<div class="tblwrap"><table>
      <thead><tr><th>Jenis</th><th class="num">Saldo tabungan</th><th></th></tr></thead>
      <tbody>${d.tabungan.map((t) => `
        <tr><td>${esc(t.nama)}</td><td class="num rp">${rp(t.saldo)}</td>
          <td><button class="linkbtn danger" data-cairkan="${t.jenis_id}">cairkan</button></td></tr>`).join('')}</tbody>
    </table></div>` : '<p class="muted">Belum punya saldo tabungan apa pun.</p>'}

    <h3 style="margin-top:14px">Setor tabungan</h3>
    <div class="field"><label>Jenis</label><select id="tb-s-jenis">
      ${jenisAktif.map((r) => `<option value="${r.id}">${esc(r.nama)}</option>`).join('') || '<option value="">(belum ada jenis aktif)</option>'}
    </select></div>
    <div class="field">${inputUang({ id: 'tb-s-jumlah', placeholder: 'Nominal (Rp)' })}</div>
    <div class="field"><label>Sumber</label><select id="tb-s-sumber">
      <option value="kartu">Kartu saja — titipan tidak disentuh</option>
      <option value="kartu_titipan">Kartu dulu, sisanya dari titipan</option>
      <option value="titipan_kartu">Titipan dulu, sisanya dari kartu</option>
    </select></div>
    <div class="field"><input id="tb-s-ket" placeholder="keterangan (opsional)" maxlength="300"></div>
    <button class="paybtn" id="tb-s-submit">Setor</button>`;

  $('#tb-s-submit').onclick = async () => {
    const btn = $('#tb-s-submit');
    const jenisId = Number($('#tb-s-jenis').value);
    const jumlah = nilaiUang('#tb-s-jumlah');
    if (!jenisId) { toast('Belum ada jenis tabungan aktif', 'err'); return; }
    if (!jumlah || jumlah <= 0) { toast('Isi nominal dulu', 'err'); return; }
    btn.disabled = true;
    try {
      await api('/api/tabungan/setor', { method: 'POST', body: {
        siswa_id: tabunganSantri.id, jenis_id: jenisId, jumlah,
        sumber: $('#tb-s-sumber').value, keterangan: $('#tb-s-ket').value,
        tx_id: 'tb-setor-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8),
      } });
      toast('✔ Tabungan disetor', 'ok');
      tbRenderPanelSantri();
      muatRekapTabungan();
    } catch (e) { toast(e.message, 'err'); }
    btn.disabled = false;
  };

  box.querySelectorAll('[data-cairkan]').forEach((b) => {
    b.onclick = () => tbCairkanModal(Number(b.dataset.cairkan));
  });
}

function tbCairkanModal(jenisId) {
  const nama = tabunganRows.find((r) => r.id === jenisId)?.nama || '';
  openModal(`
    <h2>Cairkan Tabungan</h2>
    <p>Seluruh saldo tabungan <b>${esc(nama)}</b> milik <b>${esc(tabunganSantri.nama)}</b>
      akan dicairkan ke kartu (sisanya ke titipan kalau melebihi plafon kartu).</p>
    <div class="field"><label>Alasan pencairan (wajib)</label>
      <input id="tb-c-ket" placeholder="mis. Study tour sudah berangkat" maxlength="300"></div>`,
    async () => {
      await api('/api/tabungan/cairkan', { method: 'POST', body: {
        siswa_id: tabunganSantri.id, jenis_id: jenisId, keterangan: $('#tb-c-ket').value,
        tx_id: 'tb-cair-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8),
      } });
      toast('✔ Tabungan dicairkan', 'ok');
      tbRenderPanelSantri();
      muatRekapTabungan();
    }, 'Ya, cairkan');
}

// ╔══════════════════════════════════════════════════════════╗
// ║ SUPERADMIN: SANTRI                                         ║
// ╚══════════════════════════════════════════════════════════╝
let siswaState = { page: 1 };

/**
 * Daftar golongan, disegarkan tiap kali daftar pemegang kartu dimuat.
 * Disimpan di satu tempat karena tiga layar memakainya (daftar, formulir, dan
 * pengelolaan golongan) dan ketiganya harus melihat daftar yang sama.
 */
let GOLONGAN = [];
const golDari = (kode) => GOLONGAN.find((g) => g.kode === kode) || { kode, nama: kode, label_unit: 'Kelas' };

/**
 * Menu SDM & Personil dan menu Santri adalah LAYAR YANG SAMA dengan saringan
 * golongan berbeda. Sengaja tidak digandakan: keduanya butuh tap-kartu, reset
 * PIN, hubungkan kartu, blokir, dan tombol saldo yang persis sama — menyalinnya
 * berarti dua tempat yang harus diperbaiki setiap kali salah satunya berubah.
 */
function sdmView(el) { siswaView(el, true); }

function siswaView(el, sdm) {
  siswaState = { page: 1, sdm: !!sdm, urut: 'nama', arah: 'asc' };
  const t = ISTILAH(sdm);
  const judul = sdm ? 'SDM &amp; Personil Khusus' : 'Data Santri';
  const bolehImpor = boleh(`${sdm ? 'sdm' : 'santri'}.impor`);
  const bolehTambah = boleh(`${sdm ? 'sdm' : 'santri'}.tambah`);
  const bolehArsip = boleh(`${sdm ? 'sdm' : 'santri'}.arsip`);
  const bolehKartu = boleh(`${sdm ? 'sdm' : 'santri'}.kartu`);
  el.innerHTML = `<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:8px">
      <h2 style="margin:0">${judul} <span class="muted" id="sw-count"></span></h2>
      <div style="display:flex; gap:10px; flex-wrap:wrap">
        ${sdm && boleh('golongan.kelola') ? '<button class="linkbtn" id="btn-golongan">⚙ kelola golongan</button>' : ''}
        ${sdm && boleh('golongan.kelola') ? '<button class="linkbtn" id="btn-unit-sdm">⚙ kelola unit</button>' : ''}
        ${bolehImpor ? `<button class="linkbtn" id="btn-impor">⬆ Impor ${esc(t.orang)} dari Excel</button>` : ''}
        ${bolehKartu ? '<button class="linkbtn" id="btn-kartu-massal">📇 Daftar Kartu Massal</button>' : ''}
        ${!sdm && boleh('santri.kenaikan') ? '<button class="linkbtn" id="btn-kenaikan">🎓 Kenaikan Kelas</button>' : ''}
        ${!sdm && boleh('santri.gender_massal') ? '<button class="linkbtn" id="btn-gender-massal">🚻 Set Gender Massal</button>' : ''}
        ${bolehArsip ? '<button class="linkbtn" id="btn-arsip">📦 Arsipkan</button>' : ''}
        <button class="linkbtn" id="btn-unduh-daftar">⬇ Unduh daftar</button>
        ${bolehTambah ? `<button class="linkbtn" id="btn-add-siswa">+ Tambah ${esc(t.orang)}</button>` : ''}
      </div>
    </div>
    ${sdm ? `<p class="muted">Guru, karyawan, tamu, dan personil VIP memakai kartu, saldo, kasir,
      dan kios yang sama dengan santri — yang berbeda hanya <b>golongan</b>-nya, dan tiap golongan
      punya batas belanja serta hak aksesnya sendiri. Semua saldo mereka tetap masuk ke satu
      neraca kas yang sama.</p>` : ''}
    <div id="sw-impor"></div>
    <div id="sw-tahun"></div>
    <div class="filterbar">
      <div class="field"><label>Cari</label>
        <input id="sw-cari" placeholder="nama / ${esc(t.nomorSingkat)}${sdm ? '' : ' / NISN'} / UID"></div>
      ${sdm ? `<div class="field"><label>Golongan</label><select id="sw-gol"></select></div>` : ''}
      <div class="field"><label>${sdm ? 'Unit' : 'Kelas'}</label><select id="sw-kelas"><option value="">semua</option></select></div>
      <div class="field"><label>Status</label><select id="sw-status">
        <option value="">aktif &amp; blokir</option>
        <option value="aktif">aktif</option>
        <option value="blokir">blokir</option>
        <option value="arsip">— semua yang terarsip —</option>
        <option value="alumni">alumni</option>
        <option value="lanjut_study">lanjut study</option>
        <option value="pindah">pindah</option></select></div>
      ${sdm ? '' : `<div class="field"><label>Portal ortu</label><select id="sw-ortu">
        <option value="">semua</option><option value="belum">data belum lengkap</option></select></div>`}
      ${sdm ? '' : `<div class="field"><label>Biaya admin</label><select id="sw-biaya">
        <option value="">semua</option>
        <option value="ikut">ikut golongan</option>
        <option value="bebas">dibebaskan</option>
        <option value="khusus">tarif khusus</option></select></div>`}
    </div>
    <p class="muted">💡 Tap kartu di halaman ini untuk langsung menemukan pemiliknya / mendaftarkan kartu baru.</p>
    <div class="tblwrap"><table>
      <thead id="sw-thead"></thead>
      <tbody id="sw-body"></tbody>
    </table></div>
    <div class="pager">
      <span class="muted" id="sw-pageinfo"></span>
      <button id="sw-prev">‹</button><button id="sw-next">›</button>
    </div>
  </div>`;

  renderTheadSiswa(sdm);
  // Satu pendengar klik di kepala tabel yang menyertakan atribut data-urut —
  // dipasang sekali di sini, bukan setiap kali header digambar ulang, sebab
  // #sw-thead sendiri tidak pernah diganti, hanya isinya (lewat innerHTML).
  $('#sw-thead').addEventListener('click', (e) => {
    const th = e.target.closest('th[data-urut]');
    if (!th) return;
    const kolom = th.dataset.urut;
    if (siswaState.urut === kolom) siswaState.arah = siswaState.arah === 'asc' ? 'desc' : 'asc';
    else { siswaState.urut = kolom; siswaState.arah = 'asc'; }
    siswaState.page = 1;
    renderTheadSiswa(sdm);
    loadSiswa();
  });

  // Daftar Unit untuk SDM datang dari KODE (lewat /api/kelas), bukan dari data:
  // pilihannya harus tetap lengkap walau belum ada personil di unit tersebut.
  api('/api/kelas').then((d) => {
    UNIT_SDM = d.unit_sdm || UNIT_SDM;
    const pilihan = sdm ? UNIT_SDM : d.kelas;
    $('#sw-kelas').innerHTML = '<option value="">semua</option>' +
      pilihan.map((k) => `<option value="${esc(k)}">${esc(k)}</option>`).join('');
  }).catch(() => {});

  const reload = () => { siswaState.page = 1; loadSiswa(); };
  $('#sw-cari').addEventListener('input', debounce(reload, 300));
  $('#sw-kelas').onchange = reload;
  $('#sw-status').onchange = reload;
  if ($('#sw-ortu')) $('#sw-ortu').onchange = reload;
  if ($('#sw-biaya')) $('#sw-biaya').onchange = reload;
  if ($('#sw-gol')) $('#sw-gol').onchange = reload;
  $('#sw-prev').onclick = () => { if (siswaState.page > 1) { siswaState.page--; loadSiswa(); } };
  $('#sw-next').onclick = () => { siswaState.page++; loadSiswa(); };
  if ($('#btn-add-siswa')) $('#btn-add-siswa').onclick = () => siswaModal(null);
  $('#btn-unduh-daftar').onclick = unduhDaftarModal;
  if ($('#btn-golongan')) $('#btn-golongan').onclick = kelolaGolonganModal;
  if ($('#btn-unit-sdm')) $('#btn-unit-sdm').onclick = kelolaUnitSdmModal;
  if ($('#btn-impor')) $('#btn-impor').onclick = () => imporKartuPanel(!!sdm);
  if ($('#btn-kartu-massal')) $('#btn-kartu-massal').onclick = () => kartuMassalPanel(!!sdm);
  if ($('#btn-arsip')) $('#btn-arsip').onclick = () => arsipPanel(!!sdm);
  if ($('#btn-kenaikan')) $('#btn-kenaikan').onclick = kenaikanPanel;
  if ($('#btn-gender-massal')) $('#btn-gender-massal').onclick = genderMassalModal;
  loadSiswa();
}

/**
 * Kepala tabel Santri/SDM, digambar ulang setiap kolom urut berubah supaya
 * panah penanda pindah ke kolom yang baru diklik. Hanya kolom dengan makna
 * tunggal yang boleh diurutkan — "Unit & jabatan" gabungan dua data sekaligus
 * jadi sengaja dilewati.
 */
function renderTheadSiswa(sdm) {
  const panah = (k) => siswaState.urut === k ? (siswaState.arah === 'asc' ? ' ▲' : ' ▼') : '';
  const th = (k, label, cls = '') => `<th class="th-urut${cls}" data-urut="${k}" title="Urutkan berdasarkan ${esc(label)}">${esc(label)}${panah(k)}</th>`;
  $('#sw-thead').innerHTML = `<tr><th></th>
    ${th('nama', 'Nama')}
    ${sdm ? th('golongan', 'Golongan') : th('kelas', 'Kelas')}
    ${sdm ? th('nis', 'NIY') : ''}
    ${sdm ? '<th>Unit &amp; jabatan</th>' : th('nis', 'NIS')}
    <th>UID Kartu</th>
    ${th('saldo', 'Saldo', ' num')}
    ${th('status', 'Status')}
    ${sdm ? th('berlaku_sampai', 'Berlaku sampai') : '<th>Portal ortu</th>'}
    <th>PIN kios</th><th></th></tr>`;
}

/**
 * Istilah yang berbeda antara kedua layar.
 *
 * Fungsinya sama persis — nomor induk yang khas, dipakai sebagai PIN bawaan
 * kios dan alamat transfer — tapi sebutannya mengikuti yang dipakai pesantren:
 * santri punya NIS, pegawai punya NIY / nomor pegawai.
 */
const ISTILAH = (sdm) => sdm
  ? { orang: 'personil', nomor: 'NIY / Nomor Pegawai', nomorSingkat: 'NIY', unit: 'Unit' }
  : { orang: 'santri', nomor: 'NIS', nomorSingkat: 'NIS', unit: 'Kelas' };

let UNIT_SDM = ['Pengasuhan', 'SMP', 'SMA', 'Ekskul', 'RT', 'LE', 'Lainnya'];

/** Status yang berarti "sudah keluar". Harus sama dengan App\Services\Kelas::ARSIP. */
const TERARSIP = ['alumni', 'lanjut_study', 'pindah'];

const LABEL_STATUS = {
  aktif: 'aktif', blokir: 'blokir',
  alumni: 'alumni', lanjut_study: 'lanjut study', pindah: 'pindah',
};

const LABEL_ARSIP = {
  alumni: 'Alumni (tamat SMA)',
  lanjut_study: 'Lanjut Study (tamat SMP, melanjutkan di luar)',
  pindah: 'Pindah',
};

/**
 * Kelas yang bentuknya menyimpang DITANDAI, bukan ditolak.
 *
 * Dengan enam tingkat dikali sepuluh paralel ada sampai 60 nama kelas yang
 * diketik tangan, dan "7 A" atau "70A" akan diterima apa adanya lalu memecah
 * laporan menjadi kelas hantu. Menolaknya terlalu berisiko — data lama dan
 * kartu tamu instansi memakai bentuk lain — tapi membiarkannya tak terlihat
 * berarti tidak ada yang pernah membetulkannya.
 */
const KELAS_SAH = /^(7|8|9|10|11|12)[A-Z]{1,2}$/;
const lencanaKelas = (k) => {
  const v = String(k || '');
  if (!v) return '<span class="muted">—</span>';
  return KELAS_SAH.test(v) ? esc(v)
    : `${esc(v)} <span class="badge blokir" title="Bentuk kelas tidak lazim — harus angka 7–12 diikuti huruf, mis. 7A">?</span>`;
};

/** Golongan yang tampil di menu SDM: semuanya kecuali santri. */
const golonganSdm = () => GOLONGAN.filter((g) => g.kode !== 'siswa');
const kodeSdm = () => golonganSdm().map((g) => g.kode).join(',') || 'sdm';

/** Kartu tamu/VIP boleh punya masa berlaku; kosong = tanpa batas waktu. */
function lencanaBerlaku(s) {
  if (!s.berlaku_sampai) return '<span class="muted">tanpa batas</span>';
  const hariIni = new Date(Date.now() + 7 * 3600 * 1000).toISOString().slice(0, 10);
  const habis = s.berlaku_sampai < hariIni;
  return `<span class="badge ${habis ? 'blokir' : 'aktif'}">${esc(s.berlaku_sampai)}${habis ? ' — habis' : ''}</span>`;
}

/**
 * Saringan daftar pemegang kartu, dalam satu tempat.
 *
 * Dipakai bersama oleh daftar di layar DAN unduhannya, supaya yang terunduh
 * selalu yang terlihat. Dua susunan parameter yang terpisah cepat atau lambat
 * berbeda, dan admin yang menyerahkan berkasnya tidak punya cara mengetahuinya.
 */
function siswaQs(tambahan) {
  const p = new URLSearchParams({
    urut: siswaState.urut || 'nama', arah: siswaState.arah || 'asc' });
  if ($('#sw-cari')?.value.trim()) p.set('cari', $('#sw-cari').value.trim());
  if ($('#sw-kelas')?.value) p.set('kelas', $('#sw-kelas').value);
  if ($('#sw-status')?.value) p.set('status', $('#sw-status').value);
  if ($('#sw-ortu')?.value) p.set('ortu', $('#sw-ortu').value);
  if ($('#sw-biaya')?.value) p.set('biaya', $('#sw-biaya').value);
  // Tanpa parameter golongan server hanya mengirim santri; menu SDM menyebut
  // sisanya secara eksplisit supaya golongan yang ditambahkan admin ikut tampil.
  if (siswaState.sdm) p.set('golongan', $('#sw-gol')?.value || kodeSdm());
  for (const [k, v] of Object.entries(tambahan || {})) {
    if (v === null) p.delete(k); else p.set(k, v);
  }
  return p;
}

/**
 * Unduh daftar pemegang kartu.
 *
 * Bawaannya mengikuti saringan yang sedang berlaku di layar — itulah yang
 * biasanya dimaksud orang ketika menekan "unduh". Pilihan "semua" disediakan
 * terpisah dan disebut terang-terangan, supaya tidak ada yang mengira sudah
 * mengunduh seluruh santri padahal yang terbawa hanya satu kelas.
 */
function unduhDaftarModal() {
  const t = ISTILAH(siswaState.sdm);
  const kelas = $('#sw-kelas')?.value || '';
  const cari = $('#sw-cari')?.value.trim() || '';
  const status = $('#sw-status')?.value || '';
  const label = siswaState.sdm ? 'unit' : 'kelas';
  const saringan = [
    kelas ? `${label} <b>${esc(kelas)}</b>` : null,
    status ? `status <b>${esc(status)}</b>` : null,
    cari ? `pencarian <b>"${esc(cari)}"</b>` : null,
  ].filter(Boolean);

  openModal(`
    <h2>Unduh Daftar ${esc(t.orang)}</h2>
    <label class="cek" style="align-items:flex-start; margin-bottom:8px">
      <input type="radio" name="ud-lingkup" value="layar" checked>
      <span><b>Sesuai saringan di layar</b><br><span class="muted" style="font-size:12.5px">
        ${saringan.length ? saringan.join(' · ') : 'tanpa saringan — seluruh ' + esc(t.orang) + ' aktif'}
      </span></span></label>
    <label class="cek" style="align-items:flex-start; margin-bottom:8px">
      <input type="radio" name="ud-lingkup" value="semua">
      <span><b>Semua ${esc(label === 'kelas' ? 'kelas' : 'unit')}</b><br>
        <span class="muted" style="font-size:12.5px">Saringan ${label}, status, dan pencarian
        diabaikan. Yang sudah terarsip tetap tidak ikut.</span></span></label>
    <label class="cek" style="margin-top:4px"><input type="checkbox" id="ud-arsip">
      ikut sertakan yang sudah terarsip (lulus/keluar)</label>
    <p class="muted set-hint">Berkas .xlsx berisi NIS, nama, ${esc(label)}, golongan, status,
      saldo, titipan, UID kartu, NISN, tanggal lahir, HP wali, dan masa berlaku kartu — beserta
      baris jumlah di bawahnya. <b>PIN kios tidak pernah ikut terunduh.</b><br>
      Karena memuat data pribadi, simpan dan bagikan berkasnya seperlunya saja.</p>`,
    async () => {
      const semua = document.querySelector('input[name="ud-lingkup"]:checked')?.value === 'semua';
      const p = siswaQs(semua
        ? { kelas: null, cari: null, status: null, ortu: null, biaya: null }
        : {});
      if ($('#ud-arsip').checked) p.set('status', 'arsip');
      const a = document.createElement('a');
      a.href = '/api/siswa/unduh?' + p;
      document.body.appendChild(a); a.click(); a.remove();
      toast('⬇ Berkas sedang disiapkan…', 'ok');
    }, 'Unduh');
}

async function loadSiswa() {
  const p = siswaQs({ page: siswaState.page, limit: 20 });
  const sdm = siswaState.sdm;
  const d = await api('/api/siswa?' + p);
  siswaState.rows = d.siswa;
  GOLONGAN = d.golongan || GOLONGAN;
  // Saringan golongan baru bisa diisi setelah daftarnya diketahui; nilai yang
  // sedang dipilih dipertahankan agar tidak melompat balik ke "semua".
  const selGol = $('#sw-gol');
  if (selGol && !selGol.dataset.terisi) {
    selGol.dataset.terisi = '1';
    selGol.innerHTML = `<option value="${esc(kodeSdm())}">semua non-santri</option>` +
      golonganSdm().map((g) => `<option value="${esc(g.kode)}">${esc(g.nama)}</option>`).join('');
  }
  $('#sw-count').textContent = `— ${d.total.toLocaleString('id-ID')} ${sdm ? 'personil' : 'santri'}` +
    ` · total saldo ${rp(d.total_saldo)}` +
    (d.total_titipan ? ` · titipan ${rp(d.total_titipan)}` : '');
  $('#sw-body').innerHTML = d.siswa.map((s) => `
    <tr>
      <td>${avatarHtml(s, true)}</td>
      <td><b>${esc(s.nama)}</b></td>
      <td>${sdm ? esc(golDari(s.golongan).nama) : lencanaKelas(s.kelas)}</td>
      ${sdm ? `<td>${s.nis ? esc(s.nis) : '<span class="muted">belum ada</span>'}</td>` : ''}
      <td>${sdm
        ? `${esc(s.kelas || '—')}${s.jabatan ? `<br><span class="muted">${esc(s.jabatan)}</span>` : ''}`
        : esc(s.nis)}</td>
      <td>${s.card_uid ? `<code>${esc(s.card_uid)}</code>` : '<span class="muted">belum ada</span>'}</td>
      <td class="num rp">${rp(s.saldo)}</td>
      <td><span class="badge ${TERARSIP.includes(s.status) ? 'nonaktif' : s.status}">${esc(LABEL_STATUS[s.status] || s.status)}</span></td>
      <td>${sdm ? lencanaBerlaku(s) : (s.nis && s.tgl_lahir
        ? '<span class="badge aktif">siap</span>'
        : '<span class="badge blokir">belum</span>')}</td>
      <td>${s.pin_kustom
        ? '<span class="badge aktif">diganti sendiri</span>'
        : String(s.nis || '').replace(/\D/g, '').length >= 4
          ? `<span class="muted">bawaan (${esc(ISTILAH(sdm).nomorSingkat)})</span>`
          : `<span class="badge blokir">${esc(ISTILAH(sdm).nomorSingkat)} belum ada</span>`}</td>
      <td style="white-space:nowrap">${[
        boleh('saldo.lihat') ? `<button class="linkbtn" data-act="saldo" data-id="${s.id}">saldo</button>` : '',
        boleh('riwayat.lihat') ? `<button class="linkbtn" data-act="riwayat" data-id="${s.id}">riwayat</button>` : '',
        boleh(`${sdm ? 'sdm' : 'santri'}.ubah`) ? `<button class="linkbtn" data-act="edit" data-id="${s.id}">ubah</button>` : '',
        boleh(`${sdm ? 'sdm' : 'santri'}.kartu`) ? `<button class="linkbtn" data-act="kartu" data-id="${s.id}">kartu</button>` : '',
        boleh(`${sdm ? 'sdm' : 'santri'}.reset_pin`) ? `<button class="linkbtn" data-act="pin" data-id="${s.id}">reset PIN</button>` : '',
        // Kartu terarsip tidak menawarkan blokir/aktifkan biasa: yang berlaku
        // untuknya adalah pemulihan dari arsip, dan itu tombol yang berbeda.
        !TERARSIP.includes(s.status) && boleh(`${sdm ? 'sdm' : 'santri'}.ubah`)
          ? `<button class="linkbtn" data-act="blokir" data-id="${s.id}">${s.status === 'aktif' ? 'blokir' : 'aktifkan'}</button>` : '',
        boleh(`${sdm ? 'sdm' : 'santri'}.arsip`)
          ? (TERARSIP.includes(s.status)
            ? `<button class="linkbtn" data-act="pulihkan" data-id="${s.id}">pulihkan</button>`
            : `<button class="linkbtn danger" data-act="arsipkan" data-id="${s.id}">arsipkan</button>`) : '',
      ].filter(Boolean).join(' · ') || '<span class="muted">—</span>'}</td>
    </tr>`).join('') ||
    `<tr><td colspan="${sdm ? 11 : 10}" class="muted">Tidak ada ${sdm ? 'personil' : 'santri'} cocok filter</td></tr>`;
  const maxPage = Math.max(1, Math.ceil(d.total / d.limit));
  $('#sw-pageinfo').textContent = `hal ${siswaState.page} / ${maxPage}`;
  $('#sw-prev').disabled = siswaState.page <= 1;
  $('#sw-next').disabled = siswaState.page >= maxPage;

  document.querySelectorAll('#sw-body .linkbtn').forEach((b) => {
    const s = siswaState.rows.find((x) => x.id === Number(b.dataset.id));
    // "saldo" melompat ke tab Saldo Santri dengan santri ini sudah terpilih
    if (b.dataset.act === 'saldo') b.onclick = () => {
      saldoPreselect = s;
      tandaiAktif('saldo');
      location.hash = 'saldo';
      renderView('saldo');
    };
    // "riwayat" melompat ke tab Riwayat Transaksi dengan filter terkunci ke
    // satu orang ini saja — berlaku untuk golongan apa pun (santri/sdm/tamu/vip),
    // karena loadSiswa() ini sudah dipakai bersama oleh tab Santri & SDM.
    if (b.dataset.act === 'riwayat') b.onclick = () => {
      riwayatPreselect = { id: s.id, nama: s.nama };
      tandaiAktif('riwayat');
      location.hash = 'riwayat';
      renderView('riwayat');
    };
    if (b.dataset.act === 'edit') b.onclick = () => siswaModal(s);
    if (b.dataset.act === 'kartu') b.onclick = () => assignCardModal(s);
    // Reset PIN kios: satu-satunya jalan keluar untuk santri yang lupa PIN —
    // sengaja hanya lewat admin, bukan lewat kios.
    if (b.dataset.act === 'pin') b.onclick = () => resetPinModal(s);
    if (b.dataset.act === 'arsipkan') b.onclick = () => arsipSatuModal(s);
    if (b.dataset.act === 'pulihkan') b.onclick = () => pulihkanModal(s);
    if (b.dataset.act === 'blokir') b.onclick = async () => {
      await api('/api/siswa/' + s.id, { method: 'PUT', body: { status: s.status === 'aktif' ? 'blokir' : 'aktif' } })
        .catch((e) => toast(e.message, 'err'));
      loadSiswa();
    };
  });
}

// tap kartu di tab siswa: kalau terdaftar → cari pemiliknya; kalau belum → modal daftar
async function siswaLookupCard(uid) {
  if ($('#modal-root').innerHTML) return;
  const d = await api('/api/card-lookup', { method: 'POST', body: { uid } }).catch(() => null);
  if (!d) return;
  if (d.siswa) {
    $('#sw-cari').value = uid;
    siswaState.page = 1;
    loadSiswa();
    toast(`Kartu milik ${d.siswa.nama}`);
  } else {
    assignCardModal(null, uid);
  }
}

function resetPinModal(s) {
  const nisLayak = String(s.nis || '').replace(/\D/g, '').length >= 4;
  // Sebutan nomor induk mengikuti golongan kartunya, bukan tab tempat tombol
  // ditekan: kartu SDM bisa saja muncul di hasil pencarian layar mana pun.
  const t = ISTILAH(s.golongan !== 'siswa');
  openModal(`
    <h2>Reset PIN Kios</h2>
    <p>PIN kios <b>${esc(s.nama)}</b> (${esc(s.kelas)}) akan dikembalikan ke
      <b>PIN bawaan, yaitu ${esc(t.nomorSingkat)}-nya</b>${s.nis ? `: <code>${esc(s.nis)}</code>` : ''}.</p>
    <p class="muted">Pakai ini kalau yang bersangkutan lupa PIN — PIN tidak bisa direset sendiri
      di kios. Kartu yang terlanjur terkunci karena salah PIN ikut terbuka.
      Mintalah PIN segera diganti lagi lewat kios.</p>
    ${nisLayak ? '' : `<p class="preview">${esc(t.nomorSingkat)} kartu ini belum diisi (atau kurang
      dari 4 angka), jadi PIN bawaannya belum bisa dipakai. Isi ${esc(t.nomorSingkat)} lewat tombol
      <b>ubah</b> dulu.</p>`}`,
    async () => {
      const d = await api(`/api/siswa/${s.id}/reset-pin`, { method: 'POST' });
      toast(d.pesan, d.siap ? 'ok' : 'err');
      loadSiswa();
    }, 'Reset PIN');
}

function siswaModal(s) {
  const sdm = siswaState.sdm;
  // Golongan bawaan formulir baru mengikuti tab tempat tombolnya ditekan:
  // menambah orang dari menu SDM tidak masuk akal berakhir sebagai santri.
  const kodeAwal = s?.golongan || (sdm ? (golonganSdm()[0]?.kode || 'sdm') : 'siswa');
  // Kalau daftar golongan belum sempat termuat, formulir tetap bisa dipakai
  // dengan golongan yang sedang berlaku — lebih baik daripada select kosong.
  const pilihan = GOLONGAN.filter((g) => g.aktif || g.kode === kodeAwal);
  if (!pilihan.length) pilihan.push({ kode: kodeAwal, nama: kodeAwal, label_unit: 'Kelas' });
  const g0 = golDari(kodeAwal);
  openModal(`
    <h2>${s ? 'Ubah Data' : 'Tambah'} ${sdm ? 'Personil' : 'Santri'}</h2>
    ${s ? `<div style="display:flex; gap:12px; align-items:center; margin-bottom:12px">
      ${avatarHtml(s)}
      <div class="field" style="flex:1; margin:0"><label>Foto (JPG/PNG, maks 3MB)</label>
      <input type="file" id="m-foto" accept="image/*"></div></div>` : ''}
    <div class="field"><label>Golongan</label>
      <select id="m-golongan">${pilihan.map((g) =>
        `<option value="${esc(g.kode)}" ${g.kode === kodeAwal ? 'selected' : ''}>${esc(g.nama)}</option>`).join('')}</select>
      <span class="muted set-hint" id="m-gol-hint"></span></div>
    <div class="field"><label>Nama</label><input id="m-nama" value="${esc(s?.nama || '')}"></div>
    <div class="field"><label id="m-kelas-label">${esc(g0.label_unit || 'Kelas')}</label>
      <span id="m-kelas-wrap"></span></div>
    <div class="field"><label id="m-nis-label">NIS</label>
      <input id="m-nis" value="${esc(s?.nis || '')}">
      <span class="muted set-hint" id="m-nis-hint"></span></div>
    <div class="field"><label>UID kartu (opsional — bisa juga tap kartu sekarang)</label>
      <input id="m-uid" data-scan="1" value="${esc(s?.card_uid || '')}" placeholder="tap kartu atau ketik manual"></div>
    ${!sdm ? `<div class="field">
      <label>Gender</label>
      <span class="muted set-hint" style="display:block; margin-bottom:4px">
        ${s?.jenis_kelamin ? (s.jenis_kelamin === 'L' ? '✔ Putra' : '✔ Putri')
          : '<span class="minus">belum ditetapkan</span>'} — diisi massal per kelas di tombol
        🚻 Set Gender Massal, bukan di sini. Dasar aturan "transfer hanya sesama gender".</span>
      <label style="margin-top:4px">Kode keluarga <span class="muted">(opsional)</span></label>
      <input id="m-keluarga" value="${esc(s?.kode_keluarga || '')}" maxlength="40"
        placeholder="mis. kode unik per keluarga">
      <span class="muted set-hint">Beri kode yang SAMA ke kakak-adik supaya mereka tetap boleh
        transfer walau beda gender. Kosongkan kalau tidak ada saudara kandung yang juga santri di sini.</span>
    </div>` : ''}
    <div class="field" id="m-khusus-wrap">
      <label>Jabatan / keterangan</label>
      <input id="m-jabatan" value="${esc(s?.jabatan || '')}" maxlength="120"
        placeholder="mis. Guru Matematika, Tamu Dinas Pendidikan">
      <label style="margin-top:8px">Kartu berlaku sampai <span class="muted">(kosongkan = tanpa batas)</span></label>
      <input type="date" id="m-berlaku" value="${esc(s?.berlaku_sampai || '')}">
      <span class="muted set-hint">Lewat tanggal ini kartu berhenti dilayani kasir dan kios,
        tetapi saldonya tetap tercatat.</span>
      <label style="margin-top:8px">Catatan</label>
      <input id="m-catatan" value="${esc(s?.catatan || '')}" maxlength="300" placeholder="catatan internal">
      <label style="display:flex; gap:8px; align-items:flex-start; margin-top:10px; font-weight:400">
        <input type="checkbox" id="m-iuran" ${s?.terima_iuran ? 'checked' : ''} style="margin-top:3px">
        <span>Boleh menerima iuran dari santri (jadi PJ) — muncul di daftar pilihan penerima
          iuran di layar Kios. Label kelompoknya diambil dari kolom Jabatan di atas.</span>
      </label>
    </div>
    <fieldset class="ortu-set" id="m-ortu-set">
      <legend id="m-ortu-legend">Akses portal orang tua</legend>
      <div class="field"><label>Biaya admin top up
          <span class="muted">(kosongkan = ikut golongan)</span></label>
        ${inputUang({ id: 'm-biaya', nilai: s?.biaya_admin ?? '' })}
        <span class="muted set-hint">Diisi <b>0</b> berarti <span class="m-orang">santri ini</span> <b>dibebaskan</b>; diisi
          angka berarti tarif khusus untuknya. Dikosongkan berarti mengikuti golongannya —
          dan tiga keadaan itu berbeda.</span></div>
      <p class="muted" style="margin:0 0 8px" id="m-ortu-catatan"></p>
      <div class="field" id="m-nisn-wrap"><label>NISN <span class="muted">(arsip sekolah, bukan kunci masuk)</span></label>
        <input id="m-nisn" inputmode="numeric" value="${esc(s?.nisn || '')}" placeholder="10 angka"></div>
      <div class="field"><label>Tanggal lahir</label>
        <input type="date" id="m-lahir" value="${esc(s?.tgl_lahir || '')}"></div>
      <div class="field"><label id="m-hp-label">No. HP wali</label>
        <input id="m-hp" inputmode="numeric" value="${esc(s?.hp_ortu || '')}" placeholder="08xxxxxxxxxx"></div>
    </fieldset>`,
    async () => {
      const body = {
        nama: $('#m-nama').value, kelas: $('#m-kelas').value,
        nis: $('#m-nis').value, card_uid: $('#m-uid').value.trim() || null,
        nisn: $('#m-nisn').value, tgl_lahir: $('#m-lahir').value, hp_ortu: $('#m-hp').value,
        golongan: $('#m-golongan').value, jabatan: $('#m-jabatan').value,
        berlaku_sampai: $('#m-berlaku').value, catatan: $('#m-catatan').value,
        terima_iuran: $('#m-iuran') ? $('#m-iuran').checked : false,
        ...($('#m-keluarga') ? { kode_keluarga: $('#m-keluarga').value } : {}),
        // Kosong dikirim sebagai string kosong — dibaca server sebagai "ikut
        // golongan", BUKAN sebagai nol yang berarti dibebaskan.
        biaya_admin: $('#m-biaya').value.trim() === '' ? '' : nilaiUang('#m-biaya'),
      };
      let id = s?.id;
      if (s) await api('/api/siswa/' + s.id, { method: 'PUT', body });
      else id = (await api('/api/siswa', { method: 'POST', body })).siswa.id;
      const f = s && $('#m-foto')?.files[0];
      if (f) {
        // Foto profil: cukup kecil untuk dilihat di tabel & kartu — sisi
        // terpanjang dibatasi lebih ketat daripada bukti transfer, karena di
        // sini tidak ada teks kecil yang perlu tetap terbaca.
        const data = (await kompresGambar(f, { maxSisi: 900 })).dataUrl;
        await api(`/api/siswa/${id}/foto`, { method: 'POST', body: { data } });
      }
      toast('✔ Data tersimpan', 'ok');
      loadSiswa();
    });

  // Ganti golongan → label kolom unit, bagian portal wali, dan petunjuk batasnya
  // ikut berubah seketika. Bagian portal wali disembunyikan (bukan sekadar
  // diabaikan) untuk golongan yang memang tidak dilayani portal, supaya admin
  // tidak mengisi data yang tidak akan pernah dipakai.
  /**
   * Isian Unit berganti bentuk mengikuti golongan.
   *
   * Santri mengetik kelasnya bebas (7A, 8B, …) karena daftarnya berubah tiap
   * tahun ajaran. SDM memilih dari daftar tetap, supaya tabel tidak berisi
   * campuran "SMP", "Smp", dan "S M P" yang menyulitkan saringan. Nilai lama
   * yang berada DI LUAR daftar tetap dipertahankan sebagai pilihan tambahan —
   * kalau tidak, menyunting kartu lama diam-diam memindahkannya ke unit lain.
   */
  const gambarUnit = (g) => {
    const wrap = $('#m-kelas-wrap');
    const nilai = $('#m-kelas') ? $('#m-kelas').value : (s?.kelas || '');
    if (g.kode === 'siswa') {
      wrap.innerHTML = '<input id="m-kelas" placeholder="7A">';
      $('#m-kelas').value = nilai;
      return;
    }
    const daftar = nilai && !UNIT_SDM.includes(nilai) ? [nilai, ...UNIT_SDM] : UNIT_SDM;
    wrap.innerHTML = `<select id="m-kelas">
      ${nilai ? '' : '<option value="">— pilih unit —</option>'}
      ${daftar.map((u) => `<option ${u === nilai ? 'selected' : ''}>${esc(u)}</option>`).join('')}
    </select>`;
  };

  const sinkronGolongan = () => {
    const g = golDari($('#m-golongan').value);
    const t = ISTILAH(g.kode !== 'siswa');
    $('#m-kelas-label').textContent = g.label_unit || 'Kelas';
    gambarUnit(g);
    $('#m-nis-label').textContent = `${t.nomor} / nomor kartu`;
    const pegawai = (g.jenis ?? (g.kode === 'siswa' ? 'siswa' : 'internal')) !== 'siswa';
    $('#m-nis-hint').textContent = 'Harus khas — dipakai sebagai PIN bawaan kios dan alamat ' +
      'transfer antar teman' + (g.portal_ortu
        ? ', serta kunci masuk portal SIMCASHTREN (isi angka saja — huruf dan tanda baca tidak akan cocok saat masuk).'
        : '.');
    $('#m-ortu-set').classList.toggle('hidden', !g.portal_ortu);
    $('#m-ortu-legend').textContent = pegawai ? 'Akses portal pegawai (SIMCASHTREN)' : 'Akses portal orang tua';
    $('#m-ortu-catatan').innerHTML = pegawai
      ? 'Pegawai membuka <code>/ortu</code>, memilih <b>Pegawai / Staf</b>, lalu masuk dengan ' +
        '<b>NIY + tanggal lahir (ddmmyy)</b>, plus 4 digit terakhir HP bila sekolah masih memintanya ' +
        '(diatur di Dashboard → Kunci Masuk Portal SIMCASHTREN).'
      : 'Wali membuka <code>/ortu</code>, memilih <b>Wali Santri</b>, lalu masuk dengan ' +
        '<b>NIS + tanggal lahir (ddmmyy)</b>, plus 4 digit terakhir HP wali bila sekolah masih ' +
        'memintanya (diatur di Dashboard → Kunci Masuk Portal SIMCASHTREN).';
    $('#m-nisn-wrap').classList.toggle('hidden', pegawai);
    $('#m-hp-label').textContent = pegawai ? 'No. HP' : 'No. HP wali';
    document.querySelectorAll('#m-ortu-set .m-orang').forEach((e) => { e.textContent = pegawai ? 'orang ini' : 'santri ini'; });
    $('#m-khusus-wrap').classList.toggle('hidden', g.kode === 'siswa');
    const batas = ['max_saldo', 'max_belanja_harian', 'max_beli_harian']
      .filter((k) => g[k] !== null && g[k] !== undefined);
    $('#m-gol-hint').textContent = batas.length
      ? 'Batas khusus golongan ini: ' + batas.map((k) =>
          k === 'max_beli_harian' ? `${g[k]}x belanja/hari` : rp(g[k])).join(' · ')
      : 'Memakai batas standar sekolah.';
  };
  $('#m-golongan').onchange = sinkronGolongan;
  sinkronGolongan();
  cardHandler = (uid) => { const f = $('#m-uid'); if (f) f.value = uid; };
}

// ============================== KELOLA GOLONGAN ==============================
// Satu baris golongan menetapkan aturan main seluruh kartu di dalamnya, jadi
// layar ini menampilkan angka sekolah yang berlaku di balik tiap kolom kosong:
// penimpa yang dibiarkan kosong bukan berarti "tanpa batas", melainkan "ikut
// sekolah" — dan dua hal itu terlalu mudah tertukar untuk dibiarkan tersirat.

const BATAS_LABEL = {
  max_saldo: 'Max saldo',
  max_topup_harian: 'Max top up / hari',
  max_beli_harian: 'Max transaksi / hari',
  max_belanja_harian: 'Max belanja / hari',
  max_per_transaksi: 'Max per transaksi',
  max_nonmakanan_harian: 'Max non-makanan / hari',
  max_beli_nonmakanan_harian: 'Max transaksi non-makanan / hari',
  max_nonmakanan_transaksi: 'Max non-makanan / transaksi',
};
// Dua batas ini berupa jumlah kejadian, bukan rupiah — kotaknya tidak bertitik ribuan.
const BATAS_KALI = new Set(['max_beli_harian', 'max_beli_nonmakanan_harian']);

async function kelolaGolonganModal() {
  const d = await api('/api/golongan');
  GOLONGAN = d.golongan;
  const cfg = d.settings;

  const baris = (g) => `
    <div class="gol-baris" data-id="${g.id}">
      <div class="gol-kepala">
        <b>${esc(g.nama)}</b>
        <code>${esc(g.kode)}</code>
        <span class="muted">${g.jumlah} pemegang kartu</span>
        ${g.bawaan ? '<span class="badge">bawaan</span>' : ''}
        <button class="linkbtn" data-ubah="${g.id}">ubah</button>
        ${g.bawaan ? '' : `<button class="linkbtn danger" data-hapus="${g.id}"
          ${g.jumlah ? 'disabled title="masih ada pemegang kartu"' : ''}>hapus</button>`}
      </div>
      <div class="muted">
        Kolom unit disebut “${esc(g.label_unit)}” ·
        ${g.aktif ? 'aktif' : '<b>nonaktif</b>'} ·
        portal SIMCASHTREN ${g.portal_ortu ? 'ya' : 'tidak'} ·
        kios ${g.kios ? 'ya' : 'tidak'} ·
        kirim saldo ${g.transfer ? 'ya' : 'tidak'}
      </div>
      <div class="muted">${Object.keys(BATAS_LABEL).map((k) => {
        const pakaiSekolah = g[k] === null || g[k] === undefined;
        const v = pakaiSekolah ? cfg[k] : g[k];
        const teks = BATAS_KALI.has(k) ? `${v}x` : rp(v);
        return `${esc(BATAS_LABEL[k])}: <b>${teks}</b>${pakaiSekolah ? ' <i>(sekolah)</i>' : ''}`;
      }).join(' · ')}</div>
    </div>`;

  openModal(`
    <h2>Golongan Pemegang Kartu</h2>
    <p class="muted">Setiap kartu masuk ke salah satu golongan. Batas yang dikosongkan
      <b>mengikuti pengaturan sekolah</b> — bukan berarti tanpa batas.</p>
    <div class="gol-list">${d.golongan.map(baris).join('')}</div>
    <button class="linkbtn" id="gol-tambah">+ Tambah golongan</button>`);

  const segarkan = () => { closeModal(); kelolaGolonganModal(); };
  $('#gol-tambah').onclick = () => golonganFormModal(null, cfg, segarkan);
  document.querySelectorAll('[data-ubah]').forEach((b) => {
    b.onclick = () => golonganFormModal(
      d.golongan.find((g) => g.id === Number(b.dataset.ubah)), cfg, segarkan);
  });
  document.querySelectorAll('[data-hapus]').forEach((b) => {
    b.onclick = async () => {
      try {
        await api('/api/golongan/' + b.dataset.hapus, { method: 'DELETE' });
        toast('✔ Golongan dihapus', 'ok');
        segarkan();
      } catch (e) { toast(e.message, 'err'); }
    };
  });
}

function golonganFormModal(g, cfg, sesudah) {
  const kotak = (k) => `
    <div class="field"><label>${esc(BATAS_LABEL[k])}</label>
      ${BATAS_KALI.has(k)
        ? `<input type="number" min="0" id="gf-${k}" value="${g?.[k] ?? ''}" placeholder="ikut sekolah (${cfg[k]})">`
        : inputUang({ id: `gf-${k}`, nilai: g?.[k] ?? '',
            placeholder: `ikut sekolah (${Number(cfg[k]).toLocaleString('id-ID')})` })}
    </div>`;

  openModal(`
    <h2>${g ? 'Ubah' : 'Tambah'} Golongan</h2>
    ${g ? `<div class="field"><label>Kode</label><input value="${esc(g.kode)}" disabled>
      <span class="muted set-hint">Kode tidak bisa diubah — seluruh kartu yang sudah ada menunjuk ke sini.</span></div>`
        : `<div class="field"><label>Kode</label>
      <input id="gf-kode" placeholder="mis. satpam" maxlength="20">
      <span class="muted set-hint">Huruf kecil, angka, dan garis bawah. Tidak bisa diubah lagi nanti.</span></div>`}
    <div class="field"><label>Nama golongan</label>
      <input id="gf-nama" value="${esc(g?.nama || '')}" maxlength="60" placeholder="mis. Satpam"></div>
    <div class="field"><label>Sebutan kolom unit</label>
      <input id="gf-label" value="${esc(g?.label_unit || 'Unit')}" maxlength="30" placeholder="Unit / Instansi / Kelas">
      <span class="muted set-hint">Judul kolom “kelas” untuk golongan ini.</span></div>
    ${g ? '' : `<div class="field"><label>Sifat</label><select id="gf-jenis">
      <option value="internal">internal (guru, karyawan)</option>
      <option value="tamu">tamu / undangan</option></select></div>`}
    <div class="field"><label>Hak akses</label>
      <label class="cek"><input type="checkbox" id="gf-aktif" ${g === null || g?.aktif ? 'checked' : ''}
        ${g?.kode === 'siswa' ? 'disabled' : ''}> golongan aktif${g?.kode === 'siswa' ? ' (golongan santri tidak bisa dinonaktifkan)' : ''}</label>
      <label class="cek"><input type="checkbox" id="gf-ortu" ${g?.portal_ortu ? 'checked' : ''}> bisa dibuka lewat portal SIMCASHTREN</label>
      <label class="cek"><input type="checkbox" id="gf-kios" ${g === null || g?.kios ? 'checked' : ''}> boleh memakai layar kios</label>
      <label class="cek"><input type="checkbox" id="gf-transfer" ${g === null || g?.transfer ? 'checked' : ''}> boleh mengirim saldo ke teman</label>
      <label class="cek"><input type="checkbox" id="gf-biaya" ${g?.biaya_admin_kena ? 'checked' : ''}>
        dipungut biaya admin saat wali top up</label>
      <span class="muted set-hint">Sengaja MATI untuk golongan baru — berbeda dari batas belanja di
        bawah yang “kosong berarti ikut sekolah”. Membuka portal SIMCASHTREN bagi sebuah golongan tidak
        boleh langsung memungut uang dari mereka tanpa keputusan tersendiri. Nominalnya satu untuk
        semua, diatur di menu <b>Topup Ortu</b>.</span>
    </div>
    <fieldset class="ortu-set">
      <legend>Batas khusus golongan ini</legend>
      <p class="muted" style="margin:0 0 8px">Kosongkan untuk mengikuti pengaturan sekolah
        (angkanya tertulis sebagai petunjuk di tiap kotak).</p>
      ${Object.keys(BATAS_LABEL).map(kotak).join('')}
    </fieldset>`,
    async () => {
      const body = {
        nama: $('#gf-nama').value,
        label_unit: $('#gf-label').value,
        aktif: $('#gf-aktif').checked ? 1 : 0,
        portal_ortu: $('#gf-ortu').checked ? 1 : 0,
        kios: $('#gf-kios').checked ? 1 : 0,
        transfer: $('#gf-transfer').checked ? 1 : 0,
        // Sempat hilang dari sini (28 Sep 2026): checkbox tampil dan bisa
        // dicentang, tapi nilainya tidak pernah ikut terkirim — admin
        // mencentang lalu Simpan tidak menunjukkan galat apa pun, dan
        // golongan itu tetap tidak pernah memungut siapa pun.
        biaya_admin_kena: $('#gf-biaya').checked ? 1 : 0,
      };
      for (const k of Object.keys(BATAS_LABEL)) {
        // Kosong dikirim sebagai null: itulah cara menyatakan "ikut sekolah".
        body[k] = BATAS_KALI.has(k)
          ? ($(`#gf-${k}`).value.trim() === '' ? null : Number($(`#gf-${k}`).value))
          : nilaiUang(`#gf-${k}`);
      }
      if (g) await api('/api/golongan/' + g.id, { method: 'PUT', body });
      else {
        body.kode = $('#gf-kode').value;
        body.jenis = $('#gf-jenis').value;
        await api('/api/golongan', { method: 'POST', body });
      }
      toast('✔ Golongan tersimpan', 'ok');
      sesudah();
    });
}

/**
 * Daftar Unit untuk SDM & personil — dulu angka tetap di kode, sekarang bisa
 * admin tambah/ubah/hapus sendiri. Jauh lebih sederhana dari golongan (cuma
 * nama, tanpa batas keuangan), jadi satu modal saja tanpa formulir terpisah.
 */
async function kelolaUnitSdmModal() {
  const d = await api('/api/unit-sdm');

  const baris = (u) => `
    <div class="gol-baris" data-id="${u.id}">
      <div class="gol-kepala">
        <b>${esc(u.nama)}</b>
        <span class="muted">${u.jumlah} personil</span>
        ${u.aktif ? '' : '<span class="badge">nonaktif</span>'}
        <button class="linkbtn" data-ubah-unit="${u.id}">ubah</button>
        <button class="linkbtn" data-toggle-unit="${u.id}">${u.aktif ? 'nonaktifkan' : 'aktifkan'}</button>
        <button class="linkbtn danger" data-hapus-unit="${u.id}"
          ${u.jumlah ? 'disabled title="masih ada personil di unit ini"' : ''}>hapus</button>
      </div>
    </div>`;

  openModal(`
    <h2>Unit untuk SDM &amp; Personil</h2>
    <p class="muted">Daftar pilihan kolom Unit di formulir SDM dan template impor Excel.
      Mengganti nama di sini <b>tidak</b> mengubah data personil yang sudah memakai nama lama —
      sama seperti kartu lama yang unitnya di luar daftar tetap tersimpan apa adanya.
      Unit yang masih dipakai personil tidak bisa dihapus; nonaktifkan saja supaya
      tidak ditawarkan lagi ke personil baru.</p>
    <div class="gol-list">${d.unit.map(baris).join('')}</div>
    <button class="linkbtn" id="unit-tambah">+ Tambah unit</button>`);

  const segarkan = () => { closeModal(); kelolaUnitSdmModal(); };
  $('#unit-tambah').onclick = () => unitSdmFormModal(null, segarkan);
  document.querySelectorAll('[data-ubah-unit]').forEach((b) => {
    b.onclick = () => unitSdmFormModal(d.unit.find((u) => u.id === Number(b.dataset.ubahUnit)), segarkan);
  });
  document.querySelectorAll('[data-toggle-unit]').forEach((b) => {
    b.onclick = async () => {
      const u = d.unit.find((x) => x.id === Number(b.dataset.toggleUnit));
      try {
        await api('/api/unit-sdm/' + u.id, { method: 'PUT', body: { aktif: u.aktif ? 0 : 1 } });
        toast(u.aktif ? '✔ Unit dinonaktifkan' : '✔ Unit diaktifkan', 'ok');
        segarkan();
      } catch (e) { toast(e.message, 'err'); }
    };
  });
  document.querySelectorAll('[data-hapus-unit]').forEach((b) => {
    b.onclick = async () => {
      try {
        await api('/api/unit-sdm/' + b.dataset.hapusUnit, { method: 'DELETE' });
        toast('✔ Unit dihapus', 'ok');
        segarkan();
      } catch (e) { toast(e.message, 'err'); }
    };
  });
}

function unitSdmFormModal(u, sesudah) {
  openModal(`
    <h2>${u ? 'Ubah' : 'Tambah'} Unit</h2>
    <div class="field"><label>Nama unit</label>
      <input id="uf-nama" value="${esc(u?.nama || '')}" maxlength="30" placeholder="mis. TU / Satpam"></div>`,
    async () => {
      const body = { nama: $('#uf-nama').value };
      if (u) await api('/api/unit-sdm/' + u.id, { method: 'PUT', body });
      else await api('/api/unit-sdm', { method: 'POST', body });
      toast('✔ Unit tersimpan', 'ok');
      sesudah();
    });
}

function assignCardModal(s, presetUid) {
  const rows = siswaState.rows || [];
  openModal(`
    <h2>Hubungkan Kartu</h2>
    <div class="field"><label>Santri</label>
      ${s ? `<input disabled value="${esc(s.nama)} (${esc(s.kelas)})">`
          : `<input id="m-cari-siswa" placeholder="ketik nama santri…"><div id="m-hasil"></div>
             <input type="hidden" id="m-sid" value="">`}
    </div>
    <div class="field"><label>UID kartu — tempelkan kartu sekarang, atau ketik manual</label>
      <input id="m-uid" data-scan="1" value="${esc(presetUid || '')}" placeholder="menunggu tap kartu…"></div>`,
    async () => {
      const sid = s ? s.id : Number($('#m-sid').value);
      if (!sid) throw new Error('Pilih santri dulu');
      const d = await api('/api/assign-card', { method: 'POST', body: { siswa_id: sid, uid: $('#m-uid').value } });
      toast(`✔ Kartu terhubung ke ${d.siswa.nama}`, 'ok');
      loadSiswa();
    });
  cardHandler = (uid) => { const f = $('#m-uid'); if (f) f.value = uid; };
  if (!s) {
    $('#m-cari-siswa').addEventListener('input', debounce(async () => {
      const q = $('#m-cari-siswa').value.trim();
      if (q.length < 2) { $('#m-hasil').innerHTML = ''; return; }
      const d = await api('/api/siswa?limit=6&cari=' + encodeURIComponent(q));
      $('#m-hasil').innerHTML = d.siswa.map((x) =>
        `<button class="linkbtn" style="display:block; padding:3px 0" data-id="${x.id}">${esc(x.nama)} (${esc(x.kelas)})</button>`).join('');
      $('#m-hasil').querySelectorAll('button').forEach((b) => {
        b.onclick = (ev) => {
          ev.preventDefault();
          $('#m-sid').value = b.dataset.id;
          $('#m-cari-siswa').value = b.textContent.trim();
          $('#m-hasil').innerHTML = '';
        };
      });
    }, 300));
  }
}

// ╔══════════════════════════════════════════════════════════╗
// ║ SUPERADMIN: TENANT                                        ║
// ╚══════════════════════════════════════════════════════════╝
/*
 * Layar Tenant memuat SELURUH urusan tenant, termasuk uangnya.
 *
 * Tabel tagihan dan riwayat setorannya dulu tinggal di Dashboard, berjauhan
 * dari tempat tenantnya dikelola. Akibatnya alur yang sebenarnya satu — lihat
 * siapa yang punya tagihan, setor, lalu pastikan tercatat — terpotong dua
 * menu. Sekarang ketiganya satu layar, dan Dashboard kembali menjadi
 * ringkasan sekolah, bukan tempat menagih.
 */
function tenantView(el) {
  el.innerHTML = `<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px">
      <h2 style="margin:0">Tenant Kantin</h2>
      <button class="linkbtn" id="btn-add-tenant">+ Tambah tenant</button>
    </div>
    <div class="tblwrap"><table>
      <thead><tr><th>Tenant</th><th>Jenis</th><th>Pemilik</th><th>Akun Login</th><th>Status</th><th></th></tr></thead>
      <tbody id="tn-body"></tbody>
    </table></div>
  </div>
  <div id="tn-uang"><p class="muted">memuat penghasilan & setoran…</p></div>`;
  $('#btn-add-tenant').onclick = () => tenantModal(null);
  loadTenants();
  muatUangTenant();
}

/** Panel tagihan + riwayat setoran. Dipisah supaya bisa disegarkan sendiri
 *  sesudah menyetor, tanpa menggambar ulang tabel pengelolaan tenant. */
async function muatUangTenant() {
  const wadah = $('#tn-uang');
  if (!wadah) return;
  try {
    const [d, st] = await Promise.all([
      api('/api/tenants?stat=1'),
      api('/api/settlements'),
    ]);
    wadah.innerHTML = `
    <div class="panel">
      <h2>Penghasilan & Tagihan Tenant</h2>
      <div class="tblwrap"><table>
        <thead><tr><th>Tenant</th><th>Status</th><th class="num">Hari Ini</th><th class="num">Total Penjualan</th>
        <th class="num">Sudah Disetor</th><th class="num">Tagihan Berjalan</th><th></th></tr></thead>
        <tbody>${d.tenants.map((t) => `
          <tr><td><b>${esc(t.nama)}</b><br><span class="muted">${esc(t.pemilik)}</span></td>
          <td><span class="badge ${t.status}">${t.status}</span></td>
          <td class="num rp">${rp(t.hari_ini)}</td>
          <td class="num rp">${rp(t.total)}</td>
          <td class="num rp">${rp(t.disetor)}</td>
          <td class="num rp" style="font-weight:800; color:${t.outstanding > 0 ? 'var(--err)' : 'var(--ok)'}">${rp(t.outstanding)}</td>
          <td>${t.outstanding > 0 ? `<button class="linkbtn" data-setor="${t.id}" data-nama="${esc(t.nama)}" data-out="${t.outstanding}">💰 setor</button>` : ''}</td>
          </tr>`).join('')}</tbody>
      </table></div>
    </div>
    <div class="panel">
      <h2>Riwayat Setoran ke Tenant</h2>
      <div class="tblwrap"><table>
        <thead><tr><th>Tanggal</th><th>Tenant</th><th class="num">Jumlah</th><th>Metode</th><th>Bukti</th><th>Oleh</th><th>Keterangan</th></tr></thead>
        <tbody>${st.settlements.map((s) => `
          <tr><td>${new Date(s.created_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })}</td>
          <td>${esc(s.tenant_nama)}</td><td class="num rp">${rp(s.jumlah)}</td><td>${esc(s.metode)}</td>
          <td>${s.bukti
            ? `<button class="linkbtn" data-bukti="${esc(s.bukti)}">lihat</button>`
            : `<button class="linkbtn" data-upbukti="${s.id}">upload bukti</button>`}</td>
          <td>${esc(s.operator)}</td><td class="muted">${esc(s.keterangan)}</td></tr>`).join('') ||
          '<tr><td colspan="7" class="muted">Belum ada setoran</td></tr>'}</tbody>
      </table></div>
    </div>`;

    bindBuktiButtons(wadah);
    wadah.querySelectorAll('[data-setor]').forEach((b) => {
      b.onclick = () => setorModal(Number(b.dataset.setor), b.dataset.nama, Number(b.dataset.out));
    });
    wadah.querySelectorAll('[data-upbukti]').forEach((b) => {
      b.onclick = () => openModal(`
        <h2>Upload Bukti Setoran</h2>
        <div class="field"><label>Foto/scan bukti (JPG/PNG)</label>
        <input type="file" id="m-file" accept="image/*"></div>`,
        async () => {
          const f = $('#m-file').files[0];
          if (!f) throw new Error('Pilih file dulu');
          const data = (await kompresGambar(f)).dataUrl;
          await api(`/api/settlements/${b.dataset.upbukti}/bukti`, { method: 'POST', body: { data } });
          toast('✔ Bukti tersimpan', 'ok');
          muatUangTenant();
        });
    });
  } catch (e) {
    // Gagalnya bagian ini tidak boleh ikut menjatuhkan tabel pengelolaan
    // tenant di atasnya — menambah atau menonaktifkan tenant harus tetap bisa.
    wadah.innerHTML = `<div class="panel"><p class="muted">Gagal memuat penghasilan &
      setoran: ${esc(e.message)}. <button class="linkbtn" id="tn-uang-ulang">coba lagi</button></p></div>`;
    $('#tn-uang-ulang').onclick = muatUangTenant;
  }
}

async function loadTenants() {
  const d = await api('/api/tenants');
  $('#tn-body').innerHTML = d.tenants.map((t) => `
    <tr>
      <td><b>${esc(t.nama)}</b></td>
      <td>${t.jenis === 'koperasi'
        ? '<span class="badge kat-nm">koperasi</span>' : '<span class="muted">kantin</span>'}
        ${t.mode_kasir === 'bebas' ? '<br><span class="badge" title="kasir mengetik deskripsi barang sendiri">tanpa menu</span>' : ''}
        ${t.mode_kasir !== 'bebas' && !t.input_manual
          ? '<br><span class="badge nonaktif" title="kasir hanya boleh menjual menu yang sudah disetujui">tanpa input manual</span>' : ''}</td>
      <td>${esc(t.pemilik)}</td>
      <td>${t.usernames ? `<code>${esc(t.usernames)}</code>` : '<span class="muted">—</span>'}</td>
      <td><span class="badge ${t.status}">${t.status}</span></td>
      <td style="white-space:nowrap">
        <button class="linkbtn" data-act="edit" data-id="${t.id}">ubah</button> ·
        <button class="linkbtn danger" data-act="del" data-id="${t.id}">hapus</button>
      </td>
    </tr>`).join('');
  document.querySelectorAll('#tn-body .linkbtn').forEach((b) => {
    const t = d.tenants.find((x) => x.id === Number(b.dataset.id));
    if (b.dataset.act === 'edit') b.onclick = () => tenantModal(t);
    if (b.dataset.act === 'del') b.onclick = () => {
      openModal(`<h2>Hapus tenant?</h2><p><b>${esc(t.nama)}</b> — jika sudah punya riwayat transaksi,
        tenant hanya akan dinonaktifkan (riwayat keuangan tidak boleh hilang).</p>`, async () => {
        const r = await api('/api/tenants/' + t.id, { method: 'DELETE' });
        toast(r.nonaktif ? 'Tenant dinonaktifkan (punya riwayat)' : 'Tenant dihapus', 'ok');
        loadTenants();
      }, 'Hapus');
    };
  });
}

function tenantModal(t) {
  openModal(`
    <h2>${t ? 'Ubah' : 'Tambah'} Tenant</h2>
    <div class="field"><label>Nama tenant</label><input id="m-nama" value="${esc(t?.nama || '')}" placeholder="Kantin Bu Sari"></div>
    <div class="field"><label>Pemilik</label><input id="m-pemilik" value="${esc(t?.pemilik || '')}"></div>
    <div class="field"><label>Jenis</label><select id="m-jenis">
      <option value="kantin" ${t?.jenis !== 'koperasi' ? 'selected' : ''}>Kantin (semua barangnya makanan)</option>
      <option value="koperasi" ${t?.jenis === 'koperasi' ? 'selected' : ''}>Koperasi (boleh menjual barang non-makanan)</option>
    </select>
      <span class="muted set-hint">Hanya koperasi yang boleh punya barang non-makanan —
        barang itu memakai jatah belanja harian tersendiri. Mengembalikan koperasi menjadi
        kantin akan menandai ulang semua barangnya sebagai makanan.</span></div>
    <div class="field"><label>Mode kasir</label><select id="m-mode">
      <option value="menu" ${!['bebas', 'mart'].includes(t?.mode_kasir) ? 'selected' : ''}>Menu nyala — kasir memilih dari daftar menu</option>
      <option value="bebas" ${t?.mode_kasir === 'bebas' ? 'selected' : ''}>Menu mati — kasir mengetik deskripsi & harga</option>
      <option value="mart" ${t?.mode_kasir === 'mart' ? 'selected' : ''}>Swalayan (IMBOS Mart) — pindai barcode, bisa bayar tunai</option>
    </select>
      <span class="muted set-hint">Pakai "menu mati" selama daftar barang belum selesai diinput.
        Di mode ini kasir yang memilih makanan/non-makanan, dan laporan menu terlaris
        berisi teks bebas — nyalakan lagi begitu daftar barangnya lengkap.<br>
        <b>Swalayan</b> memberi layar kasir tersendiri: barang dipindai barcode, ada kolom jumlah,
        dan pembeli umum boleh bayar <b>tunai</b>. Uang tunai TIDAK masuk kas sekolah — ia
        dibukukan terpisah dan dicocokkan lewat tutup kas harian. Pilih jenis
        <b>Koperasi</b> supaya boleh menjual barang non-makanan.</span></div>
    <label class="cek" id="m-manual-baris"><input type="checkbox" id="m-manual"
      ${t && t.input_manual === false ? '' : 'checked'}> izinkan input manual di kasir</label>
    <span class="muted set-hint" id="m-manual-hint">Kotak <b>nominal manual</b> dan
      <b>deskripsi barang</b> di layar kasir. Matikan supaya tenant hanya bisa melayani penjualan
      sesuai menu yang sudah disetujui admin — nyalakan lagi sewaktu-waktu kalau ada yang harus
      dilayani manual. Pagarnya ada di server, bukan sekadar menyembunyikan kotaknya.</span>

    <div id="m-mart-wa">
      <div class="field"><label>Target RAB bulanan (Rp)</label>${inputUang({ id: 'm-target-rab', nilai: t?.target_rab_bulanan || '' })}</div>
      <span class="muted set-hint">Khusus Imbos Mart (mode Swalayan). Dibagi rata per hari untuk hitung
        persentase capaian di tombol "copy laporan" layar Closing Harian — kosongkan kalau belum mau pakai target.</span>
    </div>

    ${t && t.mode_kasir === 'mart' ? `
    <div class="kat-head" style="margin-top:14px"><h3 style="margin:0">Akun Kasir (serve-only)</h3>
      <button class="linkbtn" id="m-kasir-tambah" type="button">+ Tambah akun kasir</button></div>
    <span class="muted set-hint">Login terpisah dari akun admin toko di atas — cuma bisa melayani
      (jual, tutup kas, tahan keranjang, koreksi riwayat, layani piutang) dan lihat Closing Harian.
      Tidak bisa kelola stok/member/pembelian. Cocok untuk unit Mart dengan lebih dari satu kasir
      bergantian jaga.</span>
    <div id="m-kasir-list" class="muted" style="margin-top:6px">memuat…</div>` : ''}

    ${t ? `
    <div class="field"><label>Status</label><select id="m-status">
      <option value="aktif" ${t.status === 'aktif' ? 'selected' : ''}>aktif</option>
      <option value="nonaktif" ${t.status === 'nonaktif' ? 'selected' : ''}>nonaktif</option></select></div>
    <div class="field"><label>Reset password akun (kosongkan jika tidak diganti)</label>
      <input id="m-pass" type="text" placeholder="password baru"></div>` : `
    <div class="field"><label>Username akun kasir</label><input id="m-user" placeholder="mis. sari"></div>
    <div class="field"><label>Password akun kasir</label><input id="m-pass" type="text"></div>`}`,
    async () => {
      const targetRab = { target_rab_bulanan: nilaiUang('#m-target-rab') };
      if (t) {
        const d = await api('/api/tenants/' + t.id, { method: 'PUT', body: {
          nama: $('#m-nama').value, pemilik: $('#m-pemilik').value, jenis: $('#m-jenis').value, mode_kasir: $('#m-mode').value,
          input_manual: $('#m-manual').checked, status: $('#m-status').value,
          password_baru: $('#m-pass').value || undefined,
          ...targetRab,
        }});
        if (d.kategori_direset > 0)
          toast(`${d.kategori_direset} barang non-makanan ditandai ulang sebagai makanan`, 'ok');
      } else {
        await api('/api/tenants', { method: 'POST', body: {
          nama: $('#m-nama').value, pemilik: $('#m-pemilik').value, jenis: $('#m-jenis').value, mode_kasir: $('#m-mode').value,
          input_manual: $('#m-manual').checked,
          username: $('#m-user').value, password: $('#m-pass').value,
          ...targetRab,
        }});
      }
      toast('✔ Tenant disimpan', 'ok');
      loadTenants();
    });

  // Di mode "menu mati" seluruh penjualan justru lewat input manual, jadi
  // sakelarnya tidak berlaku — dikunci menyala supaya tidak ada yang mematikan
  // kasirnya sendiri lewat dua pengaturan yang bersebelahan.
  const selarasManual = () => {
    const bebas = $('#m-mode').value === 'bebas';
    $('#m-manual').disabled = bebas;
    if (bebas) $('#m-manual').checked = true;
    $('#m-manual-hint').innerHTML = bebas
      ? 'Mode "menu mati" memang berjualan lewat input manual, jadi sakelar ini selalu menyala.'
      : 'Kotak <b>nominal manual</b> dan <b>deskripsi barang</b> di layar kasir. Matikan supaya '
        + 'tenant hanya bisa melayani penjualan sesuai menu yang sudah disetujui admin — nyalakan '
        + 'lagi sewaktu-waktu kalau ada yang harus dilayani manual. Pagarnya ada di server, bukan '
        + 'sekadar menyembunyikan kotaknya.';
  };
  $('#m-mode').addEventListener('change', selarasManual);
  selarasManual();

  // Target RAB cuma masuk akal untuk Imbos Mart (mode Swalayan) — tenant
  // kantin biasa tidak punya konsep SPD harian ini.
  const selarasMart = () => { $('#m-mart-wa').style.display = $('#m-mode').value === 'mart' ? '' : 'none'; };
  $('#m-mode').addEventListener('change', selarasMart);
  selarasMart();

  // Akun kasir cuma masuk akal untuk tenant Mart yang SUDAH tersimpan (perlu
  // tenant_id) — bagian ini statis di markup (lihat `${t && t.mode_kasir...}`
  // di atas), tidak ikut sakelar mode_kasir yang baru diubah di layar ini.
  if (t && t.mode_kasir === 'mart') {
    $('#m-kasir-tambah').onclick = () => kasirAkunModal(t.id, null);
    martMuatKasirAkun(t.id);
  }
}

/** Daftar akun kasir (serve-only) satu tenant Mart, dengan aksi ubah/hapus inline. */
async function martMuatKasirAkun(tenantId) {
  const wrap = $('#m-kasir-list');
  if (!wrap) return;
  let d;
  try { d = await api(`/api/tenants/${tenantId}/kasir`); }
  catch (e) { wrap.innerHTML = `<span class="minus">${esc(e.message)}</span>`; return; }

  wrap.innerHTML = d.kasir.length ? d.kasir.map((k) => `
    <div style="display:flex; justify-content:space-between; align-items:center; padding:4px 0">
      <span><b>${esc(k.nama)}</b> <code>${esc(k.username)}</code></span>
      <span><button class="linkbtn" data-kasir-ubah="${k.id}">reset sandi</button> ·
        <button class="linkbtn danger" data-kasir-hapus="${k.id}">hapus</button></span>
    </div>`).join('') : '<span class="muted">Belum ada akun kasir tambahan.</span>';

  wrap.querySelectorAll('[data-kasir-ubah]').forEach((b) => {
    b.onclick = () => kasirAkunModal(tenantId, d.kasir.find((k) => k.id === Number(b.dataset.kasirUbah)));
  });
  wrap.querySelectorAll('[data-kasir-hapus]').forEach((b) => {
    b.onclick = async () => {
      const k = d.kasir.find((x) => x.id === Number(b.dataset.kasirHapus));
      if (!confirm(`Hapus akun kasir "${k.nama}" (${k.username})?`)) return;
      try {
        await api(`/api/tenants/${tenantId}/kasir/${k.id}`, { method: 'DELETE' });
        toast('✔ Akun kasir dihapus', 'ok');
        martMuatKasirAkun(tenantId);
      } catch (e) { toast(e.message, 'err'); }
    };
  });
}

/**
 * Tambah/ubah akun kasir — modal TERPISAH dari Tenant modal (modal tidak
 * bertumpuk di aplikasi ini, yang baru menggantikan yang lama). Setelah
 * simpan, admin membuka kembali "Ubah" tenant untuk melihat daftar terbaru.
 */
function kasirAkunModal(tenantId, k) {
  openModal(`
    <h2>${k ? 'Reset Sandi' : 'Tambah'} Akun Kasir</h2>
    ${k ? `
    <div class="field"><label>Nama</label><input id="mka-nama" value="${esc(k.nama)}"></div>
    <div class="field"><label>Username</label><input value="${esc(k.username)}" disabled></div>
    <div class="field"><label>Password baru (kosongkan jika tidak diganti)</label>
      <input id="mka-pass" type="text" placeholder="password baru"></div>` : `
    <div class="field"><label>Nama</label><input id="mka-nama" placeholder="mis. Kasir Pagi"></div>
    <div class="field"><label>Username</label><input id="mka-user" placeholder="mis. kasir-putra-1"></div>
    <div class="field"><label>Password</label><input id="mka-pass" type="text"></div>`}`,
    async () => {
      if (k) {
        await api(`/api/tenants/${tenantId}/kasir/${k.id}`, { method: 'PUT', body: {
          nama: $('#mka-nama').value, password_baru: $('#mka-pass').value || undefined,
        }});
      } else {
        await api(`/api/tenants/${tenantId}/kasir`, { method: 'POST', body: {
          nama: $('#mka-nama').value, username: $('#mka-user').value, password: $('#mka-pass').value,
        }});
      }
      toast('✔ Akun kasir disimpan — buka kembali "Ubah" tenant untuk lihat daftarnya', 'ok');
    });
}

/*
 * Potong saldo kolektif lewat berkas Excel, nominal berbeda tiap santri.
 *
 * Dua langkah, dan langkah pertama TIDAK memindahkan satu rupiah pun. Itu
 * bukan kehati-hatian berlebihan: berkas ini MENGAMBIL uang dari kartu santri,
 * dan sesudah diterapkan pembetulannya hanya bisa lewat top up balik. Satu
 * layar pemeriksaan jauh lebih murah daripada satu kelas yang saldonya
 * terpotong salah.
 */
async function potongImporPratinjau(file) {
  let data, d;
  try {
    data = await fileToDataUrl(file);
    d = await api('/api/potong-massal/pratinjau', { method: 'POST',
      body: { data, nama_berkas: file.name } });
  } catch (e) { toast(e.message, 'err'); return; }

  const r = d.ringkas;
  const baris = d.baris.map((b) => `
    <tr class="${b.status === 'siap' ? '' : 'tp-menunggu'}">
      <td class="num muted">${b.baris}</td>
      <td><code>${esc(b.nis || '—')}</code></td>
      <td>${esc(b.nama || b.nama_berkas || '—')}
        ${b.kelas ? `<br><span class="muted">${esc(b.kelas)}</span>` : ''}</td>
      <td class="num rp">${b.saldo ? rp(b.saldo) : '<span class="muted">—</span>'}</td>
      <td class="num rp">${b.nominal ? rp(b.nominal) : '—'}</td>
      <td class="muted">${esc(b.keterangan || '')}</td>
      <td>${b.status === 'siap' ? '<span class="badge aktif">siap</span>'
        : `<span class="minus">${esc(b.pesan)}</span>`}</td>
    </tr>`).join('');

  openModal(`
    <h2>Pratinjau potong saldo</h2>
    ${d.sudah_pernah ? `<p class="preview" style="border-color:var(--err); color:var(--err)">
      <b>Berkas dengan isi yang sama persis sudah pernah diterapkan</b> pada
      ${esc(String(d.sudah_pernah.waktu).slice(0, 10))} oleh ${esc(d.sudah_pernah.operator)} —
      ${d.sudah_pernah.n_berhasil} santri, total ${rp(d.sudah_pernah.total)}.
      Menerapkannya lagi akan ditolak, jadi saldo tidak akan terpotong dua kali.</p>` : ''}
    <p><b>${esc(d.nama_berkas)}</b> — ${r.n_baris} baris:
      <b>${r.n_siap} siap dipotong</b>${r.n_gagal ? `, <span class="minus">${r.n_gagal} dilewati</span>` : ''}.
      Total yang akan dipotong <b class="rp">${rp(r.total_potong)}</b>.</p>
    ${r.n_saldo_kurang ? `<p class="muted">${r.n_saldo_kurang} santri saldonya kurang dan
      <b>dilewati utuh</b> — tidak dipotong sebagian. Tagih sisanya terpisah, atau betulkan
      nominalnya lalu unggah lagi.</p>` : ''}
    <div class="tblwrap" style="max-height:48vh; overflow:auto"><table>
      <thead><tr><th class="num">Baris</th><th>NIS</th><th>Santri</th><th class="num">Saldo</th>
        <th class="num">Dipotong</th><th>Keterangan</th><th>Status</th></tr></thead>
      <tbody>${baris}</tbody>
    </table></div>
    <div class="field" style="margin-top:10px"><label>Alasan umum <b class="minus">wajib</b></label>
      <input id="pt-alasan" maxlength="200" placeholder="mis. potongan kegiatan Oktober">
      <span class="muted set-hint">Dipakai baris yang kolom Keterangannya kosong. Tercatat satu
        per satu di riwayat tiap santri atas nama <b>${esc(USER?.nama || '')}</b> dan tidak bisa
        dihapus — pembetulan dilakukan dengan top up balik.</span></div>`,
    r.n_siap ? async () => {
      const alasan = $('#pt-alasan').value.trim();
      if (alasan.length < 3) throw new Error('Alasan umum wajib diisi (minimal 3 huruf)');
      const batch = 'POT-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
      const h = await api('/api/potong-massal/terapkan', { method: 'POST', body: {
        data, nama_berkas: d.nama_berkas, sidik: d.sidik, batch_id: batch, alasan } });
      if (h.duplikat) { toast(h.pesan, 'err'); return; }
      toast(`✔ ${h.berhasil.length} santri terpotong — total ${rp(h.impor.total_potong)}`, 'ok');
      potongHasilModal(h);
    } : null, `Potong ${r.n_siap} santri · ${rp(r.total_potong)}`);
  $('#modal-root .modal').classList.add('modal-lebar');
  // Mengubah sumber = menghitung ulang seluruh berkas DI SERVER, bukan di
  // layar: pecahan yang ditampilkan harus datang dari penghitung yang sama
  // dengan yang nanti menjalankannya.
  $('#pt-sumber').onchange = () => {
    const pilih = $('#pt-sumber').value;
    closeModal();
    potongImporPratinjau({ name: d.nama_berkas }, pilih, data);
  };
  const a = $('#pt-alasan');
  if (a) a.focus();
}

/** Hasil penerapan — termasuk yang gagal, supaya tidak ada yang hilang diam-diam. */
function potongHasilModal(h) {
  const gagal = [...(h.dilewati || []), ...(h.gagal_pratinjau || [])];
  setTimeout(() => openModal(`
    <h2>Hasil potong saldo</h2>
    <p><b>${h.berhasil.length} santri</b> terpotong, total <b class="rp">${rp(h.impor.total_potong)}</b>.
      ${gagal.length ? `<span class="minus">${gagal.length} dilewati.</span>` : ''}</p>
    <div class="tblwrap" style="max-height:40vh; overflow:auto"><table>
      <thead><tr><th>Santri</th><th class="num">Dipotong</th><th class="num">Saldo sesudah</th></tr></thead>
      <tbody>${h.berhasil.map((b) => `<tr><td>${esc(b.nama)}
        <span class="muted">${esc(b.kelas || '')}</span></td>
        <td class="num rp">${rp(b.nominal)}</td>
        <td class="num rp">${rp(b.saldo_sesudah)}</td></tr>`).join('')
        || '<tr><td colspan="3" class="muted">Tidak ada.</td></tr>'}</tbody>
    </table></div>
    ${gagal.length ? `<h3 style="font-size:13.5px">Dilewati</h3>
      <div class="tblwrap" style="max-height:30vh; overflow:auto"><table>
        <thead><tr><th class="num">Baris</th><th>NIS</th><th>Santri</th><th>Sebab</th></tr></thead>
        <tbody>${gagal.map((b) => `<tr><td class="num muted">${b.baris}</td>
          <td><code>${esc(b.nis || '—')}</code></td>
          <td>${esc(b.nama || b.nama_berkas || '—')}</td>
          <td class="minus">${esc(b.pesan)}</td></tr>`).join('')}</tbody>
      </table></div>` : ''}`), 150);
  setTimeout(() => $('#modal-root .modal')?.classList.add('modal-lebar'), 160);
}

async function potongRiwayatModal() {
  const d = await api('/api/potong-massal/riwayat');
  openModal(`
    <h2>Riwayat Potong via Excel</h2>
    <p class="muted">Berkas yang isinya sama persis tidak bisa diterapkan dua kali —
      daftar ini yang mengingatnya.</p>
    <div class="tblwrap" style="max-height:50vh; overflow:auto"><table>
      <thead><tr><th>Waktu</th><th>Berkas</th><th>Alasan</th><th class="num">Kena</th>
        <th class="num">Dilewati</th><th class="num">Total</th><th>Oleh</th></tr></thead>
      <tbody>${d.impor.map((x) => `<tr>
        <td>${waktuSingkat(x.created_at)}</td>
        <td><code>${esc(x.berkas || '—')}</code></td>
        <td class="muted">${esc(x.alasan || '')}</td>
        <td class="num">${x.n_berhasil}</td>
        <td class="num">${x.n_gagal}</td>
        <td class="num rp"><b>${rp(x.total_potong)}</b></td>
        <td>${esc(x.operator)}</td></tr>`).join('')
        || '<tr><td colspan="7" class="muted">Belum pernah.</td></tr>'}</tbody>
    </table></div>
    <button class="linkbtn" id="pt-unduh">⬇ unduh CSV</button>`);
  $('#modal-root .modal').classList.add('modal-lebar');
  $('#pt-unduh').onclick = () => unduhCsv(`riwayat-potong-massal.csv`,
    ['Waktu', 'Berkas', 'Alasan', 'Baris', 'Kena', 'Dilewati', 'Total dipotong', 'Oleh'],
    d.impor.map((x) => [x.created_at, x.berkas, x.alasan, x.n_baris, x.n_berhasil,
      x.n_gagal, x.total_potong, x.operator]));
}

// ╔══════════════════════════════════════════════════════════╗
// ║ TOPUP MASSAL — unggah rekap setoran dari Excel/CSV         ║
// ╚══════════════════════════════════════════════════════════╝
// Dua langkah, sengaja tidak bisa dilewati: unggah → pratinjau → terapkan.
// Berkasnya dikirim lagi saat menerapkan (server mengurai ulang), jadi yang
// tampil di tabel pratinjau ini benar-benar hasil bacaan server, bukan tafsiran
// halaman ini. Sidik berkas dari pratinjau ikut dikirim supaya berkas yang
// diterapkan pasti berkas yang tadi diperiksa.

let massal = null;   // { nama, data, pra } — berkas yang sedang diperiksa

const MASSAL_METODE = { tunai: 'Tunai', transfer: 'Transfer Bank', qris: 'QRIS' };

function massalView(el) {
  massal = null;
  el.innerHTML = `
  <div class="panel">
    <h2>1 · Unduh Template</h2>
    <p class="muted">Kolomnya: <b>No · NIS · Nama Santri · Nominal Topup ·
      Tanggal Uang Diterima (DD/MM/YYYY) · Metode Pembayaran (Tunai / Transfer Bank / QRIS)</b>.
      Jangan mengubah baris judul — kolom dikenali dari judulnya. Template versi
      “berisi daftar santri” sudah memuat NIS &amp; nama, jadi bendahara hanya mengisi
      nominal, tanggal, dan metode.</p>
    <div class="filterbar">
      <button class="go" id="ms-tpl">⬇ Template kosong</button>
      <div class="field"><label>Kelas (untuk template berisi daftar santri)</label>
        <select id="ms-kelas"><option value="">semua kelas</option></select></div>
      <button id="ms-tpl-isi">⬇ Template berisi daftar santri</button>
    </div>
  </div>

  <div class="panel">
    <h2>2 · Unggah &amp; Pratinjau</h2>
    <div class="field"><label>Berkas .xlsx atau .csv (maks 4MB, 1000 baris)</label>
      <input type="file" id="ms-file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"></div>
    <label><input type="checkbox" id="ms-titipan" checked style="width:auto">
      Simpan kelebihan sebagai saldo titipan</label>
    <span class="hint-lembut">Tanpa ini, baris yang nominalnya tidak tertampung kartu dianggap gagal.</span>
    <div class="field" style="margin:8px 0"><label>Biaya admin</label>
      <select id="ms-biaya">
        <option value="free" selected>Free — tanpa biaya admin</option>
        <option value="biaya">Biaya admin per baris</option>
      </select>
      <span class="hint-lembut" style="margin-left:0">Berlaku untuk seluruh berkas. Biaya dipotong dari
        nominal tiap baris dan masuk Dana Admin; santri yang dibebaskan tetap tidak dipotong.</span></div>
    <label><input type="checkbox" id="ms-nama" style="width:auto">
      Terapkan juga baris yang namanya berbeda dari data santri</label>
    <span class="hint-lembut">Nama berbeda biasanya berarti NIS salah ketik — biarkan mati kecuali
      Anda sudah memeriksanya satu per satu.</span>
    <div style="margin-top:12px"><button class="go" id="ms-pra" disabled>Periksa berkas</button></div>
  </div>

  <div id="ms-hasil"></div>

  <div class="panel">
    <div class="kat-head">
      <h2 style="margin:0">Riwayat Impor</h2>
      <button class="linkbtn" id="ms-muat-riwayat">muat ulang</button>
    </div>
    <div class="tblwrap"><table>
      <thead><tr><th>Waktu</th><th>Berkas</th><th>Batch</th><th class="num">Berhasil</th>
      <th class="num">Gagal</th><th class="num">Ke Kartu</th><th class="num">Ke Titipan</th><th>Oleh</th><th></th></tr></thead>
      <tbody id="ms-riwayat"><tr><td colspan="9" class="muted">memuat…</td></tr></tbody>
    </table></div>
  </div>`;

  api('/api/kelas').then((d) => {
    $('#ms-kelas').innerHTML = '<option value="">semua kelas</option>' +
      d.kelas.map((k) => `<option>${esc(k)}</option>`).join('');
  }).catch(() => {});

  // Unduhan lewat <a download>: template dikirim sebagai berkas biner, jadi tidak
  // bisa lewat fetch+JSON seperti permintaan lainnya.
  const unduh = (url) => {
    const a = document.createElement('a');
    a.href = url;
    document.body.appendChild(a);
    a.click();
    a.remove();
  };
  $('#ms-tpl').onclick = () => unduh('/api/topup-massal/template');
  $('#ms-tpl-isi').onclick = () => {
    const k = $('#ms-kelas').value;
    unduh('/api/topup-massal/template?isi=1' + (k ? '&kelas=' + encodeURIComponent(k) : ''));
  };

  $('#ms-file').addEventListener('change', () => {
    $('#ms-pra').disabled = !$('#ms-file').files[0];
    $('#ms-hasil').innerHTML = '';
    massal = null;
  });
  $('#ms-pra').onclick = massalPratinjau;
  // Pilihan biaya mengubah angka tiap baris — pratinjau yang sudah tampil dihitung ulang.
  $('#ms-biaya').onchange = () => { if (massal && $('#ms-file').files[0]) massalPratinjau(); };
  $('#ms-muat-riwayat').onclick = muatRiwayatImpor;
  muatRiwayatImpor();
}

async function massalPratinjau() {
  const f = $('#ms-file').files[0];
  if (!f) return;
  const btn = $('#ms-pra');
  btn.disabled = true;
  $('#ms-hasil').innerHTML = '<div class="panel"><p class="muted">membaca berkas…</p></div>';
  try {
    const data = await fileToDataUrl(f);
    const pra = await api('/api/topup-massal/pratinjau', { method: 'POST', body: {
      nama_berkas: f.name, data,
      ke_titipan: $('#ms-titipan').checked,
      abaikan_nama: $('#ms-nama').checked,
      ...($('#ms-biaya').value === 'biaya' ? { biaya_admin: true } : {}),
    }});
    massal = { nama: f.name, data, pra };
    massalRenderPratinjau();
  } catch (e) {
    $('#ms-hasil').innerHTML = `<div class="panel"><h2>Berkas tidak bisa dibaca</h2>
      <p class="minus">${esc(e.message)}</p>
      <p class="muted">Pakai template resmi dari langkah 1, dan pastikan baris judul kolom
        masih ada di lembar yang sama dengan datanya.</p></div>`;
  }
  btn.disabled = false;
}

function massalRenderPratinjau() {
  const { pra } = massal;
  const r = pra.ringkas;
  const metode = Object.entries(MASSAL_METODE)
    .map(([k, label]) => `${label}: <b>${r.per_metode[k].n}</b> baris ${rp(r.per_metode[k].total)}`)
    .join(' · ');

  $('#ms-hasil').innerHTML = `
  <div class="panel">
    <h2>3 · Periksa Hasil Bacaan</h2>
    ${pra.pernah_diterapkan ? `
      <p class="preview" style="border-color:var(--err)">⚠️ Berkas dengan isi <b>sama persis</b> sudah
        pernah diterapkan pada ${esc(String(pra.pernah_diterapkan.created_at).slice(0, 10))}
        oleh ${esc(pra.pernah_diterapkan.operator)} (batch ${esc(pra.pernah_diterapkan.batch_id)},
        ${pra.pernah_diterapkan.n_berhasil} baris,
        ${rp(pra.pernah_diterapkan.total_saldo + pra.pernah_diterapkan.total_titipan)}).
        Menerapkannya lagi akan <b>menambah saldo dua kali</b>.</p>` : ''}
    <div class="cards" style="margin-bottom:10px">
      <div class="statcard"><span class="muted">Baris terbaca</span><br><b>${r.n_baris}</b></div>
      <div class="statcard"><span class="muted">Siap diterapkan</span><br><b class="plus">${r.n_siap}</b></div>
      <div class="statcard"><span class="muted">Bermasalah</span><br>
        <b class="${r.n_gagal ? 'minus' : ''}">${r.n_gagal}</b></div>
      <div class="statcard"><span class="muted">Total nominal siap</span><br><b class="rp">${rp(r.total)}</b></div>
      <div class="statcard"><span class="muted">Masuk kartu</span><br><b class="rp">${rp(r.total_saldo)}</b></div>
      <div class="statcard"><span class="muted">Mengendap di titipan</span><br><b class="rp">${rp(r.total_titipan)}</b></div>
      ${pra.biaya_admin ? `<div class="statcard"><span class="muted">Biaya admin</span><br><b class="rp">${rp(r.total_biaya)}</b></div>` : ''}
    </div>
    <p class="muted">Lembar <b>${esc(pra.lembar)}</b> · berkas <b>${esc(pra.nama_berkas)}</b><br>${metode}
      ${r.n_nama_beda ? `<br>⚠️ ${r.n_nama_beda} baris namanya berbeda dari data santri` : ''}
      ${r.n_kembar ? `<br>ℹ️ ${r.n_kembar} baris memakai NIS yang sudah muncul di baris lain
        (boleh saja bila memang dua setoran)` : ''}</p>

    <div class="tblwrap" style="max-height:420px"><table>
      <thead><tr><th>Baris</th><th>NIS</th><th>Nama di berkas</th><th>Nama di data</th>
      <th class="num">Nominal</th>${pra.biaya_admin ? '<th class="num">Biaya</th>' : ''}
      <th class="num">Ke Kartu</th><th class="num">Ke Titipan</th>
      <th>Tanggal</th><th>Metode</th><th>Status</th></tr></thead>
      <tbody>${pra.baris.map((b) => `
        <tr class="${b.status === 'gagal' ? 'tp-menunggu' : ''}">
          <td>${b.baris}</td><td><code>${esc(b.nis || '—')}</code></td>
          <td>${esc(b.nama_berkas || '—')}</td>
          <td>${esc(b.nama_db || '—')}${b.kelas ? ` <span class="muted">${esc(b.kelas)}</span>` : ''}</td>
          <td class="num rp">${rp(b.jumlah)}</td>
          ${pra.biaya_admin ? `<td class="num rp">${b.status === 'siap' ? rp(b.biaya) : '—'}</td>` : ''}
          <td class="num rp">${b.status === 'siap' ? rp(b.ke_saldo) : '—'}</td>
          <td class="num rp">${b.status === 'siap' ? rp(b.ke_titipan) : '—'}</td>
          <td>${esc(b.tgl_terima || '—')}</td>
          <td>${esc(MASSAL_METODE[b.metode] || '—')}</td>
          <td>${b.status === 'siap'
            ? `<span class="badge tp-disetujui">siap</span>${b.pesan ? `<br><span class="muted">${esc(b.pesan)}</span>` : ''}`
            : `<span class="badge tp-ditolak">gagal</span><br><span class="muted">${esc(b.pesan)}</span>`}</td>
        </tr>`).join('')}</tbody>
    </table></div>

    ${pra.pernah_diterapkan ? `
      <label style="margin-top:10px"><input type="checkbox" id="ms-ulang" style="width:auto">
        Terapkan lagi walau isinya sama dengan impor sebelumnya</label>` : ''}
    <button class="paybtn" id="ms-terap" ${r.n_siap ? '' : 'disabled'}>
      ${r.n_siap ? `Terapkan ${r.n_siap} baris · ${rp(r.total)}` : 'Tidak ada baris yang siap'}</button>
    <p class="muted">Baris bermasalah tidak diterapkan — perbaiki di Excel lalu unggah lagi.
      Saldo baru berubah setelah tombol ini ditekan.</p>
  </div>`;

  $('#ms-terap').onclick = () => {
    const ulang = !!$('#ms-ulang')?.checked;
    if (massal.pra.pernah_diterapkan && !ulang) {
      toast('Centang “terapkan lagi walau isinya sama” bila ini memang setoran berbeda', 'err');
      return;
    }
    openModal(`
      <h2>Terapkan ${r.n_siap} baris top up?</h2>
      <p><b>${rp(r.total)}</b> diterima: ${rp(r.total_saldo)} ke kartu,
        ${rp(r.total_titipan)} mengendap sebagai titipan${r.total_biaya
          ? `, dan <b>${rp(r.total_biaya)}</b> biaya admin masuk Dana Admin` : ''}.</p>
      <p class="muted">Tercatat atas nama <b>${esc(USER.nama)}</b>, satu baris riwayat per setoran.
        Pembatalan hanya bisa lewat pemotongan saldo manual di menu Saldo Santri, jadi pastikan
        rekapnya sudah cocok dengan uang yang benar-benar diterima.</p>`,
      () => massalTerapkan(ulang), 'Ya, terapkan');
  };
}

async function massalTerapkan(izinkanUlang) {
  // batch_id dibuat per penerapan: mengunggah berkas yang sama dua kali tetap
  // tertahan di server lewat sidik isi berkas, bukan lewat angka ini.
  const batch = 'ms-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
  const d = await api('/api/topup-massal/terapkan', { method: 'POST', body: {
    nama_berkas: massal.nama, data: massal.data, sidik: massal.pra.sidik,
    batch_id: batch,
    ke_titipan: $('#ms-titipan').checked,
    abaikan_nama: $('#ms-nama').checked,
    // Pilihan biaya mengikuti yang DIPRATINJAU, bukan isi kotak saat ini.
    ...(massal.pra.biaya_admin ? { biaya_admin: true } : {}),
    ...(izinkanUlang ? { izinkan_ulang: true } : {}),
  }});
  const i = d.impor;
  toast(`✔ ${i.n_berhasil} baris diterapkan — ${rp(i.total_saldo)} ke kartu, ` +
    `${rp(i.total_titipan)} ke titipan`, 'ok');
  $('#ms-hasil').innerHTML = `
    <div class="panel">
      <h2>✔ Impor selesai</h2>
      <div class="cards" style="margin-bottom:10px">
        <div class="statcard"><span class="muted">Baris berhasil</span><br><b class="plus">${i.n_berhasil}</b></div>
        <div class="statcard"><span class="muted">Baris gagal (dilewati)</span><br><b>${i.n_gagal}</b></div>
        <div class="statcard"><span class="muted">Masuk kartu</span><br><b class="rp">${rp(i.total_saldo)}</b></div>
        <div class="statcard"><span class="muted">Ke titipan</span><br><b class="rp">${rp(i.total_titipan)}</b></div>
        ${i.total_biaya ? `<div class="statcard"><span class="muted">Biaya admin</span><br><b class="rp">${rp(i.total_biaya)}</b></div>` : ''}
        ${d.smart?.n ? `<div class="statcard"><span class="muted">Isi ulang otomatis</span><br>
          <b>${d.smart.n} santri</b><br><span class="muted">${rp(d.smart.total)}</span></div>` : ''}
      </div>
      <p class="muted">Batch <code>${esc(i.batch_id)}</code> — rinciannya bisa dibuka lagi
        dari Riwayat Impor di bawah, dan tiap setorannya muncul di menu Riwayat sebagai top up.</p>
    </div>`;
  massal = null;
  $('#ms-file').value = '';
  $('#ms-pra').disabled = true;
  muatRiwayatImpor();
}

async function muatRiwayatImpor() {
  const body = $('#ms-riwayat');
  if (!body) return;
  const d = await api('/api/topup-massal/riwayat').catch(() => ({ impor: [] }));
  body.innerHTML = d.impor.map((i) => `
    <tr>
      <td>${esc(waktuSingkat(i.created_at))}</td>
      <td>${esc(i.berkas)}</td>
      <td><code>${esc(i.batch_id)}</code></td>
      <td class="num plus">${i.n_berhasil}</td>
      <td class="num ${i.n_gagal ? 'minus' : 'muted'}">${i.n_gagal}</td>
      <td class="num rp">${rp(i.total_saldo)}</td>
      <td class="num rp">${rp(i.total_titipan)}</td>
      <td>${esc(i.operator)}</td>
      <td><button class="linkbtn" data-impor="${i.id}">rincian</button></td>
    </tr>`).join('') || '<tr><td colspan="9" class="muted">Belum ada impor</td></tr>';
  body.querySelectorAll('[data-impor]').forEach((b) => {
    b.onclick = async () => {
      const r = await api('/api/topup-massal/riwayat?id=' + b.dataset.impor);
      openModal(`
        <h2>Rincian impor ${esc(r.impor.batch_id)}</h2>
        <p class="muted">${esc(r.impor.berkas)} · ${esc(waktuSingkat(r.impor.created_at))} ·
          oleh ${esc(r.impor.operator)}</p>
        <div class="tblwrap" style="max-height:60vh"><table>
          <thead><tr><th>Baris</th><th>NIS</th><th>Nama</th><th class="num">Nominal</th>
          <th class="num">Kartu</th><th class="num">Titipan</th><th>Metode</th><th>Tanggal</th><th>Status</th></tr></thead>
          <tbody>${r.baris.map((x) => `
            <tr><td>${x.baris}</td><td><code>${esc(x.nis)}</code></td><td>${esc(x.nama)}</td>
            <td class="num rp">${rp(x.jumlah)}</td><td class="num rp">${rp(x.ke_saldo)}</td>
            <td class="num rp">${rp(x.ke_titipan)}</td>
            <td>${esc(MASSAL_METODE[x.metode] || x.metode || '—')}</td><td>${esc(x.tgl_terima)}</td>
            <td>${x.status === 'berhasil'
              ? '<span class="badge tp-disetujui">berhasil</span>'
              : `<span class="badge tp-ditolak">gagal</span>`}
              ${x.pesan ? `<br><span class="muted">${esc(x.pesan)}</span>` : ''}</td></tr>`).join('')}</tbody>
        </table></div>`);
    };
  });
}

// ================================================ TOPUP ORTU (superadmin)
// Antrean konfirmasi pembayaran yang dikirim orang tua dari portal /ortu.
// Menyetujui di sini benar-benar menambah saldo kartu santri, jadi tombolnya
// melewati DUA konfirmasi — modal rincian lalu modal "yakin" — dan server pun
// menolak persetujuan yang datang tanpa penanda konfirmasi kedua.

let tpStatus = 'pending';     // filter yang sedang aktif
let tpQrisBaru = null;        // data URL gambar QRIS yang baru dipilih admin
let tpQrisSekarang = '';      // nama berkas QRIS yang tersimpan di server

const TP_METODE = { qris: 'QRIS', transfer: 'Transfer bank' };
const TP_STATUS = { pending: 'menunggu', disetujui: 'disetujui', ditolak: 'ditolak' };

const tpWaktu = (iso) => new Date(iso).toLocaleString('id-ID',
  { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });

function topupOrtuView(el) {
  el.innerHTML = `
  <div class="cards" id="tp-kartu"></div>

  <div id="tp-dana"></div>

  <div class="panel">
    <div class="kat-head">
      <h2>Antrean Pengajuan Isi Saldo</h2>
      <div class="quick" id="tp-filter">
        <button data-status="pending" class="sel">menunggu</button>
        <button data-status="disetujui">disetujui</button>
        <button data-status="ditolak">ditolak</button>
        <button data-status="">semua</button>
      </div>
    </div>
    <p class="muted">Cocokkan tiap pengajuan dengan mutasi rekening sekolah sebelum menyetujui.
      Persetujuan langsung menambah saldo kartu santri dan tercatat di <b>Riwayat</b> sebagai top up.</p>
    <div class="tblwrap"><table>
      <thead><tr><th>Diajukan</th><th>Santri</th><th>Kelas</th><th class="num">Nominal</th>
        <th>Metode</th><th>Tgl bayar</th><th>Pengirim</th><th>Bukti</th><th>Kembar</th>
        <th>Status</th><th></th></tr></thead>
      <tbody id="tp-baris"></tbody>
    </table></div>
  </div>

  <div class="row">
    <div class="panel">
      <h2>Instruksi Pembayaran</h2>
      <p class="muted">Yang diisi di sini persis yang dibaca orang tua di portal
        <a href="/ortu" target="_blank"><code>/ortu</code></a>.
        Metode yang datanya belum lengkap tidak pernah ditawarkan ke wali.</p>

      <fieldset class="ortu-set"><legend>QRIS statis</legend>
        <div id="tp-qris-pratinjau"></div>
        <div class="field"><label>Ganti gambar QRIS</label>
          <input id="tp-qris-file" type="file" accept="image/*">
          <span class="muted hint">PNG/JPG/WebP, maksimal 3MB</span></div>
        <div class="field"><label>Nama penerima yang tertera di QRIS</label>
          <input id="tp-qris-nama" maxlength="80" placeholder="mis. KANTIN IMBOS PRINGSEWU"></div>
        <div class="field"><label>Instruksi untuk orang tua</label>
          <textarea id="tp-qris-instruksi" rows="3" maxlength="500"></textarea></div>
      </fieldset>

      <fieldset class="ortu-set"><legend>Transfer bank</legend>
        <div class="field"><label>Nama bank</label>
          <input id="tp-bank-nama" maxlength="40" placeholder="mis. Bank Syariah Indonesia"></div>
        <div class="field"><label>Nomor rekening</label><input id="tp-bank-rek" maxlength="40"></div>
        <div class="field"><label>Atas nama</label><input id="tp-bank-an" maxlength="80"></div>
        <div class="field"><label>Instruksi untuk orang tua</label>
          <textarea id="tp-bank-instruksi" rows="3" maxlength="500"></textarea></div>
      </fieldset>
      <button class="paybtn" id="tp-simpan-info">Simpan instruksi pembayaran</button>
    </div>

    <div class="panel">
      <h2>Batas Pengajuan</h2>
      <div class="field"><label>Layanan pengajuan</label>
        <select id="tp-aktif">
          <option value="1">Dibuka — orang tua bisa mengajukan</option>
          <option value="0">Ditutup — tombol isi saldo disembunyikan</option></select></div>
      <div class="field"><label>Nominal terkecil satu pengajuan</label>
        ${inputUang({ id: 'tp-min' })}</div>
      <div class="field"><label>Nominal terbesar satu pengajuan</label>
        ${inputUang({ id: 'tp-maks' })}</div>
      <div class="field"><label>Maksimal pengajuan menunggu per santri</label>
        <input id="tp-antre" inputmode="numeric"></div>
      <div class="field"><label>Biaya admin per pengajuan
          <span class="muted">(isi 0 untuk tidak memungut)</span></label>
        ${inputUang({ id: 'tp-biaya' })}
        <span class="muted set-hint" id="tp-biaya-hint"></span></div>
      <button class="paybtn" id="tp-simpan-batas">Simpan batas pengajuan</button>
      <p class="muted" id="tp-catatan">Batas <b>max saldo</b> dan <b>max top up harian</b> di menu
        Dashboard tetap berlaku, dan diperiksa ulang saat Anda menekan Setujui.</p>
    </div>
  </div>`;

  $('#tp-filter').onclick = (e) => {
    const b = e.target.closest('button[data-status]');
    if (!b) return;
    document.querySelectorAll('#tp-filter button').forEach((x) => x.classList.toggle('sel', x === b));
    tpStatus = b.dataset.status;
    muatAntreanTopup();
  };
  $('#tp-qris-file').onchange = async (e) => {
    const f = e.target.files[0];
    tpQrisBaru = null;
    if (!f) return;
    if (f.size > 3 * 1024 * 1024) { e.target.value = ''; return toast('Gambar maksimal 3MB', 'err'); }
    tpQrisBaru = await fileToDataUrl(f);
    tpGambarPratinjau(tpQrisBaru, true);
  };
  $('#tp-simpan-info').onclick = simpanInfoTopup;
  $('#tp-simpan-batas').onclick = simpanBatasTopup;

  muatAntreanTopup();
  muatPengaturanTopup();
  if (boleh('dana.lihat')) muatDanaAdmin();
}

/** `sumber` boleh nama berkas di uploads/ atau data URL yang belum tersimpan. */
function tpGambarPratinjau(sumber, belumSimpan) {
  const wadah = $('#tp-qris-pratinjau');
  if (!wadah) return;
  if (!sumber) {
    wadah.innerHTML = '<p class="muted">Belum ada gambar QRIS — metode QRIS belum ditawarkan ke orang tua.</p>';
    return;
  }
  const src = belumSimpan ? sumber : '/uploads/' + encodeURIComponent(sumber);
  wadah.innerHTML = `<img class="o-qris" src="${esc(src)}" alt="Kode QRIS sekolah">
    <p class="muted" style="text-align:center">
      ${belumSimpan ? 'Gambar baru — belum disimpan. ' : ''}
      <button class="linkbtn danger" id="tp-qris-copot">copot gambar QRIS</button></p>`;
  $('#tp-qris-copot').onclick = () => {
    tpQrisBaru = null;
    tpQrisSekarang = '';
    $('#tp-qris-file').value = '';
    tpGambarPratinjau('');
    toast('Gambar dicopot — tekan Simpan untuk menerapkan');
  };
}

/*
 * Lencananya ikut disegarkan dari sini.
 *
 * Sesudah admin menyetujui atau menolak, angkanya harus langsung turun. Kalau
 * hanya menunggu giliran pengecekan berikutnya, selama setengah menit lencana
 * masih menuntut tindakan yang BARU SAJA dikerjakan — dan itu cara tercepat
 * membuat orang berhenti mempercayai lencananya.
 */
async function muatAntreanTopup() {
  try {
    const d = await api('/api/topup-ortu' + (tpStatus ? '?status=' + tpStatus : ''));
    setLencanaTopup(d.n_pending);
    if (notifTerakhir) notifTerakhir.topup = d.n_pending;
    $('#tp-kartu').innerHTML = [
      ['Menunggu diperiksa', `${d.n_pending} pengajuan`],
      ['Nilai yang menunggu', rp(d.rp_pending)],
      ['Disetujui hari ini', `${d.n_hari_ini} pengajuan`],
      ['Nilai disetujui hari ini', rp(d.rp_hari_ini)],
    ].map(([l, v]) => `<div class="statcard"><span class="muted">${l}</span><br><b class="rp">${v}</b></div>`).join('');

    $('#tp-baris').innerHTML = d.pengajuan.map((p) => `
      <tr class="${p.status === 'pending' ? 'tp-menunggu' : ''}">
        <td style="white-space:nowrap">${esc(tpWaktu(p.created_at))}</td>
        <td><b>${esc(p.siswa_nama)}</b></td>
        <td>${esc(p.kelas || '—')}</td>
        <td class="num rp">${rp(p.jumlah)}</td>
        <td>${esc(TP_METODE[p.metode] || p.metode)}</td>
        <td style="white-space:nowrap">${esc(p.tgl_bayar)}</td>
        <td>${esc(p.pengirim || '—')}</td>
        <td>${p.bukti ? `<a href="/uploads/${encodeURIComponent(p.bukti)}" target="_blank" rel="noopener">lihat</a>` : '<span class="muted">—</span>'}</td>
        <td>${p.kembar > 1
          ? `<span class="badge tp-kembar" title="Ada ${p.kembar} pengajuan bernominal, tanggal, dan metode yang sama dari santri ini. Cocokkan dengan mutasi rekening sebelum menyetujui.">⚠ ${p.kembar}×</span>`
          : '<span class="muted">—</span>'}</td>
        <td><span class="badge tp-${esc(p.status)}">${esc(TP_STATUS[p.status] || p.status)}</span>
          ${p.status === 'ditolak' && p.alasan ? `<br><span class="muted">${esc(p.alasan)}</span>` : ''}
          ${p.status === 'disetujui' ? `<br><span class="muted">oleh ${esc(p.operator)}</span>` : ''}</td>
        <td style="white-space:nowrap">${p.status === 'pending'
          ? `<button class="linkbtn" data-setuju="${p.id}">Setujui</button>
             <button class="linkbtn danger" data-tolak="${p.id}">Tolak</button>`
          : ''}</td>
      </tr>`).join('') || '<tr><td colspan="11" class="muted">Belum ada pengajuan pada filter ini</td></tr>';

    const cari = (id) => d.pengajuan.find((x) => x.id === Number(id));
    $('#tp-baris').querySelectorAll('[data-setuju]').forEach((b) => {
      b.onclick = () => tpSetujuiModal(cari(b.dataset.setuju));
    });
    $('#tp-baris').querySelectorAll('[data-tolak]').forEach((b) => {
      b.onclick = () => tpTolakModal(cari(b.dataset.tolak));
    });
  } catch (e) { toast(e.message, 'err'); }
}

// Tarif dan sasarannya diatur di dua layar berbeda; petunjuk ini yang
// menyambungkannya, supaya nominal yang terisi tapi belum memungut siapa pun
// tidak terlihat seperti sudah berjalan.
function gambarPetunjukBiaya(s) {
  const el = $('#tp-biaya-hint');
  if (!el || !s) return;
  const ekor = [];
  if (s.bebas) ekor.push(`${s.bebas} kartu dibebaskan`);
  if (s.khusus) ekor.push(`${s.khusus} kartu bertarif khusus`);
  const rinci = ekor.length ? ` Di luar itu: ${ekor.join(', ')}.` : '';

  if (!s.golongan.length) {
    el.innerHTML = s.tarif
      ? `<b>Belum memungut siapa pun.</b> Nominal sudah terisi, tapi belum ada
         golongan yang dicentang. Buka <b>SDM &amp; Personil → Kelola Golongan</b>,
         ubah golongan yang dikehendaki, lalu centang
         <b>“dipungut biaya admin saat wali top up”</b>.${rinci}`
      : `Diisi 0 — tidak ada yang dipungut.${rinci}`;
    return;
  }
  const daftar = s.golongan.map(esc).join(', ');
  el.innerHTML = s.tarif
    ? `Dipungut dari golongan <b>${daftar}</b> pada top up lewat QRIS dan transfer.
       Top up massal, top up loket, dan transfer antar kartu tidak dipungut.${rinci}`
    : `Golongan <b>${daftar}</b> sudah dicentang, tapi nominalnya 0 —
       belum ada yang dipungut.${rinci}`;
}

async function muatPengaturanTopup() {
  try {
    const d = await api('/api/topup-ortu/pengaturan');
    $('#tp-aktif').value = String(d.pengaturan.topup_ortu_aktif);
    isiUang('#tp-min', d.pengaturan.topup_ortu_min);
    isiUang('#tp-maks', d.pengaturan.topup_ortu_maks);
    $('#tp-antre').value = d.pengaturan.topup_ortu_antre;
    isiUang('#tp-biaya', d.pengaturan.biaya_admin_topup_ortu ?? 0);
    gambarPetunjukBiaya(d.sasaran_biaya);
    $('#tp-qris-nama').value = d.info.qris_nama;
    $('#tp-qris-instruksi').value = d.info.qris_instruksi;
    $('#tp-bank-nama').value = d.info.bank_nama;
    $('#tp-bank-rek').value = d.info.bank_rekening;
    $('#tp-bank-an').value = d.info.bank_atasnama;
    $('#tp-bank-instruksi').value = d.info.bank_instruksi;
    tpQrisBaru = null;
    tpQrisSekarang = d.info.qris_gambar;
    tpGambarPratinjau(tpQrisSekarang);
  } catch (e) { toast(e.message, 'err'); }
}

async function simpanInfoTopup() {
  const btn = $('#tp-simpan-info');
  btn.disabled = true;
  try {
    await api('/api/topup-ortu/pengaturan', { method: 'PUT', body: {
      qris_nama: $('#tp-qris-nama').value,
      qris_instruksi: $('#tp-qris-instruksi').value,
      bank_nama: $('#tp-bank-nama').value,
      bank_rekening: $('#tp-bank-rek').value,
      bank_atasnama: $('#tp-bank-an').value,
      bank_instruksi: $('#tp-bank-instruksi').value,
      // Gambar QRIS punya tiga keadaan: diganti, dicopot, atau dibiarkan.
      ...(tpQrisBaru ? { qris_gambar_baru: tpQrisBaru }
        : tpQrisSekarang ? {} : { qris_gambar: '' }),
    } });
    $('#tp-qris-file').value = '';
    toast('✔ Instruksi pembayaran disimpan', 'ok');
    muatPengaturanTopup();
  } catch (e) { toast(e.message, 'err'); }
  btn.disabled = false;
}

async function simpanBatasTopup() {
  const btn = $('#tp-simpan-batas');
  btn.disabled = true;
  try {
    const angka = (sel) => Number(String($(sel).value).replace(/\D/g, ''));
    await api('/api/topup-ortu/pengaturan', { method: 'PUT', body: {
      topup_ortu_aktif: Number($('#tp-aktif').value),
      topup_ortu_min: nilaiUang('#tp-min'),
      topup_ortu_maks: nilaiUang('#tp-maks'),
      topup_ortu_antre: angka('#tp-antre'),
      biaya_admin_topup_ortu: nilaiUang('#tp-biaya') ?? 0,
    } });
    toast('✔ Batas pengajuan disimpan', 'ok');
    muatPengaturanTopup();
  } catch (e) { toast(e.message, 'err'); }
  btn.disabled = false;
}

// ---- konfirmasi ganda sebelum saldo bertambah ----
function tpSetujuiModal(p) {
  if (!p) return;
  openModal(`
    <h2>Setujui pengajuan?</h2>
    <p class="muted">Periksa dulu rinciannya terhadap mutasi rekening sekolah.</p>
    <div class="tp-konfirm">
      <dl>
        <dt>Santri</dt><dd>${esc(p.siswa_nama)}${p.kelas ? ' · ' + esc(p.kelas) : ''}</dd>
        <dt>Nominal</dt><dd>${rp(p.jumlah)}</dd>
        <dt>Metode</dt><dd>${esc(TP_METODE[p.metode] || p.metode)}</dd>
        <dt>Tanggal bayar</dt><dd>${esc(p.tgl_bayar)}</dd>
        <dt>Pengirim</dt><dd>${esc(p.pengirim || '—')}</dd>
        <dt>Diajukan</dt><dd>${esc(tpWaktu(p.created_at))}</dd>
      </dl>
      ${p.kembar > 1 ? `<p class="tp-peringatan">⚠ Santri ini punya <b>${p.kembar} pengajuan</b>
        dengan nominal, tanggal bayar, dan metode yang sama. Pastikan uangnya memang masuk
        sebanyak itu di mutasi rekening — satu transfer hanya boleh disetujui sekali.</p>` : ''}
      ${p.bukti
        ? `<img class="tp-bukti-besar" src="/uploads/${encodeURIComponent(p.bukti)}"
             alt="Bukti pembayaran" data-bukti-gambar>
           <p class="tp-bukti-rusak hidden">⚠ Gambar buktinya tidak bisa dibuka — berkasnya rusak
             atau terpotong saat diunggah. <b>Jangan setujui berdasarkan gambar ini.</b>
             Minta wali mengunggah ulang, atau cocokkan langsung dengan mutasi rekening.</p>`
        : '<p class="muted" style="margin:10px 0 0">Tidak ada bukti dilampirkan.</p>'}
    </div>`,
    () => tpKonfirmasiKedua(p), 'Lanjut ›');

  /*
   * Gambar yang gagal dimuat harus BERSUARA. Tanpa ini yang tampil hanya ikon
   * gambar rusak kecil milik peramban, dan admin yang sedang buru-buru mudah
   * membacanya sebagai "bukti ada" lalu menyetujui penambahan saldo tanpa
   * pernah benar-benar melihat buktinya.
   */
  const img = document.querySelector('[data-bukti-gambar]');
  if (img) {
    img.onerror = () => {
      img.classList.add('hidden');
      document.querySelector('.tp-bukti-rusak')?.classList.remove('hidden');
    };
  }
}

function tpKonfirmasiKedua(p) {
  // openModal menutup modal ini sendiri setelah handler-nya selesai, jadi modal
  // kedua sengaja dibuka pada tick berikutnya — kalau tidak, ia ikut terhapus.
  setTimeout(() => openModal(`
    <h2>Konfirmasi terakhir</h2>
    <p>Saldo <b>${esc(p.siswa_nama)}</b> akan langsung bertambah <b>${rp(p.jumlah)}</b>,
       tercatat sebagai top up atas nama Anda.</p>
    <label><input type="checkbox" id="m-ke-titipan" checked style="width:auto">
      Simpan kelebihan sebagai saldo titipan</label>
    <span class="hint-lembut">Kalau nominalnya tidak tertampung kartu (batas saldo atau jatah
      top up harian), sisanya mengendap di titipan dan otomatis mengisi kartu saat saldo menipis.
      Tanpa ini, persetujuan akan gagal dan pengajuan tetap menunggu.</span>
    <p class="muted">Setelah ini pembatalan hanya bisa lewat pemotongan saldo manual
       di menu <b>Saldo Santri</b>.</p>`,
    async () => {
      const d = await api(`/api/topup-ortu/${p.id}/setujui`,
        { method: 'POST', body: { konfirmasi: true, ke_titipan: !!$('#m-ke-titipan').checked } });
      toast(d.ke_titipan > 0
        ? `✔ ${rp(d.ke_saldo)} ke kartu, ${rp(d.ke_titipan)} ke titipan — saldo ${d.siswa.nama} kini ${rp(d.siswa.saldo)}`
        : `✔ Saldo ${d.siswa.nama} kini ${rp(d.siswa.saldo)}`, 'ok');
      muatAntreanTopup();
    }, 'Ya, setujui & tambah saldo'), 0);
}

function tpTolakModal(p) {
  if (!p) return;
  openModal(`
    <h2>Tolak pengajuan</h2>
    <p class="muted">${esc(p.siswa_nama)} · ${rp(p.jumlah)} ·
      ${esc(TP_METODE[p.metode] || p.metode)} · bayar ${esc(p.tgl_bayar)}</p>
    <div class="field"><label>Alasan penolakan — dibaca orang tua di portal</label>
      <textarea id="m-alasan" rows="3" maxlength="300"
        placeholder="mis. dana belum masuk ke rekening sekolah"></textarea></div>`,
    async () => {
      await api(`/api/topup-ortu/${p.id}/tolak`,
        { method: 'POST', body: { alasan: $('#m-alasan').value } });
      toast('Pengajuan ditolak', 'ok');
      muatAntreanTopup();
    }, 'Tolak pengajuan');
}

// ========================================================== init
// Layar antara (#boot) tetap tampil sampai status sesi diketahui — mencegah
// form login berkedip sesaat saat refresh dalam keadaan sudah login.
api('/api/me')
  .then((d) => { if (d.authed) { USER = d.user; showApp(); } else showLogin(); })
  .catch(() => showLogin());

// ╔════════════════════════════════════════════════════════════════════════╗
// ║ KELOLA AKUN — akun pengguna & kategori akses                           ║
// ╚════════════════════════════════════════════════════════════════════════╝
// Kategori akses menggantikan peran tetap yang dulu ditulis di kode. Satu
// kategori memegang sekumpulan sakelar fitur, dan tiap akun sisi admin memilih
// satu kategori. Granularitasnya per AKSI — "lihat" terpisah dari "tambah",
// "ubah", dan "hapus" — supaya pembatasan yang muncul kemudian cukup dimatikan
// lewat sakelar, tanpa menyentuh kode lagi.
//
// Yang digambar di sini hanyalah cermin: setiap sakelar punya pasangannya di
// server (middleware `izin:`), dan itulah yang benar-benar menutup endpoint.

let akses = { akun: [], kategori: [], katalog: [], pilih: new Set() };

function akunView(el) {
  const bolehKelolaAkun = boleh('akun.kelola');
  const bolehKelolaAkses = boleh('akses.kelola');

  el.innerHTML = `
  <div class="panel">
    <div class="kat-head">
      <h2 style="margin:0">Akun Pengguna <span class="muted" id="ak-count"></span></h2>
      <div style="display:flex; gap:10px">
        <button class="linkbtn" id="ak-sandi">🔑 ganti sandi saya</button>
        ${bolehKelolaAkun ? '<button class="linkbtn" id="ak-tambah">+ Tambah akun</button>' : ''}
      </div>
    </div>
    <p class="muted">Akun <b>kasir</b> tidak diurus di sini — satu akun kasir melekat pada satu
      tenant dan dibuat bersama tenantnya di menu <b>Kantin → Tenant</b>. Yang bisa ditambah di
      layar ini adalah akun sisi admin, dan hak masing-masing ditentukan kategori aksesnya.</p>
    <div class="tblwrap"><table>
      <thead><tr><th>Nama</th><th>Username</th><th>Kategori Akses</th><th>Jenis</th><th></th></tr></thead>
      <tbody id="ak-body"><tr><td colspan="5" class="muted">memuat…</td></tr></tbody>
    </table></div>
  </div>

  ${bolehKelolaAkses ? `<div class="panel">
    <div class="kat-head">
      <h2 style="margin:0">Kategori Akses</h2>
      <button class="linkbtn" id="ka-tambah">+ Kategori baru</button>
    </div>
    <p class="muted">Tiap kategori adalah sekumpulan sakelar. Matikan sebuah sakelar dan menunya
      hilang dari layar pemegang kategori itu <i>sekaligus</i> endpoint-nya ditutup di server —
      bukan sekadar tombolnya disembunyikan.</p>
    <div id="ka-list"><p class="muted">memuat…</p></div>
  </div>` : ''}`;

  if ($('#ak-tambah')) $('#ak-tambah').onclick = () => akunModal(null);
  if ($('#ka-tambah')) $('#ka-tambah').onclick = () => kategoriAksesModal(null);
  $('#ak-sandi').onclick = gantiSandiModal;

  muatAkun();
  if (bolehKelolaAkses) muatKategoriAkses();
}

async function muatAkun() {
  let d;
  try { d = await api('/api/akun'); }
  catch (e) { $('#ak-body').innerHTML = `<tr><td colspan="5" class="minus">${esc(e.message)}</td></tr>`; return; }

  akses.akun = d.akun;
  akses.kategoriRingkas = d.kategori;
  const admin = d.akun.filter((a) => a.role === 'superadmin');
  $('#ak-count').textContent = `— ${admin.length} akun admin · ${d.akun.length - admin.length} akun kasir`;

  $('#ak-body').innerHTML = d.akun.map((a) => {
    const kasir = a.role === 'tenant';
    return `<tr>
      <td><b>${esc(a.nama)}</b></td>
      <td><code>${esc(a.username)}</code></td>
      <td>${kasir
        ? `<span class="muted">— (kasir ${esc(a.tenant_nama || '?')})</span>`
        : esc(a.kategori_nama || '—')}</td>
      <td><span class="badge ${kasir ? 'nonaktif' : 'aktif'}">${kasir ? 'kasir' : 'admin'}</span></td>
      <td style="white-space:nowrap">${kasir
        ? '<span class="muted">lewat menu Tenant</span>'
        : (boleh('akun.kelola')
          ? `<button class="linkbtn" data-akun-ubah="${a.id}">ubah</button> ·
             <button class="linkbtn danger" data-akun-hapus="${a.id}">hapus</button>`
          : '<span class="muted">—</span>')}</td>
    </tr>`;
  }).join('');

  document.querySelectorAll('#ak-body [data-akun-ubah]').forEach((b) => {
    b.onclick = () => akunModal(akses.akun.find((x) => x.id === Number(b.dataset.akunUbah)));
  });
  document.querySelectorAll('#ak-body [data-akun-hapus]').forEach((b) => {
    b.onclick = () => hapusAkunModal(akses.akun.find((x) => x.id === Number(b.dataset.akunHapus)));
  });
}

function akunModal(a) {
  const kategori = akses.kategoriRingkas || [];
  openModal(`
    <h2>${a ? 'Ubah Akun' : 'Tambah Akun Admin'}</h2>
    <div class="field"><label>Nama lengkap</label>
      <input id="au-nama" value="${esc(a?.nama || '')}" placeholder="mis. Ustadz Ahmad"></div>
    <div class="field"><label>Username</label>
      <input id="au-user" value="${esc(a?.username || '')}" placeholder="huruf kecil, tanpa spasi">
      <span class="muted set-hint">3–30 karakter: huruf kecil, angka, titik, strip, garis bawah.</span></div>
    <div class="field"><label>${a ? 'Sandi baru <span class="muted">(kosongkan bila tidak diganti)</span>' : 'Sandi'}</label>
      <input id="au-sandi" type="text" autocomplete="new-password" placeholder="minimal 8 karakter">
      <span class="muted set-hint">Minimal 8 karakter. Sandi ditulis terbaca di sini supaya bisa
        dicatat dan diserahkan — gantilah setelah akunnya dipakai pertama kali.</span></div>
    <div class="field"><label>Kategori akses</label>
      <select id="au-kategori">${kategori.map((k) =>
        `<option value="${k.id}" ${k.id === a?.kategori_id ? 'selected' : ''}>${esc(k.nama)}</option>`).join('')}</select>
      <span class="muted set-hint">Kategori inilah yang menentukan menu dan aksi apa saja yang
        bisa dijalankan akun ini.</span></div>
    ${a ? `<p class="muted">Mengganti sandi, username, atau kategori akan <b>menutup sesi</b> akun
      ini di semua peramban — pemegangnya harus masuk lagi.</p>` : ''}`,
    async () => {
      const body = {
        nama: $('#au-nama').value,
        username: $('#au-user').value,
        kategori_id: Number($('#au-kategori').value),
      };
      if ($('#au-sandi').value) body.password = $('#au-sandi').value;
      if (a) await api('/api/akun/' + a.id, { method: 'PUT', body });
      else await api('/api/akun', { method: 'POST', body });
      toast('✔ Akun tersimpan', 'ok');
      muatAkun();
      if (boleh('akses.kelola')) muatKategoriAkses();
    }, a ? 'Simpan' : 'Buat akun');
}

function hapusAkunModal(a) {
  openModal(`
    <h2>Hapus akun ${esc(a.nama)}?</h2>
    <p>Akun <code>${esc(a.username)}</code> (${esc(a.kategori_nama || '—')}) akan dihapus dan
      sesinya ditutup. Riwayat transaksi yang sudah tercatat atas namanya <b>tidak ikut hilang</b> —
      nama operator disimpan sebagai teks di tiap transaksi.</p>
    <p class="muted">Kalau yang bersangkutan hanya berhenti sementara, lebih baik pindahkan
      kategorinya ke yang tanpa hak apa pun daripada dihapus.</p>`,
    async () => {
      await api('/api/akun/' + a.id, { method: 'DELETE' });
      toast('✔ Akun dihapus', 'ok');
      muatAkun();
    }, 'Hapus akun');
}

function gantiSandiModal() {
  openModal(`
    <h2>Ganti Sandi Saya</h2>
    <p class="muted">Berlaku untuk akun <b>${esc(USER.nama)}</b> (<code>${esc(USER.role)}</code>).
      Sesi Anda di peramban lain akan ditutup, sesi yang sedang dipakai ini tetap hidup.</p>
    <div class="field"><label>Sandi lama</label>
      <input id="gs-lama" type="password" autocomplete="current-password"></div>
    <div class="field"><label>Sandi baru</label>
      <input id="gs-baru" type="password" autocomplete="new-password" placeholder="minimal 8 karakter"></div>
    <div class="field"><label>Ulangi sandi baru</label>
      <input id="gs-ulang" type="password" autocomplete="new-password"></div>`,
    async () => {
      if ($('#gs-baru').value !== $('#gs-ulang').value) throw new Error('Ulangan sandi baru tidak sama');
      const d = await api('/api/akun/saya/sandi', { method: 'PUT', body: {
        password_lama: $('#gs-lama').value, password_baru: $('#gs-baru').value,
      }});
      toast('✔ ' + d.pesan, 'ok');
    }, 'Ganti sandi');
}

// ------------------------------------------------------- kategori akses

async function muatKategoriAkses() {
  let d, k;
  try {
    [d, k] = await Promise.all([api('/api/akses/kategori'), api('/api/akses/katalog')]);
  } catch (e) {
    $('#ka-list').innerHTML = `<p class="minus">${esc(e.message)}</p>`;
    return;
  }
  akses.kategori = d.kategori;
  akses.katalog = k.katalog;
  const totalFitur = k.katalog.reduce((n, g) => n + g.fitur.length, 0);

  $('#ka-list').innerHTML = d.kategori.map((x) => `
    <div class="gol-baris">
      <div class="gol-kepala">
        <b>${esc(x.nama)}</b>
        ${x.terkunci ? '<span class="badge aktif">terkunci</span>' : ''}
        ${x.bawaan && !x.terkunci ? '<span class="badge nonaktif">bawaan</span>' : ''}
        <span class="muted">${x.fitur.length} / ${totalFitur} fitur · ${x.n_akun} akun</span>
        <span style="margin-left:auto; white-space:nowrap">
          <button class="linkbtn" data-ka-lihat="${x.id}">${x.terkunci ? 'lihat' : 'ubah'}</button>
          ${x.terkunci ? '' : ` · <button class="linkbtn danger" data-ka-hapus="${x.id}">hapus</button>`}
        </span>
      </div>
      <i>${esc(x.deskripsi || 'Tanpa keterangan.')}</i>
    </div>`).join('');

  document.querySelectorAll('#ka-list [data-ka-lihat]').forEach((b) => {
    b.onclick = () => kategoriAksesModal(akses.kategori.find((x) => x.id === Number(b.dataset.kaLihat)));
  });
  document.querySelectorAll('#ka-list [data-ka-hapus]').forEach((b) => {
    b.onclick = () => hapusKategoriModal(akses.kategori.find((x) => x.id === Number(b.dataset.kaHapus)));
  });
}

/**
 * Penyunting sakelar. Kategori terkunci dibuka dalam keadaan HANYA BACA:
 * ia yang menjamin selalu ada akun yang bisa membuka layar ini kembali,
 * jadi tidak ada satu pun jalan untuk mengubahnya dari antarmuka.
 */
function kategoriAksesModal(k) {
  const kunci = !!k?.terkunci;
  const punya = new Set(k?.fitur || []);

  const grid = akses.katalog.map((g, gi) => `
    <fieldset class="izin-grup">
      <legend>${esc(g.grup)}
        ${kunci ? '' : `<span class="izin-aksi">
          <button type="button" class="linkbtn" data-grup-on="${gi}">semua</button> ·
          <button type="button" class="linkbtn" data-grup-off="${gi}">tidak satu pun</button></span>`}
      </legend>
      ${g.fitur.map((f) => `
        <label class="izin-baris">
          <input type="checkbox" data-fitur="${esc(f.kode)}"
            ${punya.has(f.kode) ? 'checked' : ''} ${kunci ? 'disabled' : ''}>
          <span>${esc(f.label)}</span>
          <code>${esc(f.kode)}</code>
        </label>`).join('')}
    </fieldset>`).join('');

  openModal(`
    <h2>${k ? (kunci ? 'Kategori: ' : 'Ubah Kategori: ') + esc(k.nama) : 'Kategori Akses Baru'}</h2>
    ${kunci ? `<p class="preview">Kategori ini <b>dikunci</b> dan selalu memegang seluruh fitur —
      termasuk fitur yang ditambahkan pada pembaruan berikutnya. Inilah yang menjamin sekolah tidak
      pernah terkunci keluar dari pengelolaan aksesnya sendiri, jadi ia tidak bisa disunting
      maupun dihapus.</p>` : ''}
    <div class="field"><label>Nama kategori</label>
      <input id="kf-nama" value="${esc(k?.nama || '')}" ${kunci ? 'disabled' : ''}
        placeholder="mis. Bendahara Pondok" maxlength="60"></div>
    <div class="field"><label>Keterangan</label>
      <input id="kf-desk" value="${esc(k?.deskripsi || '')}" ${kunci ? 'disabled' : ''}
        placeholder="untuk siapa kategori ini" maxlength="300"></div>
    <div class="izin-grid">${grid}</div>`,
    kunci ? null : async () => {
      const fitur = [...document.querySelectorAll('.izin-grid [data-fitur]')]
        .filter((c) => c.checked).map((c) => c.dataset.fitur);
      const body = { nama: $('#kf-nama').value, deskripsi: $('#kf-desk').value, fitur };
      if (k) await api('/api/akses/kategori/' + k.id, { method: 'PUT', body });
      else await api('/api/akses/kategori', { method: 'POST', body });
      toast('✔ Kategori tersimpan', 'ok');
      muatKategoriAkses();
      muatAkun();
    }, 'Simpan kategori');

  if (kunci) return;
  const kotak = (gi) => {
    const set = document.querySelectorAll('.izin-grid fieldset')[gi].querySelectorAll('[data-fitur]');
    return [...set];
  };
  document.querySelectorAll('[data-grup-on]').forEach((b) => {
    b.onclick = () => kotak(Number(b.dataset.grupOn)).forEach((c) => { c.checked = true; });
  });
  document.querySelectorAll('[data-grup-off]').forEach((b) => {
    b.onclick = () => kotak(Number(b.dataset.grupOff)).forEach((c) => { c.checked = false; });
  });
}

function hapusKategoriModal(k) {
  openModal(`
    <h2>Hapus kategori ${esc(k.nama)}?</h2>
    ${k.n_akun
      ? `<p class="minus">Masih ada <b>${k.n_akun} akun</b> memakai kategori ini. Pindahkan
         akunnya dulu ke kategori lain — kalau tidak, hak mereka justru akan melebar.</p>`
      : '<p>Kategori ini belum dipakai akun mana pun, jadi aman dihapus.</p>'}`,
    k.n_akun ? null : async () => {
      await api('/api/akses/kategori/' + k.id, { method: 'DELETE' });
      toast('✔ Kategori dihapus', 'ok');
      muatKategoriAkses();
    }, 'Hapus kategori');
}

// ╔════════════════════════════════════════════════════════════════════════╗
// ║ IMPOR KOLEKTIF PEMEGANG KARTU — santri & SDM dari berkas Excel        ║
// ╚════════════════════════════════════════════════════════════════════════╝
// Alurnya SELALU dua langkah dan sengaja sama dengan Topup Massal: berkas
// diperiksa lebih dulu (tidak ada data yang dibuat), baru diterapkan. Berkas
// yang sama dikirim dua kali dan diurai ULANG di server, dengan sidik SHA-256
// sebagai pengikat bahwa keduanya memang berkas yang sama — admin tidak mungkin
// memeriksa satu berkas lalu menerapkan berkas lain.

let imporKartu = null;

function imporKartuPanel(sdm) {
  const jenis = sdm ? 'sdm' : 'santri';
  const t = ISTILAH(sdm);
  imporKartu = null;

  const kolom = sdm
    ? 'No · NIY/Nomor Pegawai · Nama Personil · Unit · Golongan · Jabatan · Berlaku Sampai'
    : 'No · NIS · Nama Santri · Kelas · NISN · Tanggal Lahir · No HP Wali';

  $('#sw-impor').innerHTML = `
  <div class="panel" style="border-left:4px solid var(--gold)">
    <div class="kat-head">
      <h2 style="margin:0">Tambah ${esc(t.orang)} kolektif dari Excel</h2>
      <button class="linkbtn" id="im-tutup">tutup ✕</button>
    </div>

    <p class="muted"><b>1 · Unduh template.</b> Kolomnya: <b>${esc(kolom)}</b>.
      Jangan mengubah baris judul — kolom dikenali dari judulnya, bukan urutannya.
      Template sudah memuat pemeriksa isi sel dan satu lembar <b>Petunjuk</b>.</p>
    <div class="filterbar">
      <button class="go" id="im-tpl">⬇ Template ${esc(t.orang)} (.xlsx)</button>
    </div>

    <p class="muted"><b>2 · Unggah &amp; periksa.</b> Tidak ada data yang dibuat pada langkah ini.</p>
    <div class="field"><label>Berkas .xlsx atau .csv (maks 4MB, 1000 baris)</label>
      <input type="file" id="im-file"
        accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"></div>
    <p class="muted">${esc(t.nomorSingkat)} <b>wajib diisi</b> dan harus khas. Nomor yang sudah
      terdaftar ditolak — itulah yang membuat berkas yang tidak sengaja diunggah dua kali
      tidak pernah menghasilkan kartu kembar.</p>
    <div style="margin-top:10px"><button class="go" id="im-periksa" disabled>Periksa berkas</button></div>

    <div id="im-hasil"></div>
  </div>`;

  const unduh = (url) => {
    const a = document.createElement('a');
    a.href = url;
    document.body.appendChild(a);
    a.click();
    a.remove();
  };
  $('#im-tpl').onclick = () => unduh('/api/impor-kartu/template?jenis=' + jenis);
  $('#im-tutup').onclick = () => { $('#sw-impor').innerHTML = ''; imporKartu = null; };
  $('#im-file').addEventListener('change', () => {
    $('#im-periksa').disabled = !$('#im-file').files[0];
    $('#im-hasil').innerHTML = '';
    imporKartu = null;
  });
  $('#im-periksa').onclick = () => imporKartuPratinjau(jenis);
  $('#sw-impor').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function imporKartuPratinjau(jenis) {
  const f = $('#im-file').files[0];
  if (!f) return;
  const btn = $('#im-periksa');
  btn.disabled = true;
  $('#im-hasil').innerHTML = '<p class="muted">membaca berkas…</p>';
  try {
    const data = await fileToDataUrl(f);
    const pra = await api('/api/impor-kartu/pratinjau', { method: 'POST', body: {
      jenis, nama_berkas: f.name, data,
    }});
    imporKartu = { jenis, nama: f.name, data, pra };
    imporKartuRender();
  } catch (e) {
    $('#im-hasil').innerHTML = `<p class="minus">${esc(e.message)}</p>
      <p class="muted">Pakai template resmi dari langkah 1, dan pastikan baris judul kolom
        masih ada di lembar yang sama dengan datanya.</p>`;
  }
  btn.disabled = false;
}

function imporKartuRender() {
  const { pra, jenis } = imporKartu;
  const r = pra.ringkas;
  const sdm = jenis === 'sdm';
  const t = ISTILAH(sdm);
  const unit = Object.entries(r.per_unit).map(([u, n]) => `${esc(u)}: <b>${n}</b>`).join(' · ');

  $('#im-hasil').innerHTML = `
    <div class="panel" style="margin:12px 0 0">
      <h2>3 · Hasil pemeriksaan <span class="muted">— lembar ${esc(pra.lembar)}</span></h2>
      <div class="cards">
        <div class="statcard"><span class="muted">Baris terbaca</span><br><b>${r.n_baris}</b></div>
        <div class="statcard"><span class="muted">Siap dibuat</span><br><b class="plus">${r.n_siap}</b></div>
        <div class="statcard"><span class="muted">Gagal</span><br><b class="${r.n_gagal ? 'minus' : ''}">${r.n_gagal}</b></div>
      </div>
      ${unit ? `<p class="muted">Sebaran ${esc(t.unit.toLowerCase())} — ${unit}</p>` : ''}
      ${r.n_catatan ? `<p class="muted">${r.n_catatan} baris punya catatan (lihat kolom keterangan).
        Baris bercatatan tetap dibuat.</p>` : ''}

      <div class="tblwrap"><table>
        <thead><tr><th>Baris</th><th></th><th>${esc(t.nomorSingkat)}</th><th>Nama</th>
        <th>${esc(t.unit)}</th>${sdm ? '<th>Golongan</th>' : '<th>Tgl lahir</th>'}<th>Keterangan</th></tr></thead>
        <tbody>${pra.baris.map((b) => `<tr>
          <td>${b.baris}</td>
          <td><span class="badge ${b.status === 'siap' ? 'aktif' : 'blokir'}">${b.status}</span></td>
          <td><code>${esc(b.nis || '—')}</code></td>
          <td>${esc(b.nama || '—')}</td>
          <td>${esc(b.kelas || '—')}</td>
          <td>${sdm ? esc(b.golongan) : esc(b.tgl_lahir || '—')}</td>
          <td class="${b.status === 'siap' ? 'muted' : 'minus'}">${esc(b.pesan || '')}</td>
        </tr>`).join('')}</tbody>
      </table></div>

      ${r.n_siap
        ? `<div style="margin-top:12px">
             <button class="go" id="im-terapkan">Terapkan — buat ${r.n_siap} ${esc(t.orang)}</button>
             <span class="hint-lembut">Baris yang gagal dilewati; sisanya tetap dibuat.</span>
           </div>`
        : '<p class="minus" style="margin-top:12px">Tidak ada baris yang siap — perbaiki berkasnya lalu periksa lagi.</p>'}
    </div>`;

  if ($('#im-terapkan')) $('#im-terapkan').onclick = imporKartuTerapkan;
}

async function imporKartuTerapkan() {
  const { jenis, nama, data, pra } = imporKartu;
  const t = ISTILAH(jenis === 'sdm');
  const btn = $('#im-terapkan');
  btn.disabled = true;
  btn.textContent = 'membuat…';
  try {
    const d = await api('/api/impor-kartu/terapkan', { method: 'POST', body: {
      jenis, nama_berkas: nama, data, sidik: pra.sidik,
    }});
    toast(`✔ ${d.n_dibuat} ${t.orang} dibuat`, 'ok');
    $('#im-hasil').innerHTML = `
      <div class="panel" style="margin:12px 0 0">
        <h2>Selesai</h2>
        <div class="cards">
          <div class="statcard"><span class="muted">Berhasil dibuat</span><br><b class="plus">${d.n_dibuat}</b></div>
          <div class="statcard"><span class="muted">Dilewati</span><br><b class="${d.n_gagal ? 'minus' : ''}">${d.n_gagal}</b></div>
        </div>
        <p class="muted">Kartu NFC belum terpasang — tempelkan kartunya satu per satu lewat tombol
          <b>kartu</b> di tabel. PIN kios bawaan setiap orang adalah ${esc(t.nomorSingkat)}-nya.</p>
        ${d.gagal.length ? `<div class="tblwrap"><table>
          <thead><tr><th>Baris</th><th>${esc(t.nomorSingkat)}</th><th>Nama</th><th>Sebab gagal</th></tr></thead>
          <tbody>${d.gagal.map((b) => `<tr><td>${b.baris}</td><td><code>${esc(b.nis || '—')}</code></td>
            <td>${esc(b.nama || '—')}</td><td class="minus">${esc(b.pesan)}</td></tr>`).join('')}</tbody>
        </table></div>` : ''}
      </div>`;
    $('#im-file').value = '';
    imporKartu = null;
    loadSiswa();
  } catch (e) {
    toast(e.message, 'err');
    btn.disabled = false;
    btn.textContent = 'Coba terapkan lagi';
  }
}

// ===========================================================================
// KARTU RFID KOLEKTIF — dua opsi: tap berturut-turut & template Excel
// ===========================================================================
/*
 * Opsi A (tap) selalu aman: UID datang langsung dari reader, tidak ada yang
 * diketik. Admin memilih kelas/unit dulu supaya daftar "menunggu kartu"-nya
 * jelas, lalu tinggal tap-tap-tap kartu berturut-turut — setiap tap mengisi
 * baris TARGET (baris pertama yang masih menunggu, atau baris yang sengaja
 * diklik) dan otomatis maju ke baris berikutnya. Baru dikirim ke server saat
 * admin menekan "Terapkan Semua", supaya tap yang salah tempel masih bisa
 * dibatalkan dulu di layar.
 *
 * Opsi B (template Excel) hanya masuk akal kalau UID kartunya sudah diketahui
 * SEBELUM ditempel ke reader (tercetak di kartu, atau dicatat sekolah dari
 * vendor) — pola dua-langkahnya (pratinjau lalu terapkan) disamakan dengan
 * Impor Excel & Topup Massal.
 */
let kartuMassalState = null;

function kartuMassalPanel(sdm) {
  const jenis = sdm ? 'sdm' : 'santri';
  const t = ISTILAH(sdm);
  kartuMassalState = { sdm, jenis, tapRows: [], tapTarget: null, tapBatch: crypto.randomUUID(), excel: null };

  $('#sw-tahun').innerHTML = '';
  $('#sw-impor').innerHTML = `
  <div class="panel" style="border-left:4px solid var(--gold)">
    <div class="kat-head">
      <h2 style="margin:0">Daftarkan kartu RFID ${esc(t.orang)} — kolektif</h2>
      <button class="linkbtn" id="km-tutup">tutup ✕</button>
    </div>
    <p class="muted">Dua cara mendaftarkan banyak kartu sekaligus. <b>Tap Berturut-turut</b> paling aman
      dan cocok untuk hampir semua sekolah — UID dibaca langsung dari reader, tidak ada yang diketik.
      <b>Template Excel</b> hanya berguna kalau UID kartu Anda sudah tercetak/diketahui sebelum
      ditempel ke reader.</p>

    <div class="kat-head" style="margin-top:6px"><h3 style="margin:0">A · Tap Berturut-turut</h3></div>
    <div class="filterbar">
      <div class="field"><label>${sdm ? 'Unit' : 'Kelas'}</label><select id="km-tap-kelas"></select></div>
      <div class="field" style="align-self:flex-end"><button class="go" id="km-tap-muat">Muat daftar</button></div>
    </div>
    <label style="display:flex; gap:6px; align-items:center; font-size:13px; margin:6px 0">
      <input type="checkbox" id="km-tap-ganti"> Izinkan menimpa kartu yang sudah terdaftar
    </label>
    <div id="km-tap-hasil"><p class="muted">Pilih ${sdm ? 'unit' : 'kelas'} lalu klik "Muat daftar" untuk mulai.</p></div>

    <div class="kat-head" style="margin-top:26px"><h3 style="margin:0">B · Template Excel</h3></div>
    <p class="muted">Hanya untuk kartu yang UID-nya sudah Anda ketahui/catat di luar aplikasi.
      Template terisi otomatis dengan ${esc(t.orang)} yang belum berkartu.</p>
    <div class="filterbar">
      <button class="go" id="km-tpl">⬇ Template ${esc(t.orang)} (.xlsx)</button>
    </div>
    <div class="field"><label>Berkas .xlsx atau .csv (maks 4MB)</label>
      <input type="file" id="km-file"
        accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"></div>
    <label style="display:flex; gap:6px; align-items:center; font-size:13px; margin:6px 0">
      <input type="checkbox" id="km-excel-ganti"> Izinkan menimpa kartu yang sudah terdaftar
    </label>
    <div style="margin-top:6px"><button class="go" id="km-periksa" disabled>Periksa berkas</button></div>
    <div id="km-excel-hasil"></div>
  </div>`;

  // Daftar kelas/unit dipinjam dari filter tabel utama (sudah terisi saat
  // layar Santri/SDM dimuat) — tidak perlu memanggil /api/kelas lagi.
  $('#km-tap-kelas').innerHTML = $('#sw-kelas').innerHTML;

  $('#km-tutup').onclick = () => { $('#sw-impor').innerHTML = ''; cardHandler = null; kartuMassalState = null; };
  $('#km-tap-muat').onclick = () => kartuTapMuat();
  $('#km-tpl').onclick = () => {
    const a = document.createElement('a');
    a.href = '/api/kartu-massal/template?jenis=' + jenis;
    document.body.appendChild(a);
    a.click();
    a.remove();
  };
  $('#km-file').addEventListener('change', () => {
    $('#km-periksa').disabled = !$('#km-file').files[0];
    $('#km-excel-hasil').innerHTML = '';
    kartuMassalState.excel = null;
  });
  $('#km-periksa').onclick = () => kartuExcelPratinjau();
  $('#sw-impor').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function kartuTapMuat() {
  const st = kartuMassalState;
  if (!st) return;
  $('#km-tap-hasil').innerHTML = '<p class="muted">memuat…</p>';
  try {
    const p = new URLSearchParams({ limit: 500, status: 'aktif', golongan: st.sdm ? kodeSdm() : 'siswa' });
    const kelas = $('#km-tap-kelas').value;
    if (kelas) p.set('kelas', kelas);
    const d = await api('/api/siswa?' + p);
    st.tapRows = (d.siswa || []).filter((s) => !s.card_uid).map((s) => ({
      siswa_id: s.id, nama: s.nama, kelas: s.kelas, nis: s.nis, uid: '', status: 'menunggu', pesan: '',
    }));
    st.tapTarget = null;
    // Reader RFID mengetik seperti keyboard biasa — mendaftarkan handler ini
    // membuat SETIAP tap di halaman ini (bukan cuma saat sebuah field fokus)
    // langsung mengisi baris target, persis mekanisme yang sama dipakai modal
    // "Hubungkan Kartu" satuan.
    cardHandler = (uid) => kartuTapOnScan(uid);
    kartuTapRender();
  } catch (e) {
    $('#km-tap-hasil').innerHTML = `<p class="minus">${esc(e.message)}</p>`;
  }
}

function kartuTapRender() {
  const st = kartuMassalState;
  if (!st) return;
  const rows = st.tapRows;
  if (! rows.length) {
    $('#km-tap-hasil').innerHTML = '<p class="muted">Tidak ada yang belum berkartu di pilihan ini.</p>';
    return;
  }
  const siap = rows.filter((r) => r.status === 'siap').length;
  const selesai = rows.filter((r) => r.status === 'berhasil').length;
  const gagalN = rows.filter((r) => r.status === 'gagal').length;
  const menunggu = rows.filter((r) => r.status === 'menunggu').length;
  const target = (st.tapTarget && rows.find((r) => r.siswa_id === st.tapTarget && r.status === 'menunggu'))
    || rows.find((r) => r.status === 'menunggu') || null;

  $('#km-tap-hasil').innerHTML = `
    <div class="cards" style="margin:10px 0">
      <div class="statcard"><span class="muted">Menunggu</span><br><b>${menunggu}</b></div>
      <div class="statcard"><span class="muted">Siap diterapkan</span><br><b class="plus">${siap}</b></div>
      <div class="statcard"><span class="muted">Tersimpan</span><br><b class="plus">${selesai}</b></div>
      <div class="statcard"><span class="muted">Gagal</span><br><b class="${gagalN ? 'minus' : ''}">${gagalN}</b></div>
    </div>
    ${target
      ? `<p class="hint-lembut">Tempelkan kartu untuk <b>${esc(target.nama)}</b> sekarang — atau klik baris
           lain di bawah untuk memindahkan target.</p>`
      : (menunggu === 0 ? '<p class="muted">Semua baris sudah dapat kartu. Tekan "Terapkan Semua" di bawah.</p>' : '')}
    <div class="field" style="max-width:280px"><label>Atau ketik UID manual</label>
      <input id="km-tap-manual" placeholder="lalu tekan Enter"></div>
    <div class="tblwrap"><table>
      <thead><tr><th></th><th>Nama</th><th>${st.sdm ? 'Unit' : 'Kelas'}</th><th>${st.sdm ? 'NIY' : 'NIS'}</th>
      <th>UID</th><th>Keterangan</th></tr></thead>
      <tbody>${rows.map((r) => `<tr data-id="${r.siswa_id}"
          style="${target && r.siswa_id === target.siswa_id ? 'background:#FDF6E3;' : ''}${r.status === 'menunggu' ? 'cursor:pointer' : ''}">
        <td><span class="badge ${r.status === 'berhasil' || r.status === 'siap' ? 'aktif' : r.status === 'gagal' ? 'blokir' : ''}">${
          r.status === 'menunggu' ? (target && r.siswa_id === target.siswa_id ? 'target' : 'menunggu') : r.status}</span></td>
        <td>${esc(r.nama)}</td><td>${esc(r.kelas || '—')}</td><td><code>${esc(r.nis || '—')}</code></td>
        <td>${r.uid ? `<code>${esc(r.uid)}</code>` : '—'}${r.status === 'siap' ? ` <button class="linkbtn" data-undo="${r.siswa_id}">batal</button>` : ''}</td>
        <td class="${r.status === 'gagal' ? 'minus' : 'muted'}">${esc(r.pesan || '')}</td>
      </tr>`).join('')}</tbody>
    </table></div>
    ${siap ? `<div style="margin-top:12px"><button class="go" id="km-tap-terapkan">Terapkan Semua — simpan ${siap} kartu</button></div>` : ''}
  `;

  document.querySelectorAll('#km-tap-hasil tbody tr[data-id]').forEach((tr) => {
    tr.onclick = () => {
      const row = rows.find((r) => r.siswa_id === Number(tr.dataset.id));
      if (row && row.status === 'menunggu') { st.tapTarget = row.siswa_id; kartuTapRender(); }
    };
  });
  document.querySelectorAll('#km-tap-hasil [data-undo]').forEach((b) => {
    b.onclick = (ev) => {
      ev.stopPropagation();
      const row = rows.find((r) => r.siswa_id === Number(b.dataset.undo));
      if (row) { row.uid = ''; row.status = 'menunggu'; row.pesan = ''; kartuTapRender(); }
    };
  });
  if ($('#km-tap-terapkan')) $('#km-tap-terapkan').onclick = kartuTapTerapkan;
  if ($('#km-tap-manual')) $('#km-tap-manual').addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const uid = normUid(e.target.value);
    e.target.value = '';
    if (uid) kartuTapOnScan(uid);
  });
}

function kartuTapOnScan(uid) {
  const st = kartuMassalState;
  if (!st || !st.tapRows.length) {
    toast('Muat daftar kelas/unit dulu sebelum menempelkan kartu', 'err');
    return;
  }
  if (st.tapRows.some((r) => r.uid === uid && (r.status === 'siap' || r.status === 'berhasil'))) {
    toast('Kartu ini sudah dipakai di daftar ini — klik "batal" pada barisnya kalau salah tempel', 'err');
    return;
  }
  const target = (st.tapTarget && st.tapRows.find((r) => r.siswa_id === st.tapTarget && r.status === 'menunggu'))
    || st.tapRows.find((r) => r.status === 'menunggu');
  if (!target) {
    toast('Semua baris di daftar ini sudah dapat kartu', 'ok');
    return;
  }
  target.uid = uid;
  target.status = 'siap';
  target.pesan = 'Siap diterapkan';
  st.tapTarget = null;
  kartuTapRender();
}

async function kartuTapTerapkan() {
  const st = kartuMassalState;
  const siap = st.tapRows.filter((r) => r.status === 'siap');
  if (!siap.length) return;
  const btn = $('#km-tap-terapkan');
  btn.disabled = true;
  btn.textContent = 'menyimpan…';
  try {
    const d = await api('/api/kartu-massal/terapkan-tap', { method: 'POST', body: {
      jenis: st.jenis, batch_id: st.tapBatch, izinkan_ganti: $('#km-tap-ganti').checked,
      baris: siap.map((r) => ({ siswa_id: r.siswa_id, uid: r.uid, nama: r.nama })),
    }});
    const perId = {};
    d.baris.forEach((b) => { perId[b.siswa_id] = b; });
    siap.forEach((r) => {
      const b = perId[r.siswa_id];
      if (b) { r.status = b.status; r.pesan = b.pesan; }
    });
    toast(`✔ ${d.impor.n_berhasil} kartu tersimpan${d.impor.n_gagal ? `, ${d.impor.n_gagal} gagal` : ''}`,
      d.impor.n_gagal ? 'err' : 'ok');
    st.tapBatch = crypto.randomUUID();
    loadSiswa();
  } catch (e) {
    toast(e.message, 'err');
  }
  kartuTapRender();
}

async function kartuExcelPratinjau() {
  const st = kartuMassalState;
  const f = $('#km-file').files[0];
  if (!f || !st) return;
  const btn = $('#km-periksa');
  btn.disabled = true;
  $('#km-excel-hasil').innerHTML = '<p class="muted">membaca berkas…</p>';
  try {
    const data = await fileToDataUrl(f);
    const izinkanGanti = $('#km-excel-ganti').checked;
    const pra = await api('/api/kartu-massal/pratinjau', { method: 'POST', body: {
      jenis: st.jenis, nama_berkas: f.name, data, izinkan_ganti: izinkanGanti,
    }});
    st.excel = { nama: f.name, data, pra, izinkanGanti };
    kartuExcelRender();
  } catch (e) {
    $('#km-excel-hasil').innerHTML = `<p class="minus">${esc(e.message)}</p>
      <p class="muted">Pakai template resmi di atas, dan pastikan baris judul kolom masih ada.</p>`;
  }
  btn.disabled = false;
}

function kartuExcelRender() {
  const st = kartuMassalState;
  const { pra } = st.excel;
  const r = pra.ringkas;
  $('#km-excel-hasil').innerHTML = `
    <div class="panel" style="margin:12px 0 0">
      <h2>Hasil pemeriksaan <span class="muted">— lembar ${esc(pra.lembar)}</span></h2>
      <div class="cards">
        <div class="statcard"><span class="muted">Baris berisi UID</span><br><b>${r.n_baris}</b></div>
        <div class="statcard"><span class="muted">Siap disimpan</span><br><b class="plus">${r.n_siap}</b></div>
        <div class="statcard"><span class="muted">Gagal</span><br><b class="${r.n_gagal ? 'minus' : ''}">${r.n_gagal}</b></div>
        ${r.n_ganti ? `<div class="statcard"><span class="muted">Menimpa kartu lama</span><br><b>${r.n_ganti}</b></div>` : ''}
      </div>
      <div class="tblwrap"><table>
        <thead><tr><th>Baris</th><th></th><th>NIS/NIY</th><th>Nama</th><th>UID</th><th>Keterangan</th></tr></thead>
        <tbody>${pra.baris.map((b) => `<tr>
          <td>${b.baris}</td>
          <td><span class="badge ${b.status === 'siap' ? 'aktif' : 'blokir'}">${b.status}</span></td>
          <td><code>${esc(b.nis || '—')}</code></td>
          <td>${esc(b.nama_db || b.nama_berkas || '—')}</td>
          <td>${b.uid ? `<code>${esc(b.uid)}</code>` : '—'}</td>
          <td class="${b.status === 'siap' ? 'muted' : 'minus'}">${esc(b.pesan || '')}</td>
        </tr>`).join('')}</tbody>
      </table></div>
      ${r.n_siap
        ? `<div style="margin-top:12px"><button class="go" id="km-terapkan">Terapkan — simpan ${r.n_siap} kartu</button>
             <span class="hint-lembut">Baris yang gagal dilewati; sisanya tetap disimpan.</span></div>`
        : '<p class="minus" style="margin-top:12px">Tidak ada baris yang siap — perbaiki berkasnya lalu periksa lagi.</p>'}
    </div>`;
  if ($('#km-terapkan')) $('#km-terapkan').onclick = kartuExcelTerapkan;
}

async function kartuExcelTerapkan() {
  const st = kartuMassalState;
  const { jenis } = st;
  const { nama, data, pra, izinkanGanti } = st.excel;
  const btn = $('#km-terapkan');
  btn.disabled = true;
  btn.textContent = 'menyimpan…';
  try {
    const d = await api('/api/kartu-massal/terapkan', { method: 'POST', body: {
      jenis, nama_berkas: nama, data, sidik: pra.sidik, batch_id: crypto.randomUUID(),
      izinkan_ganti: izinkanGanti, abaikan_nama: false,
    }});
    const gagalBaris = d.baris.filter((b) => b.status === 'gagal');
    toast(`✔ ${d.impor.n_berhasil} kartu tersimpan${d.impor.n_gagal ? `, ${d.impor.n_gagal} gagal` : ''}`,
      d.impor.n_gagal ? 'err' : 'ok');
    $('#km-excel-hasil').innerHTML = `
      <div class="panel" style="margin:12px 0 0">
        <h2>Selesai</h2>
        <div class="cards">
          <div class="statcard"><span class="muted">Tersimpan</span><br><b class="plus">${d.impor.n_berhasil}</b></div>
          <div class="statcard"><span class="muted">Gagal</span><br><b class="${d.impor.n_gagal ? 'minus' : ''}">${d.impor.n_gagal}</b></div>
        </div>
        ${gagalBaris.length ? `<div class="tblwrap"><table>
          <thead><tr><th>Baris</th><th>NIS/NIY</th><th>Nama</th><th>Sebab gagal</th></tr></thead>
          <tbody>${gagalBaris.map((b) => `<tr><td>${b.baris}</td><td><code>${esc(b.nis || '—')}</code></td>
            <td>${esc(b.nama || '—')}</td><td class="minus">${esc(b.pesan)}</td></tr>`).join('')}</tbody>
        </table></div>` : ''}
      </div>`;
    $('#km-file').value = '';
    st.excel = null;
    loadSiswa();
  } catch (e) {
    toast(e.message, 'err');
    btn.disabled = false;
    btn.textContent = 'Coba terapkan lagi';
  }
}

// ╔════════════════════════════════════════════════════════════════════════╗
// ║ AKHIR TAHUN AJARAN — arsip pemegang kartu & kenaikan kelas massal      ║
// ╚════════════════════════════════════════════════════════════════════════╝
// Dua operasi setahun sekali, dikerjakan berurutan: arsipkan dulu yang keluar,
// baru naikkan kelas sisanya. Urutan itu penting — kalau dibalik, santri yang
// sudah pamit ikut terbawa naik.
//
// Keduanya tinggal di panel pada layar Santri, bukan menu tersendiri: operasi
// setahun sekali tidak perlu memakan tempat permanen di bar menu.

let arsipState = null;

function arsipPanel(sdm) {
  const t = ISTILAH(sdm);
  const gol = sdm ? kodeSdm() : 'siswa';
  arsipState = { sdm, gol, unduhSudah: false, pra: null };

  $('#sw-tahun').innerHTML = '';
  $('#sw-impor').innerHTML = `
  <div class="panel" style="border-left:4px solid var(--gold)">
    <div class="kat-head">
      <h2 style="margin:0">Arsipkan ${esc(t.orang)} — Alumni / Lanjut Study / Pindah</h2>
      <button class="linkbtn" id="ar-tutup">tutup ✕</button>
    </div>

    <p class="muted">Yang diarsipkan <b>tidak dihapus</b>. Riwayat transaksinya tetap utuh — itulah
      yang dipakai menjawab pertanyaan wali berbulan-bulan kemudian. Yang berubah: kartunya berhenti
      dilayani kasir &amp; kios, UID kartunya <b>dilepas</b> agar kartu fisiknya bisa dipakai
      ${esc(t.orang)} baru, dan barisnya hilang dari daftar aktif.</p>
    <p class="muted"><b>Saldo harus nol lebih dulu.</b> Neraca kas menghitung kewajiban sekolah dari
      saldo seluruh kartu; mengarsipkan kartu bersaldo akan menyembunyikan kewajiban yang uangnya
      masih ada di kas. Kembalikan dulu lewat menu <b>Saldo Santri</b> (alasan
      “Pengembalian saldo (santri keluar/lulus)” sudah tersedia).</p>

    <div class="filterbar">
      <div class="field"><label>${sdm ? 'Unit' : 'Kelas'}</label>
        <select id="ar-kelas"><option value="">— pilih —</option></select></div>
      <button class="go" id="ar-periksa" disabled>Periksa</button>
    </div>
    <div id="ar-hasil"></div>
  </div>`;

  api('/api/kelas').then((d) => {
    const pilihan = sdm ? (d.unit_sdm || UNIT_SDM) : d.kelas;
    $('#ar-kelas').innerHTML = '<option value="">— pilih —</option>' +
      pilihan.map((k) => `<option value="${esc(k)}">${esc(k)}</option>`).join('');
  }).catch(() => {});

  $('#ar-tutup').onclick = () => { $('#sw-impor').innerHTML = ''; arsipState = null; };
  $('#ar-kelas').onchange = () => {
    $('#ar-periksa').disabled = !$('#ar-kelas').value;
    $('#ar-hasil').innerHTML = '';
    arsipState.unduhSudah = false;
  };
  $('#ar-periksa').onclick = arsipPeriksa;
  $('#sw-impor').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function arsipPeriksa() {
  const kelas = $('#ar-kelas').value;
  if (!kelas) return;
  $('#ar-hasil').innerHTML = '<p class="muted">memeriksa…</p>';
  try {
    const p = new URLSearchParams({ kelas, golongan: arsipState.sdm ? 'sdm' : 'siswa' });
    arsipState.pra = await api('/api/arsip/pratinjau?' + p);
    arsipState.unduhSudah = false;
    arsipRender();
  } catch (e) {
    $('#ar-hasil').innerHTML = `<p class="minus">${esc(e.message)}</p>`;
  }
}

function arsipRender() {
  const { pra, sdm } = arsipState;
  const t = ISTILAH(sdm);
  const r = pra.ringkas;

  $('#ar-hasil').innerHTML = `
    <div class="cards">
      <div class="statcard"><span class="muted">Di ${esc(t.unit.toLowerCase())} ini</span><br><b>${r.n}</b></div>
      <div class="statcard"><span class="muted">Siap diarsipkan</span><br><b class="plus">${r.n_siap}</b></div>
      <div class="statcard"><span class="muted">Tertahan</span><br><b class="${r.n_tertahan ? 'minus' : ''}">${r.n_tertahan}</b></div>
      <div class="statcard"><span class="muted">Saldo belum diselesaikan</span><br><b class="rp">${rp(r.sisa_saldo + r.sisa_titipan)}</b></div>
    </div>

    <div class="tblwrap"><table>
      <thead><tr><th>${esc(t.nomorSingkat)}</th><th>Nama</th><th>Status</th>
        <th class="num">Saldo</th><th class="num">Titipan</th><th>Kartu</th><th>Keterangan</th></tr></thead>
      <tbody>${pra.daftar.map((x) => `<tr>
        <td><code>${esc(x.nis || '—')}</code></td>
        <td>${esc(x.nama)}</td>
        <td><span class="badge ${x.status}">${esc(x.status)}</span></td>
        <td class="num rp">${rp(x.saldo)}</td>
        <td class="num rp">${rp(x.saldo_titipan)}</td>
        <td>${x.card_uid ? `<code>${esc(x.card_uid)}</code>` : '<span class="muted">—</span>'}</td>
        <td class="${x.penahan.length ? 'minus' : 'muted'}">${x.penahan.length
          ? esc(x.penahan.join('; ')) : 'siap'}</td>
      </tr>`).join('') || `<tr><td colspan="7" class="muted">Tidak ada ${esc(t.orang)} aktif di sini</td></tr>`}</tbody>
    </table></div>

    ${r.n_siap ? `
    <div class="panel" style="margin-top:12px; background:var(--bg)">
      <h2>Langkah 1 · Unduh salinan untuk arsip pesantren</h2>
      <p class="muted">Berkas .xlsx berisi identitas, kelas terakhir, saldo akhir, dan jumlah
        transaksi seumur kartu. Simpan di luar sistem — kalau sistem ini suatu saat diganti,
        data ini tetap terbaca. <b>Tombol arsip baru menyala setelah berkasnya diunduh.</b></p>
      <button class="go" id="ar-unduh">⬇ Unduh salinan (.xlsx)</button>
      <span class="hint-lembut" id="ar-unduh-status"></span>

      <h2 style="margin-top:16px">Langkah 2 · Arsipkan</h2>
      <div class="field"><label>Status keluar</label>
        <select id="ar-status">${Object.entries(LABEL_ARSIP)
          .map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('')}</select>
        <span class="muted set-hint">Alumni untuk tamatan SMA. Lanjut Study untuk tamatan SMP yang
          melanjutkan <b>di luar</b> pesantren ini — yang lanjut ke SMA di sini tidak diarsipkan,
          cukup dinaikkan kelasnya.</span></div>
      <div class="field"><label>Catatan <span class="muted">(opsional)</span></label>
        <input id="ar-catatan" maxlength="200" placeholder="mis. angkatan 2026"></div>
      <button class="go" id="ar-terapkan" disabled>Arsipkan ${r.n_siap} ${esc(t.orang)}</button>
      <span class="hint-lembut">Yang tertahan dilewati; sisanya tetap diarsipkan.</span>
    </div>` : `<p class="muted" style="margin-top:12px">Belum ada yang siap diarsipkan —
      selesaikan dulu saldo yang tersisa.</p>`}`;

  if (!r.n_siap) return;
  $('#ar-unduh').onclick = () => {
    const p = new URLSearchParams({ kelas: pra.kelas, golongan: sdm ? 'sdm' : 'siswa' });
    const a = document.createElement('a');
    a.href = '/api/arsip/unduh?' + p;
    document.body.appendChild(a);
    a.click();
    a.remove();
    arsipState.unduhSudah = true;
    $('#ar-terapkan').disabled = false;
    $('#ar-unduh-status').textContent = '✔ sudah diunduh — tombol arsip aktif';
  };
  $('#ar-terapkan').onclick = arsipTerapkan;
}

async function arsipTerapkan() {
  const { pra, sdm } = arsipState;
  if (!arsipState.unduhSudah) { toast('Unduh salinannya dulu', 'err'); return; }
  const t = ISTILAH(sdm);
  const status = $('#ar-status').value;

  openModal(`
    <h2>Arsipkan ${pra.ringkas.n_siap} ${esc(t.orang)} ${esc(pra.kelas)}?</h2>
    <p>Status mereka menjadi <b>${esc(LABEL_ARSIP[status])}</b>. UID kartunya dilepas sehingga
      kartu fisiknya bisa dipakai ${esc(t.orang)} baru, dan barisnya hilang dari daftar aktif.</p>
    <p class="muted">Riwayat transaksinya tetap utuh dan tetap bisa dilihat lewat saringan status.
      Kalau ternyata keliru, tiap baris bisa dipulihkan lewat tombol <b>pulihkan</b>.</p>`,
    async () => {
      const d = await api('/api/arsip/massal', { method: 'POST', body: {
        kelas: pra.kelas, golongan: sdm ? 'sdm' : 'siswa',
        status, catatan: $('#ar-catatan').value,
      }});
      toast(`✔ ${d.n_berhasil} ${t.orang} diarsipkan`, 'ok');
      $('#ar-hasil').innerHTML = `
        <div class="cards">
          <div class="statcard"><span class="muted">Diarsipkan</span><br><b class="plus">${d.n_berhasil}</b></div>
          <div class="statcard"><span class="muted">Dilewati</span><br><b class="${d.n_gagal ? 'minus' : ''}">${d.n_gagal}</b></div>
        </div>
        ${d.gagal.length ? `<div class="tblwrap"><table>
          <thead><tr><th>${esc(t.nomorSingkat)}</th><th>Nama</th><th>Sebab dilewati</th></tr></thead>
          <tbody>${d.gagal.map((x) => `<tr><td><code>${esc(x.nis || '—')}</code></td>
            <td>${esc(x.nama)}</td><td class="minus">${esc(x.pesan)}</td></tr>`).join('')}</tbody>
        </table></div>` : ''}`;
      loadSiswa();
    }, `Arsipkan ${pra.ringkas.n_siap} ${t.orang}`);
}

function arsipSatuModal(s) {
  const sdm = siswaState.sdm;
  const t = ISTILAH(sdm);
  openModal(`
    <h2>Arsipkan ${esc(s.nama)}?</h2>
    <p>${esc(t.nomorSingkat)} <code>${esc(s.nis || '—')}</code> · ${esc(t.unit.toLowerCase())}
      <b>${esc(s.kelas || '—')}</b> · saldo <b class="rp">${rp(s.saldo)}</b></p>
    ${(s.saldo || s.saldo_titipan) ? `<p class="preview">Saldo belum nol. Sistem akan menolak —
      kembalikan uangnya dulu lewat menu <b>Saldo Santri</b>, baru arsipkan.</p>` : ''}
    <div class="field"><label>Status keluar</label>
      <select id="as-status">${Object.entries(LABEL_ARSIP)
        .map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('')}</select></div>
    <div class="field"><label>Catatan <span class="muted">(opsional)</span></label>
      <input id="as-catatan" maxlength="200" placeholder="mis. pindah ke Pondok X"></div>
    <p class="muted">UID kartunya dilepas agar bisa dipakai orang lain. Riwayatnya tetap utuh.</p>`,
    async () => {
      await api(`/api/siswa/${s.id}/arsip`, { method: 'POST', body: {
        status: $('#as-status').value, catatan: $('#as-catatan').value,
      }});
      toast('✔ Diarsipkan', 'ok');
      loadSiswa();
    }, 'Arsipkan');
}

function pulihkanModal(s) {
  openModal(`
    <h2>Pulihkan ${esc(s.nama)} dari arsip?</h2>
    <p>Statusnya kembali menjadi <b>aktif</b> dan barisnya muncul lagi di daftar.</p>
    <p class="preview">Kartu fisiknya <b>tidak</b> ikut pulih — UID-nya sudah dilepas saat
      diarsipkan dan bisa saja kini dipakai orang lain. Daftarkan kartu baru lewat tombol
      <b>kartu</b> setelah ini.</p>
    ${s.arsip_catatan ? `<p class="muted">Catatan arsip: ${esc(s.arsip_catatan)}</p>` : ''}`,
    async () => {
      await api(`/api/siswa/${s.id}/aktifkan`, { method: 'POST' });
      toast('✔ Dipulihkan — daftarkan kartu barunya', 'ok');
      loadSiswa();
    }, 'Pulihkan');
}

// ------------------------------------------------------- kenaikan kelas

let kenaikanState = null;

/**
 * Pengaturan Jenjang Kelas, dibuka dari dalam panel Kenaikan Kelas.
 *
 * Dulu panel ini ada di Dashboard, di antara batas belanja dan saldo — padahal
 * angkanya hanya dipakai kenaikan kelas dan arsip. Di sini admin mengubahnya
 * tepat di depan tabel yang dipengaruhinya, dan tabelnya langsung digambar
 * ulang dengan rentang yang baru.
 */
async function jenjangForm() {
  const wadah = $('#kk-jenjang-form');
  if (wadah.innerHTML) { wadah.innerHTML = ''; return; }   // tombol yang sama menutupnya
  wadah.innerHTML = '<p class="muted">memuat…</p>';
  let cfg;
  try { cfg = await api('/api/settings'); }
  catch (e) { wadah.innerHTML = `<p class="minus">${esc(e.message)}</p>`; return; }
  const labels = cfg.labels_kelas || {};
  wadah.innerHTML = `
    <p class="muted">Kelas tertinggi berujung pada <b>Alumni</b>, dan kelas di luar rentang ini
      <b>ditandai</b> sebagai bentuk menyimpang — tidak dinaikkan, supaya salah ketik satu angka
      tidak berarti seseorang dikeluarkan. Paralelnya (A, B, … J) <b>tidak perlu didaftarkan</b>.</p>
    ${gridPengaturan(labels, cfg.settings)}
    <button class="savebtn" id="kk-simpan-jenjang">Simpan Jenjang Kelas</button>`;
  bindPengaturan(labels);
  $('#kk-simpan-jenjang').onclick = async (ev) => {
    const body = {};
    for (const k of Object.keys(labels)) body[k] = Number($('#set-' + k).value);
    ev.target.disabled = true;
    try {
      await api('/api/settings', { method: 'PUT', body });
      toast('✔ Jenjang kelas tersimpan', 'ok');
      kenaikanPanel();   // saran tujuan bergantung pada rentang ini
    } catch (e) { toast(e.message, 'err'); ev.target.disabled = false; }
  };
}

/**
 * Set gender massal: pilih beberapa kelas sekaligus, pilih putra/putri,
 * terapkan. Bukan per-santri — lihat alasannya di SiswaController::genderMassal().
 */
async function genderMassalModal() {
  let kelasList = [];
  try { kelasList = (await api('/api/kelas')).kelas || []; } catch { /* dropdown kosong, bukan fatal */ }

  openModal(`
    <h2>Set Gender Massal</h2>
    <p class="muted" style="margin-top:-6px">Pilih kelas yang SEMUA santrinya satu gender, lalu
      terapkan. Dipakai tiap kali rombongan belajar baru dibentuk — pembagian kelas putra/putri
      boleh berubah tiap tahun ajaran, gendernya tetap melekat ke santri walau naik kelas.</p>
    <div class="field"><label>Kelas (pilih beberapa — Ctrl/Cmd+klik)</label>
      <select id="gm-kelas" multiple size="10" style="width:100%">
        ${kelasList.map((k) => `<option value="${esc(k)}">${esc(k)}</option>`).join('')
          || '<option disabled>Belum ada kelas</option>'}
      </select></div>
    <div class="field"><label>Gender</label>
      <select id="gm-jk">
        <option value="L">Putra</option>
        <option value="P">Putri</option>
      </select></div>
    <div id="gm-hasil"></div>`,
    async () => {
      const kelas = [...$('#gm-kelas').selectedOptions].map((o) => o.value);
      if (!kelas.length) { throw new Error('Pilih minimal satu kelas'); }
      const d = await api('/api/siswa/gender-massal', { method: 'POST', body: {
        kelas, jenis_kelamin: $('#gm-jk').value } });
      toast(`✔ ${d.n_berhasil} santri di ${kelas.length} kelas ditetapkan ${d.jenis_kelamin === 'L' ? 'putra' : 'putri'}`, 'ok');
      loadSiswa();
    }, 'Terapkan');
}

async function kenaikanPanel() {
  $('#sw-impor').innerHTML = '';
  $('#sw-tahun').innerHTML = '<div class="panel"><p class="muted">memuat…</p></div>';
  let d;
  try { d = await api('/api/kenaikan-kelas/pratinjau'); }
  catch (e) { $('#sw-tahun').innerHTML = `<div class="panel"><p class="minus">${esc(e.message)}</p></div>`; return; }
  kenaikanState = d;

  const opsiTujuan = (saran) => {
    // Pilihan tujuan disusun dari kelas yang benar-benar mungkin ditempuh,
    // ditambah status keluar. Tidak ada daftar kelas yang ditulis di kode —
    // semuanya diturunkan dari tingkat yang dibuka sekolah.
    const kelas = [];
    for (let tk = d.tingkat_awal; tk <= d.tingkat_lulus; tk++) {
      for (const hr of 'ABCDEFGHIJ') kelas.push(tk + hr);
    }
    const semua = [...new Set([...(saran && !TERARSIP.includes(saran) ? [saran] : []), ...kelas])];
    return `<option value="">— jangan diubah —</option>` +
      Object.entries(LABEL_ARSIP).map(([k, v]) =>
        `<option value="${k}" ${saran === k ? 'selected' : ''}>${esc(v)}</option>`).join('') +
      semua.map((k) => `<option value="${k}" ${saran === k ? 'selected' : ''}>→ ${esc(k)}</option>`).join('');
  };

  $('#sw-tahun').innerHTML = `
  <div class="panel" style="border-left:4px solid var(--brand)">
    <div class="kat-head">
      <h2 style="margin:0">Kenaikan Kelas Massal</h2>
      <button class="linkbtn" id="kk-tutup">tutup ✕</button>
    </div>
    <div class="kk-jenjang">
      <p class="muted" style="margin:0">🎓 Jenjang yang dibuka sekolah: kelas <b>${d.tingkat_awal}</b> sampai
        <b>${d.tingkat_lulus}</b>.
        ${boleh('settings.ubah') ? '<button class="linkbtn" id="kk-ubah-jenjang">ubah jenjang</button>' : ''}</p>
      <div id="kk-jenjang-form"></div>
    </div>
    <p class="muted">Tujuan di bawah ini <b>saran</b> —
      periksa dan ubah sesuai kenyataan sebelum diterapkan.</p>
    <p class="muted">Santri kelas ${d.tingkat_lulus - 3} yang <b>lanjut ke SMA di sini</b> cukup
      dinaikkan biasa; yang melanjutkan di luar arsipkan dulu lewat tombol
      <b>📦 Arsipkan</b> sebelum menjalankan ini.</p>

    <div class="tblwrap"><table>
      <thead><tr><th>Kelas</th><th class="num">Santri</th><th>Tujuan</th><th>Keterangan</th></tr></thead>
      <tbody>${d.baris.map((b) => `<tr>
        <td><b>${esc(b.kelas)}</b>${b.bentuk_sah ? '' : ' <span class="badge blokir">bentuk tidak lazim</span>'}</td>
        <td class="num">${b.n}</td>
        <td><select data-dari="${esc(b.kelas)}" style="min-width:200px">${opsiTujuan(b.saran)}</select></td>
        <td class="${b.jenis === 'menyimpang' ? 'minus' : 'muted'}">${
          b.jenis === 'menyimpang' ? esc(b.pesan)
          : b.jenis === 'arsip'
            ? `seluruhnya keluar${b.n_tertahan ? ` — <b class="minus">${b.n_tertahan} tertahan</b>, saldo ${rp(b.sisa_saldo + b.sisa_titipan)} belum diselesaikan` : ' — semua siap'}`
            : 'kenaikan biasa'}</td>
      </tr>`).join('') || '<tr><td colspan="4" class="muted">Belum ada santri berkelas</td></tr>'}</tbody>
    </table></div>

    <div class="filterbar" style="margin-top:12px">
      <div class="field"><label>Tahun ajaran</label>
        <input id="kk-tahun" value="${esc(d.tahun_ajaran_saran)}" placeholder="2026/2027"></div>
      <div class="field" style="flex:1"><label>Catatan <span class="muted">(opsional)</span></label>
        <input id="kk-catatan" maxlength="200"></div>
      <button class="go" id="kk-terapkan">Terapkan kenaikan</button>
    </div>
    <span class="hint-lembut">Satu tahun ajaran hanya bisa dinaikkan sekali. Kalau pemetaannya
      keliru, batch-nya bisa dibatalkan di bawah dan dijalankan ulang.</span>

    <div id="kk-hasil"></div>

    <div class="kat-head" style="margin-top:18px">
      <h2 style="margin:0">Riwayat kenaikan</h2>
    </div>
    <div class="tblwrap"><table>
      <thead><tr><th>Tahun ajaran</th><th class="num">Naik</th><th class="num">Diarsipkan</th>
        <th>Oleh</th><th>Waktu</th><th>Status</th><th></th></tr></thead>
      <tbody id="kk-riwayat"></tbody>
    </table></div>
  </div>`;

  $('#kk-tutup').onclick = () => { $('#sw-tahun').innerHTML = ''; kenaikanState = null; };
  if ($('#kk-ubah-jenjang')) $('#kk-ubah-jenjang').onclick = jenjangForm;
  $('#kk-terapkan').onclick = kenaikanTerapkan;
  kenaikanRiwayat(d.riwayat);
  $('#sw-tahun').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function kenaikanRiwayat(riwayat) {
  $('#kk-riwayat').innerHTML = (riwayat || []).map((r) => `<tr>
    <td><b>${esc(r.tahun_ajaran)}</b>${r.percobaan > 1 ? ` <span class="muted">percobaan ${r.percobaan}</span>` : ''}</td>
    <td class="num">${r.n_naik}</td>
    <td class="num">${r.n_arsip}</td>
    <td>${esc(r.operator)}</td>
    <td>${esc(String(r.created_at || '').slice(0, 10))}</td>
    <td>${r.dibatalkan_pada
      ? `<span class="badge blokir">dibatalkan</span>`
      : '<span class="badge aktif">berlaku</span>'}</td>
    <td>${r.dibatalkan_pada ? '' : `<button class="linkbtn danger" data-kk-batal="${r.id}">batalkan</button>`}</td>
  </tr>`).join('') || '<tr><td colspan="7" class="muted">Belum pernah dijalankan</td></tr>';

  document.querySelectorAll('[data-kk-batal]').forEach((b) => {
    b.onclick = () => kenaikanBatalModal((riwayat || []).find((x) => x.id === Number(b.dataset.kkBatal)));
  });
}

async function kenaikanTerapkan() {
  const peta = {};
  document.querySelectorAll('#sw-tahun select[data-dari]').forEach((sel) => {
    if (sel.value) peta[sel.dataset.dari] = sel.value;
  });
  const n = Object.keys(peta).length;
  if (!n) { toast('Belum ada kelas yang dipetakan', 'err'); return; }

  const arsip = Object.entries(peta).filter(([, v]) => TERARSIP.includes(v));
  openModal(`
    <h2>Terapkan kenaikan ${esc($('#kk-tahun').value)}?</h2>
    <p>${n} kelas akan dipetakan${arsip.length
      ? `, dan <b>${arsip.length} kelas dikeluarkan</b> (${esc(arsip.map(([k, v]) => k + ' → ' + LABEL_ARSIP[v].split(' (')[0]).join(', '))})` : ''}.</p>
    <div class="tblwrap" style="max-height:220px; overflow:auto"><table>
      <thead><tr><th>Dari</th><th>Ke</th></tr></thead>
      <tbody>${Object.entries(peta).map(([k, v]) =>
        `<tr><td>${esc(k)}</td><td>${TERARSIP.includes(v)
          ? `<b>${esc(LABEL_ARSIP[v])}</b>` : esc(v)}</td></tr>`).join('')}</tbody>
    </table></div>
    <p class="muted">Kelas pada transaksi yang sudah tercatat <b>tidak berubah</b> — laporan tahun
      lalu tetap menunjukkan kelas yang berlaku saat itu. Kalau keliru, batch ini bisa dibatalkan
      dan keadaan dipulihkan persis.</p>`,
    async () => {
      const d = await api('/api/kenaikan-kelas/terapkan', { method: 'POST', body: {
        tahun_ajaran: $('#kk-tahun').value,
        catatan: $('#kk-catatan').value,
        peta,
      }});
      toast(`✔ ${d.batch.n_naik} naik, ${d.batch.n_arsip} diarsipkan`, 'ok');
      $('#kk-hasil').innerHTML = `
        <div class="cards" style="margin-top:12px">
          <div class="statcard"><span class="muted">Naik kelas</span><br><b class="plus">${d.batch.n_naik}</b></div>
          <div class="statcard"><span class="muted">Diarsipkan</span><br><b>${d.batch.n_arsip}</b></div>
          <div class="statcard"><span class="muted">Tertahan</span><br><b class="${d.n_tertahan ? 'minus' : ''}">${d.n_tertahan}</b></div>
        </div>
        ${d.tertahan.length ? `<p class="muted">Tertahan karena saldonya belum diselesaikan —
          kembalikan dulu lewat menu Saldo Santri, lalu arsipkan lewat tombol 📦 Arsipkan:</p>
        <div class="tblwrap"><table>
          <thead><tr><th>NIS</th><th>Nama</th><th>Kelas</th><th>Sebab</th></tr></thead>
          <tbody>${d.tertahan.map((x) => `<tr><td><code>${esc(x.nis || '—')}</code></td>
            <td>${esc(x.nama)}</td><td>${esc(x.kelas)}</td>
            <td class="minus">${esc(x.penahan.join('; '))}</td></tr>`).join('')}</tbody>
        </table></div>` : ''}`;
      loadSiswa();
      api('/api/kenaikan-kelas').then((h) => kenaikanRiwayat(h.riwayat)).catch(() => {});
    }, 'Terapkan kenaikan');
}

function kenaikanBatalModal(b) {
  if (!b) return;
  openModal(`
    <h2>Batalkan kenaikan ${esc(b.tahun_ajaran)}?</h2>
    <p>Seluruh ${b.n_naik} santri dikembalikan ke kelas sebelumnya${b.n_arsip
      ? `, dan ${b.n_arsip} yang diarsipkan dipulihkan statusnya` : ''}.</p>
    ${b.n_arsip ? `<p class="preview">UID kartu yang sudah dilepas <b>tidak</b> ikut pulih —
      kartu barunya harus didaftarkan satu per satu lewat tombol <b>kartu</b>.</p>` : ''}
    <p class="muted">Setelah dibatalkan, tahun ajaran yang sama boleh dijalankan ulang dengan
      pemetaan yang sudah dibetulkan.</p>`,
    async () => {
      const d = await api(`/api/kenaikan-kelas/${b.id}/batalkan`, { method: 'POST' });
      toast('✔ ' + d.pesan, 'ok');
      loadSiswa();
      const h = await api('/api/kenaikan-kelas').catch(() => null);
      if (h) kenaikanRiwayat(h.riwayat);
      $('#kk-hasil').innerHTML = '';
    }, 'Batalkan kenaikan');
}

// ╔════════════════════════════════════════════════════════════════════════╗
// ║ DANA ADMIN — pos dana milik sekolah dari biaya admin top up wali       ║
// ╚════════════════════════════════════════════════════════════════════════╝
// BUKAN rekening bank. Uangnya berada di rekening sekolah yang sama,
// bercampur dengan titipan wali; yang dilacak di sini adalah berapa bagian
// dari saldo rekening itu yang sudah menjadi hak sekolah.
//
// "Menarik" karenanya tidak memindahkan apa pun secara otomatis — ia MENCATAT
// bahwa sekolah sudah mengambil bagiannya. Pemindahan fisiknya dikerjakan
// bendahara di bank, dan catatan inilah yang membuatnya dipertanggungjawabkan.

let danaState = null;

async function muatDanaAdmin() {
  let d;
  try { d = await api('/api/dana-admin'); }
  catch (e) { $('#tp-dana').innerHTML = `<div class="panel"><p class="minus">${esc(e.message)}</p></div>`; return; }
  danaState = d;
  const r = d.ringkas;

  $('#tp-dana').innerHTML = `
  <div class="panel" style="border-left:4px solid var(--ok)">
    <div class="kat-head">
      <h2 style="margin:0">Dana Admin</h2>
      ${d.boleh_tarik && r.saldo > 0
        ? '<button class="linkbtn" id="dn-tarik">− Catat penarikan</button>' : ''}
    </div>

    <div class="cards">
      <div class="statcard" style="border-top-color:var(--ok)">
        <span class="muted">Tersedia sekarang</span><br><b class="rp">${rp(r.saldo)}</b></div>
      <div class="statcard"><span class="muted">Terkumpul seluruhnya</span><br>
        <b class="rp">${rp(r.masuk)}</b><br><span class="muted">${r.n_masuk} pungutan</span></div>
      <div class="statcard"><span class="muted">Sudah ditarik</span><br>
        <b class="rp">${rp(r.tarik)}</b><br><span class="muted">${r.n_tarik} penarikan</span></div>
    </div>

    <p class="muted"><b>Ini pos dana, bukan rekening bank.</b> Uangnya ada di rekening sekolah yang
      sama dengan titipan wali — yang dihitung di sini adalah berapa bagiannya yang sudah menjadi
      hak sekolah dan boleh dipakai. Menekan “catat penarikan” tidak memindahkan uang; ia mencatat
      bahwa sekolah sudah mengambilnya, supaya bisa dipertanggungjawabkan.</p>

    ${d.per_bulan.length ? `
    <h2 style="margin-top:14px">Rekap bulanan</h2>
    <div class="tblwrap"><table>
      <thead><tr><th>Periode</th><th class="num">Dipungut</th><th class="num">Jumlah pungutan</th>
        <th class="num">Ditarik</th></tr></thead>
      <tbody>${d.per_bulan.map((b) => `<tr>
        <td><b>${esc(b.periode)}</b></td>
        <td class="num rp">${rp(b.masuk)}</td>
        <td class="num">${b.n_pungut}</td>
        <td class="num rp">${b.tarik ? rp(b.tarik) : '<span class="muted">—</span>'}</td>
      </tr>`).join('')}</tbody>
    </table></div>` : ''}

    <div class="kat-head" style="margin-top:14px">
      <h2 style="margin:0">Mutasi terakhir</h2>
      <button class="linkbtn" id="mt-toggle">▾ Tampilkan mutasi</button>
    </div>
    <div id="mt-box" class="hidden">
    <div class="tblwrap"><table>
      <thead><tr><th>Waktu</th><th>Jenis</th><th class="num">Jumlah</th>
        <th class="num">Sisa dana</th><th>Keterangan</th><th>Oleh</th></tr></thead>
      <tbody>${d.mutasi.map((m) => `<tr>
        <td>${esc(String(m.created_at || '').slice(0, 10))}</td>
        <td><span class="badge ${m.jenis === 'masuk' ? 'aktif' : 'blokir'}">${m.jenis}</span></td>
        <td class="num ${m.jenis === 'masuk' ? 'plus' : 'minus'}">${m.jenis === 'masuk' ? '+' : '−'}${rp(m.jumlah)}</td>
        <td class="num rp">${rp(m.saldo_sesudah)}</td>
        <td>${esc(m.keterangan || '—')}</td>
        <td>${esc(m.operator)}</td>
      </tr>`).join('') || '<tr><td colspan="6" class="muted">Belum ada mutasi — biaya admin belum pernah dipungut</td></tr>'}</tbody>
    </table></div>
    </div>
  </div>`;

  if ($('#dn-tarik')) $('#dn-tarik').onclick = tarikDanaModal;
  $('#mt-toggle').onclick = () => {
    const tersembunyi = $('#mt-box').classList.toggle('hidden');
    $('#mt-toggle').textContent = tersembunyi ? '▾ Tampilkan mutasi' : '▴ Sembunyikan mutasi';
  };
}

function tarikDanaModal() {
  const r = danaState.ringkas;
  openModal(`
    <h2>Catat penarikan Dana Admin</h2>
    <p>Tersedia sekarang: <b class="rp">${rp(r.saldo)}</b></p>
    <p class="preview">Tindakan ini <b>tidak memindahkan uang</b>. Ia mencatat bahwa sekolah sudah
      mengambil bagiannya dari rekening. Pemindahan uangnya dikerjakan bendahara di bank — catatan
      ini yang membuatnya bisa dipertanggungjawabkan.</p>
    <div class="field"><label>Nominal yang diambil</label>
      ${inputUang({ id: 'dn-jumlah', placeholder: 'contoh: 500.000' })}
      <span class="muted set-hint">Tidak boleh melebihi ${rp(r.saldo)} — dana ini bukan rekening
        yang bisa minus, dan menariknya berlebih berarti mengambil uang titipan wali.</span></div>
    <div class="field"><label>Untuk apa</label>
      <input id="dn-ket" maxlength="200" placeholder="mis. beli kertas struk & servis printer kasir">
      <span class="muted set-hint">Wajib diisi. Dana ini uang sekolah, dan setiap pengambilannya
        harus bisa dijelaskan kembali berbulan-bulan kemudian.</span></div>
    <div class="field"><label>Bukti <span class="muted">(opsional, JPG/PNG)</span></label>
      <input type="file" id="dn-bukti" accept="image/*"></div>`,
    async () => {
      const jumlah = nilaiUang('#dn-jumlah') ?? 0;
      if (!jumlah) throw new Error('Nominal belum diisi');
      const f = $('#dn-bukti')?.files[0];
      const body = {
        jumlah, keterangan: $('#dn-ket').value, konfirmasi: true,
        // Penanda unik per penarikan: klik ganda tidak menghasilkan dua catatan.
        ref: 'tarik-' + Date.now(),
      };
      if (f) body.bukti = (await kompresGambar(f)).dataUrl;
      const d = await api('/api/dana-admin/tarik', { method: 'POST', body });
      toast(`✔ Penarikan tercatat — sisa dana ${rp(d.ringkas.saldo)}`, 'ok');
      muatDanaAdmin();
    }, 'Catat penarikan');
}
