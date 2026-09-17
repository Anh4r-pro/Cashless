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
function openModal(html, onSave, saveLabel) {
  const root = $('#modal-root');
  root.innerHTML = `<div class="modal-bg"><div class="modal">${html}
    <div class="actions">
      <button id="m-cancel">Batal</button>
      ${onSave ? `<button id="m-save" class="primary">${saveLabel || 'Simpan'}</button>` : ''}
    </div></div></div>`;
  $('#m-cancel').onclick = closeModal;
  root.querySelector('.modal-bg').addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-bg')) closeModal();
  });
  if (onSave) $('#m-save').onclick = async () => {
    const btn = $('#m-save');
    btn.disabled = true;
    try { await onSave(); closeModal(); }
    catch (e) { toast(e.message, 'err'); btn.disabled = false; }
  };
}
function closeModal() { $('#modal-root').innerHTML = ''; cardHandler = null; }

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
                                     ['topup', 'Topup Ortu']] },
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

/** Apakah tab ini boleh digambar? Tab tanpa aturan (milik kasir) selalu boleh. */
const bolehTab = (id) => !IZIN_TAB[id] || IZIN_TAB[id].some(boleh);

/**
 * Menu bar setelah disaring izin. Grup yang seluruh anaknya tertutup ikut
 * hilang — induk kosong yang tetap tergambar hanya akan terbuka ke ruang hampa.
 */
function tabTampil(role) {
  return (VIEWS[role] || []).map((t) => {
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
  $('#hd-title').textContent = USER.role === 'tenant' ? `Kantin Imbos — ${USER.tenant_nama}` : 'Kantin Imbos';
  $('#hd-user').textContent = USER.role === 'tenant'
    ? `${USER.nama} (kasir)`
    : `${USER.nama} · ${USER.kategori_nama || 'Akses Penuh'}`;
  const awal = tabDariHash();
  gambarNav(tabTampil(USER.role), awal);
  location.hash = awal;
  renderView(awal);
}

/** Satu tombol tab. Label di-escape: nama grup & tab berasal dari kode, tapi
 *  aturannya sama di seluruh berkas ini supaya tidak jadi celah kalau kelak
 *  labelnya datang dari pengaturan. */
const tombolNav = ([id, label], aktif) =>
  `<button data-v="${id}" class="${id === aktif ? 'active' : ''}">${esc(label)}</button>`;

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

function renderView(name) {
  activeView = name;
  cardHandler = null;
  if (!name) {
    $('#main').innerHTML = `<div class="panel"><h2>Belum ada menu yang terbuka</h2>
      <p class="muted">Kategori akses <b>${esc(USER?.kategori_nama || '—')}</b> belum diberi satu pun
        hak membuka menu. Mintalah pemegang akun dengan hak <b>Mengelola kategori akses</b>
        untuk menyalakan fiturnya.</p></div>`;
    return;
  }
  const render = {
    kasir: kasirView, menu: menuView, riwayat: riwayatView,
    dashboard: USER?.role === 'tenant' ? dashTenantView : dashSAView,
    saldo: saldoView, santri: siswaView, tenant: tenantView, analitik: analitikView,
    donasi: donasiView, topup: topupOrtuView, 'menu-ajuan': menuAjuanView, sdm: sdmView,
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
  tutupMenuPonsel();
  showLogin();
};

// ========================================================== HID card capture
// Reader keyboard-wedge mengetik UID + Enter super cepat (jeda antar-karakter < 100ms).
let kbBuf = '', kbLast = 0;
let lastTap = { uid: '', at: 0 };
document.addEventListener('keydown', (e) => {
  if (!USER) return;
  const now = performance.now();
  if (now - kbLast > 100) kbBuf = '';
  kbLast = now;
  if (e.key === 'Enter') {
    const uid = normUid(kbBuf);
    kbBuf = '';
    if (uid.length >= 6) { e.preventDefault(); onCardTapped(uid); }
  } else if (e.key.length === 1) {
    kbBuf += e.key;
  }
});

function onCardTapped(uid) {
  const now = Date.now();
  if (uid === lastTap.uid && now - lastTap.at < 2000) return; // anti double-read
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
      <div style="display:flex; gap:8px; margin-top:12px">
        ${inputUang({ id: 'manual-harga', placeholder: 'Input manual (Rp)' })}
        <select id="manual-kat" class="hidden" style="flex:none; width:auto">
          <option value="makanan">Makanan</option>
          <option value="non_makanan">Non-makanan</option>
        </select>
        <button class="linkbtn" id="btn-add-manual" style="flex:none">+ Tambah</button>
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
    const h = nilaiUang('#manual-harga');
    if (!h || h <= 0) { toast('Isi nominal manual dulu', 'err'); return; }
    const kategori = kasir.tenant?.jenis === 'koperasi' ? $('#manual-kat').value : 'makanan';
    kasir.cart.push({ manual: true, nama: 'Input manual', harga: h, kategori });
    $('#manual-harga').value = '';
    renderCart();
  };
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
    : '<div class="muted">Keranjang kosong — klik menu atau input manual</div>';
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
    kasir.siswa = d.siswa;
    kasir.tapToken = null;         // satu tap sudah terpakai
    kasir.cart = [];
    renderCart();
    const pesanSmart = d.smart
      ? `<br>💡 Saldo terisi ulang otomatis <b>${rp(d.smart.isi)}</b> dari titipan
         (sisa titipan ${rp(d.smart.sisa_titipan)}).`
      : '';
    renderKasirSiswa('✔ Transaksi berhasil. Tempelkan kartu lagi untuk transaksi berikutnya.' + pesanSmart);
    toast(`✔ Berhasil — sisa saldo ${rp(d.siswa.saldo)}`, 'ok');
  } catch (e) {
    toast(e.message, 'err');
    updatePayBtn();               // token masih hidup jika transaksi gagal
  }
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
      <button class="linkbtn" id="btn-add-menu">+ Ajukan menu baru</button>
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
  loadMenu();
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
  </div>`;
  $('#aju-filter').onclick = (e) => {
    const b = e.target.closest('button[data-status]');
    if (!b) return;
    ajuanStatus = b.dataset.status;
    document.querySelectorAll('#aju-filter button').forEach((x) => x.classList.toggle('sel', x === b));
    loadAjuanSA();
  };
  $('#kat-muat').onclick = muatKategoriProduk;
  loadAjuanSA();
  muatKategoriProduk();
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

async function loadAjuanSA() {
  const d = await api('/api/produk/ajuan?status=' + ajuanStatus);
  setLencanaAjuan(d.menunggu);
  $('#aju-sa-body').innerHTML = d.ajuan.map((a) => `
    <tr class="${a.status === 'menunggu' ? 'tp-menunggu' : ''}">
      <td>${esc(waktuSingkat(a.created_at))}</td>
      <td>${esc(a.tenant_nama)}</td>
      <td>${esc(AJU_JENIS[a.jenis] || a.jenis)}</td>
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
  const { tenants } = await api('/api/tenants').catch(() => ({ tenants: [] }));
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

function riwayatView(el) {
  riwayatPage = 1;
  const isSA = USER.role === 'superadmin';
  el.innerHTML = `<div class="panel">
    <h2>Riwayat Transaksi</h2>
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
        <option value="transfer_masuk">transfer — terima</option></select></div>
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
    riwayatPage = 1;
    loadRiwayat();
  };
  $('#rw-prev').onclick = () => { if (riwayatPage > 1) { riwayatPage--; loadRiwayat(); } };
  $('#rw-next').onclick = () => { riwayatPage++; loadRiwayat(); };
  loadRiwayat();
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
 * Lencana jumlah pengajuan menu yang menunggu, ditempel di tab "Menu Tenant".
 * Tanpa ini usulan tenant bisa menganggur berhari-hari tanpa ada yang melihat.
 */
function setLencanaAjuan(n) {
  const b = document.querySelector('#nav button[data-v="menu-ajuan"]');
  if (!b) return;
  b.innerHTML = 'Menu Tenant' + (n > 0 ? ` <span class="navbadge">${n}</span>` : '');
  // Tombolnya kini duduk di dalam submenu, jadi lencananya tak terlihat sampai
  // grupnya disorot — padahal seluruh gunanya justru supaya usulan tenant tidak
  // menganggur tanpa ada yang melihat. Induknya ikut diberi titik penanda.
  const grup = b.closest('.nav-grup');
  if (grup) grup.classList.toggle('ada-lencana', n > 0);
}

async function dashSAView(el) {
  const d = await api('/api/dashboard');
  setLencanaAjuan(d.menu_menunggu);
  const st = await api('/api/settlements');
  const cfg = await api('/api/settings');
  const nr = await api('/api/neraca').catch(() => null);
  el.innerHTML = `
    ${nr ? neracaPanel(nr) : ''}
    <div class="cards">
      <div class="statcard"><span class="muted">Belanja hari ini</span><br><b class="rp">${rp(d.belanja_hari_ini)}</b></div>
      <div class="statcard"><span class="muted">Top up hari ini</span><br><b class="rp">${rp(d.topup_hari_ini)}</b></div>
      <div class="statcard"><span class="muted">Total saldo santri (kewajiban)</span><br><b class="rp">${rp(d.total_saldo_siswa)}</b></div>
      <div class="statcard"><span class="muted">Jumlah santri</span><br><b>${d.jumlah_siswa.toLocaleString('id-ID')}</b></div>
    </div>
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
      <h2>👪 Kunci Masuk Portal Wali</h2>
      <p class="muted">Wali masuk ke <b>/ortu</b> dengan <b>NIS + tanggal lahir</b> anak.
        Kunci ketiga berupa 4 digit terakhir nomor HP wali bisa dimatikan di sini.</p>
      <p class="muted"><b>Pertimbangkan sebelum mematikannya:</b> NIS dan tanggal lahir
        sama-sama diketahui teman sekelas, jadi tanpa kunci ketiga portal ini praktis terbuka
        bagi siapa pun yang mengenal anaknya. Halamannya memang baca-saja, tetapi saldo dan pola
        jajan tetap data pribadi. Yang menahan tebak-tebakan dari luar adalah rem percobaan gagal
        (5 kali salah → terkunci 15 menit), bukan kerahasiaan nomornya.</p>
      ${gridPengaturan(cfg.labels_ortu || {}, cfg.settings)}
      <button class="savebtn" data-simpan="ortu">Simpan Kunci Portal Wali</button>
    </div>`;

  // Semua panel memakai satu mekanisme yang sama; kelompoknya hanya menentukan
  // kunci mana yang dikirim saat tombol simpannya ditekan.
  const GRUP = {
    limit: cfg.labels, titipan: cfg.labels_titipan, nonmakanan: cfg.labels_nonmakanan,
    transfer: cfg.labels_transfer || {}, ortu: cfg.labels_ortu || {},
  };
  const semua = { ...cfg.labels, ...cfg.labels_titipan, ...cfg.labels_nonmakanan,
    ...GRUP.transfer, ...GRUP.ortu };
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
  bindBuktiButtons(el);
  el.querySelectorAll('[data-setor]').forEach((b) => {
    b.onclick = () => setorModal(Number(b.dataset.setor), b.dataset.nama, Number(b.dataset.out));
  });
  el.querySelectorAll('[data-upbukti]').forEach((b) => {
    b.onclick = () => openModal(`
      <h2>Upload Bukti Setoran</h2>
      <div class="field"><label>Foto/scan bukti (JPG/PNG, maks 3MB)</label>
      <input type="file" id="m-file" accept="image/*"></div>`,
      async () => {
        const f = $('#m-file').files[0];
        if (!f) throw new Error('Pilih file dulu');
        const data = await fileToDataUrl(f);
        await api(`/api/settlements/${b.dataset.upbukti}/bukti`, { method: 'POST', body: { data } });
        toast('✔ Bukti tersimpan', 'ok');
        renderView('dashboard');
      });
  });
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
            (m.internal_isi_ulang > 0
              ? `<br>isi ulang otomatis dari titipan ${rp(m.internal_isi_ulang)} tidak dihitung sebagai uang masuk`
              : '') })}
        ${nrBaris('Sudah disetor ke tenant', n.keluar.tenant, { tanda: '−', warna: 'minus' })}
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
        : v > 0 ? (m.unit === 'kali' ? `${v.toLocaleString('id-ID')} kali / hari` : rp(v))
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
      if (f) body.bukti_data = await fileToDataUrl(f);
      await api('/api/settlements', { method: 'POST', body });
      toast('✔ Setoran tercatat', 'ok');
      renderView('dashboard');
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
      <div class="field"><label id="sd-jumlah-label">Nominal</label>
        <div class="quick" id="sd-quick"></div>
        ${inputUang({ id: 'sd-jumlah', placeholder: 'Nominal lain (Rp)' })}</div>
      <div class="field hidden" id="sd-kurang-field"><label>Kalau saldo santri kurang</label>
        <select id="sd-kurang">
          <option value="lewati">Lewati santri itu (saldo tidak berubah)</option>
          <option value="sebisanya">Potong sebisanya sampai saldo 0</option>
        </select></div>
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
      sdRenderTarget();
      sdRenderQuick();
      sdRenderKategori();
      sdSync();
      muatPanelTitipan();      // panel titipan tidak berlaku pada mode kolektif
    };
  });
  $('#sd-ke-titipan').addEventListener('change', sdSync);
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
  $('#sd-target-title').textContent = sdKolektif() ? '1 · Pilih Kelas' : '1 · Pilih Santri';
  if (!sdKolektif()) {
    t.innerHTML = `
      <p class="muted">Cari langsung tanpa kartu — ketik nama, NIS, kelas, atau UID.
        Kalau reader tersedia, tap kartu di halaman ini juga memilih santri.</p>
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
    <p class="muted">Pilih kelas — seluruh santrinya masuk daftar, lalu hilangkan centang
      santri yang dikecualikan. Beberapa kelas boleh digabung dalam satu pemotongan.
      Santri berstatus blokir tidak dicentang otomatis.</p>
    <div class="filterbar">
      <div class="field"><label>Kelas</label><select id="sd-kelas"><option value="">memuat…</option></select></div>
      <button class="go" id="sd-tambah">+ Tambahkan kelas</button>
      <button id="sd-kosongkan">Kosongkan daftar</button>
    </div>
    <div id="sd-batch"></div>`;
  api('/api/kelas').then((d) => {
    const sel = $('#sd-kelas');
    if (sel) sel.innerHTML = d.kelas.map((k) => `<option>${esc(k)}</option>`).join('')
      || '<option value="">(belum ada kelas)</option>';
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
    const d = await api('/api/siswa?limit=500&kelas=' + encodeURIComponent(kelas));
    const adaId = new Set(saldo.batch.map((s) => s.id));
    // hanya santri yang benar-benar di kelas itu (parameter kelas dicocokkan persis di server)
    const baru = d.siswa.filter((s) => !adaId.has(s.id));
    saldo.batch = saldo.batch.concat(baru);
    for (const s of baru) if (s.status === 'aktif') saldo.pilih.add(s.id);
    toast(baru.length ? `${baru.length} santri kelas ${kelas} ditambahkan` : `Kelas ${kelas} sudah ada di daftar`,
      baru.length ? 'ok' : '');
    sdRenderBatch();
    sdSync();
  } catch (e) { toast(e.message, 'err'); }
  btn.disabled = false;
}

// Berapa yang benar-benar terpotong dari satu santri dengan nominal & mode saat ini.
function sdPotongEfektif(s) {
  const n = saldo.nominal || 0;
  if (!n) return 0;
  return $('#sd-kurang')?.value === 'sebisanya' ? Math.min(n, s.saldo) : (s.saldo >= n ? n : 0);
}

function sdRenderBatch() {
  const box = $('#sd-batch');
  if (!box) return;
  if (!saldo.batch.length) {
    box.innerHTML = '<p class="muted">Daftar masih kosong — pilih kelas lalu tekan “Tambahkan kelas”.</p>';
    return;
  }
  const n = saldo.nominal || 0;
  const rows = saldo.batch.map((s) => {
    const dicentang = saldo.pilih.has(s.id);
    const efektif = sdPotongEfektif(s);
    const kurang = n > 0 && efektif < n;
    return `<tr class="${dicentang ? '' : 'off'}">
      <td><input type="checkbox" data-id="${s.id}" ${dicentang ? 'checked' : ''} style="width:auto"></td>
      <td><b>${esc(s.nama)}</b>${s.status === 'blokir' ? ' <span class="badge blokir">blokir</span>' : ''}</td>
      <td>${esc(s.kelas)}</td>
      <td class="num rp">${rp(s.saldo)}</td>
      <td class="num ${kurang ? 'minus' : 'muted'}">${!n ? '—'
        : efektif > 0 ? '−' + rp(efektif) + (kurang ? ' (sebagian)' : '')
        : 'dilewati'}</td>
    </tr>`;
  }).join('');
  const dipilih = saldo.batch.filter((s) => saldo.pilih.has(s.id));
  box.innerHTML = `
    <div class="batchbar">
      <label><input type="checkbox" id="sd-all" style="width:auto"
        ${dipilih.length === saldo.batch.length ? 'checked' : ''}> pilih semua</label>
      <span class="muted">${dipilih.length} dari ${saldo.batch.length} santri dicentang</span>
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
  try { d = await api('/api/siswa?limit=8&cari=' + encodeURIComponent(q)); }
  catch (e) { box.innerHTML = `<p class="muted">${esc(e.message)}</p>`; return; }
  if (!d.siswa.length) { box.innerHTML = '<p class="muted">Tidak ada santri cocok</p>'; return; }
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
  // Pilihan titipan hanya masuk akal pada top up satu santri.
  $('#sd-titipan-field').classList.toggle('hidden', potong);

  if (sdKolektif()) return sdSyncKolektif(n, ket);

  let pesan = '', ok = false;
  if (!s) pesan = 'Pilih santri dulu di panel sebelah kiri.';
  else if (!n || n <= 0) pesan = 'Isi nominal — pilih nominal cepat atau ketik sendiri.';
  else if (potong && n > s.saldo) pesan = `⚠️ Melebihi saldo ${esc(s.nama)} (${rp(s.saldo)}).`;
  else if (potong && ket.length < 3) pesan = 'Alasan pemotongan wajib diisi (minimal 3 huruf).';
  else {
    ok = true;
    const akhir = potong ? s.saldo - n : s.saldo + n;
    pesan = `<b>${esc(s.nama)}</b><br>Saldo ${rp(s.saldo)}
      <span class="${potong ? 'minus' : 'plus'}">${potong ? '−' : '+'}${rp(n)}</span>
      → <b class="rp">${rp(akhir)}</b>`;
    // Angka pastinya dihitung server (jatah harian ikut menentukan), jadi di sini
    // hanya diberi ancang-ancang berdasarkan batas saldo kartu.
    if (!potong && $('#sd-ke-titipan')?.checked) pesan +=
      '<br><span class="muted">Yang tidak tertampung kartu akan mengendap di saldo titipan.</span>';
  }
  $('#sd-preview').innerHTML = pesan;
  $('#sd-preview').className = 'preview' + (ok ? ' ok' : '');

  const btn = $('#btn-saldo');
  btn.textContent = potong ? `Potong Saldo${n ? ' ' + rp(n) : ''}` : `Top Up${n ? ' ' + rp(n) : ''}`;
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
  if (!saldo.batch.length) pesan = 'Tambahkan kelas dulu di panel sebelah kiri.';
  else if (!r.dipilih.length) pesan = 'Belum ada santri yang dicentang.';
  else if (!n || n <= 0) pesan = 'Isi nominal yang dipotong dari tiap santri.';
  else if (r.total <= 0) pesan = '⚠️ Tidak ada saldo yang bisa dipotong — semua santri terpilih saldonya kurang.';
  else if (ket.length < 3) pesan = 'Alasan pemotongan wajib diisi (minimal 3 huruf).';
  else {
    ok = true;
    pesan = `<b>${r.dipilih.length} santri</b>${kelas.length ? ' · kelas ' + esc(kelas.join(', ')) : ''}<br>
      ${rp(n)} × ${r.kena.length + r.sebagian.length} santri →
      total <b class="minus">−${rp(r.total)}</b>` +
      (r.sebagian.length ? `<br><span class="muted">${r.sebagian.length} santri dipotong sebagian (saldo habis)</span>` : '') +
      (r.lewat.length ? `<br><span class="muted">${r.lewat.length} santri dilewati karena saldonya kurang</span>` : '');
  }
  $('#sd-preview').innerHTML = pesan;
  $('#sd-preview').className = 'preview' + (ok ? ' ok' : '');
  const btn = $('#btn-saldo');
  btn.textContent = ok ? `Potong ${r.kena.length + r.sebagian.length} Santri · ${rp(r.total)}` : 'Potong Kolektif';
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
  const d = await api(potong ? '/api/koreksi' : '/api/topup', { method: 'POST', body: {
    siswa_id: saldo.siswa.id,
    jumlah: nominal,
    keterangan: $('#sd-ket').value.trim(),
    ...(keTitipan ? { ke_titipan: true } : {}),
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
  const rincian = d.ke_titipan > 0
    ? ` (${rp(d.ke_saldo)} ke kartu, ${rp(d.ke_titipan)} ke titipan)` : '';
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
    <h2>Potong saldo ${r.kena.length + r.sebagian.length} santri?</h2>
    <p>${rp(n)} per santri${kelas.length ? ` · kelas <b>${esc(kelas.join(', '))}</b>` : ''} —
       total <b class="minus">${rp(r.total)}</b> ditarik dari saldo santri.</p>
    ${r.sebagian.length ? `<p class="muted">${r.sebagian.length} santri saldonya kurang dan dipotong sebagian (sampai saldo 0).</p>` : ''}
    ${r.lewat.length ? `<p class="muted">${r.lewat.length} santri dilewati karena saldonya kurang — saldonya tidak berubah.</p>` : ''}
    <p class="muted">Alasan: “${esc(alasan)}”.<br>
      Tercatat satu per satu atas nama <b>${esc(USER.nama)}</b> di riwayat tiap santri
      dan tidak bisa dihapus — pembetulan dilakukan dengan top up balik.</p>`,
    async () => {
      const d = await api('/api/koreksi-massal', { method: 'POST', body: {
        siswa_ids: r.dipilih.map((s) => s.id),
        jumlah: n,
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
      toast(`✔ ${d.berhasil.length} santri dipotong — total ${rp(d.total_dipotong)}`, 'ok');
      setTimeout(() => hasilKolektifModal(d), 250);
    }, 'Ya, potong kolektif');
}

function hasilKolektifModal(d) {
  openModal(`
    <h2>Hasil Pemotongan Kolektif</h2>
    <div class="cards" style="margin-bottom:12px">
      <div class="statcard"><span class="muted">Berhasil dipotong</span><br><b>${d.berhasil.length} santri</b></div>
      <div class="statcard"><span class="muted">Total ditarik</span><br><b class="rp">${rp(d.total_dipotong)}</b></div>
    </div>
    ${d.dilewati.length ? `
      <p class="muted">${d.dilewati.length} santri dilewati — saldonya tidak berubah:</p>
      <div class="tblwrap" style="max-height:220px; overflow:auto"><table>
        <thead><tr><th>Nama</th><th>Kelas</th><th class="num">Saldo</th><th>Alasan</th></tr></thead>
        <tbody>${d.dilewati.map((s) => `<tr><td>${esc(s.nama)}</td><td>${esc(s.kelas)}</td>
          <td class="num rp">${rp(s.saldo)}</td><td class="muted">${esc(s.alasan)}</td></tr>`).join('')}</tbody>
      </table></div>`
    : '<p class="muted">Semua santri terpilih berhasil dipotong.</p>'}`);
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
      nominal: p.nominal.join(', '), aktif: p.aktif === 1,
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
      <input class="dn-nominal" data-i="${i}" value="${esc(r.nominal)}" inputmode="numeric"
        placeholder="pilihan nominal, mis. 2000, 5000, 10000">
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
        nominal: r.nominal.split(',').map((x) => x.trim()).filter(Boolean)
          .map((x) => Number(x.replace(/\D/g, ''))),
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
  siswaState = { page: 1, sdm: !!sdm };
  const t = ISTILAH(sdm);
  const judul = sdm ? 'SDM &amp; Personil Khusus' : 'Data Santri';
  const bolehImpor = boleh(`${sdm ? 'sdm' : 'santri'}.impor`);
  const bolehTambah = boleh(`${sdm ? 'sdm' : 'santri'}.tambah`);
  el.innerHTML = `<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:8px">
      <h2 style="margin:0">${judul} <span class="muted" id="sw-count"></span></h2>
      <div style="display:flex; gap:10px; flex-wrap:wrap">
        ${sdm && boleh('golongan.kelola') ? '<button class="linkbtn" id="btn-golongan">⚙ kelola golongan</button>' : ''}
        ${bolehImpor ? `<button class="linkbtn" id="btn-impor">⬆ Impor ${esc(t.orang)} dari Excel</button>` : ''}
        ${bolehTambah ? `<button class="linkbtn" id="btn-add-siswa">+ Tambah ${esc(t.orang)}</button>` : ''}
      </div>
    </div>
    ${sdm ? `<p class="muted">Guru, karyawan, tamu, dan personil VIP memakai kartu, saldo, kasir,
      dan kios yang sama dengan santri — yang berbeda hanya <b>golongan</b>-nya, dan tiap golongan
      punya batas belanja serta hak aksesnya sendiri. Semua saldo mereka tetap masuk ke satu
      neraca kas yang sama.</p>` : ''}
    <div id="sw-impor"></div>
    <div class="filterbar">
      <div class="field"><label>Cari</label>
        <input id="sw-cari" placeholder="nama / ${esc(t.nomorSingkat)}${sdm ? '' : ' / NISN'} / UID"></div>
      ${sdm ? `<div class="field"><label>Golongan</label><select id="sw-gol"></select></div>` : ''}
      <div class="field"><label>${sdm ? 'Unit' : 'Kelas'}</label><select id="sw-kelas"><option value="">semua</option></select></div>
      <div class="field"><label>Status</label><select id="sw-status">
        <option value="">semua</option><option>aktif</option><option>blokir</option></select></div>
      ${sdm ? '' : `<div class="field"><label>Portal ortu</label><select id="sw-ortu">
        <option value="">semua</option><option value="belum">data belum lengkap</option></select></div>`}
    </div>
    <p class="muted">💡 Tap kartu di halaman ini untuk langsung menemukan pemiliknya / mendaftarkan kartu baru.</p>
    <div class="tblwrap"><table>
      <thead><tr><th></th><th>Nama</th><th>${sdm ? 'Golongan' : 'Kelas'}</th>
      <th>${sdm ? 'Unit &amp; jabatan' : 'NIS'}</th><th>UID Kartu</th>
      <th class="num">Saldo</th><th>Status</th><th>${sdm ? 'Berlaku sampai' : 'Portal ortu'}</th>
      <th>PIN kios</th><th></th></tr></thead>
      <tbody id="sw-body"></tbody>
    </table></div>
    <div class="pager">
      <span class="muted" id="sw-pageinfo"></span>
      <button id="sw-prev">‹</button><button id="sw-next">›</button>
    </div>
  </div>`;

  // Daftar Unit untuk SDM datang dari KODE (lewat /api/kelas), bukan dari data:
  // pilihannya harus tetap lengkap walau belum ada personil di unit tersebut.
  api('/api/kelas').then((d) => {
    UNIT_SDM = d.unit_sdm || UNIT_SDM;
    const pilihan = sdm ? UNIT_SDM : d.kelas;
    $('#sw-kelas').innerHTML = '<option value="">semua</option>' +
      pilihan.map((k) => `<option>${esc(k)}</option>`).join('');
  }).catch(() => {});

  const reload = () => { siswaState.page = 1; loadSiswa(); };
  $('#sw-cari').addEventListener('input', debounce(reload, 300));
  $('#sw-kelas').onchange = reload;
  $('#sw-status').onchange = reload;
  if ($('#sw-ortu')) $('#sw-ortu').onchange = reload;
  if ($('#sw-gol')) $('#sw-gol').onchange = reload;
  $('#sw-prev').onclick = () => { if (siswaState.page > 1) { siswaState.page--; loadSiswa(); } };
  $('#sw-next').onclick = () => { siswaState.page++; loadSiswa(); };
  if ($('#btn-add-siswa')) $('#btn-add-siswa').onclick = () => siswaModal(null);
  if ($('#btn-golongan')) $('#btn-golongan').onclick = kelolaGolonganModal;
  if ($('#btn-impor')) $('#btn-impor').onclick = () => imporKartuPanel(!!sdm);
  loadSiswa();
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

async function loadSiswa() {
  const p = new URLSearchParams({ page: siswaState.page, limit: 20 });
  const sdm = siswaState.sdm;
  if ($('#sw-cari').value.trim()) p.set('cari', $('#sw-cari').value.trim());
  if ($('#sw-kelas').value) p.set('kelas', $('#sw-kelas').value);
  if ($('#sw-status').value) p.set('status', $('#sw-status').value);
  if ($('#sw-ortu')?.value) p.set('ortu', $('#sw-ortu').value);
  // Tanpa parameter golongan server hanya mengirim santri; menu SDM menyebut
  // sisanya secara eksplisit supaya golongan yang ditambahkan admin ikut tampil.
  if (sdm) p.set('golongan', $('#sw-gol')?.value || kodeSdm());
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
      <td><b>${esc(s.nama)}</b>${sdm && s.nis
        ? `<br><span class="muted">NIY ${esc(s.nis)}</span>` : ''}</td>
      <td>${sdm ? esc(golDari(s.golongan).nama) : esc(s.kelas)}</td>
      <td>${sdm
        ? `${esc(s.kelas || '—')}${s.jabatan ? `<br><span class="muted">${esc(s.jabatan)}</span>` : ''}`
        : esc(s.nis)}</td>
      <td>${s.card_uid ? `<code>${esc(s.card_uid)}</code>` : '<span class="muted">belum ada</span>'}</td>
      <td class="num rp">${rp(s.saldo)}</td>
      <td><span class="badge ${s.status}">${s.status}</span></td>
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
        boleh(`${sdm ? 'sdm' : 'santri'}.ubah`) ? `<button class="linkbtn" data-act="edit" data-id="${s.id}">ubah</button>` : '',
        boleh(`${sdm ? 'sdm' : 'santri'}.kartu`) ? `<button class="linkbtn" data-act="kartu" data-id="${s.id}">kartu</button>` : '',
        boleh(`${sdm ? 'sdm' : 'santri'}.reset_pin`) ? `<button class="linkbtn" data-act="pin" data-id="${s.id}">reset PIN</button>` : '',
        boleh(`${sdm ? 'sdm' : 'santri'}.ubah`) ? `<button class="linkbtn" data-act="blokir" data-id="${s.id}">${s.status === 'aktif' ? 'blokir' : 'aktifkan'}</button>` : '',
      ].filter(Boolean).join(' · ') || '<span class="muted">—</span>'}</td>
    </tr>`).join('') ||
    `<tr><td colspan="10" class="muted">Tidak ada ${sdm ? 'personil' : 'santri'} cocok filter</td></tr>`;
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
    if (b.dataset.act === 'edit') b.onclick = () => siswaModal(s);
    if (b.dataset.act === 'kartu') b.onclick = () => assignCardModal(s);
    // Reset PIN kios: satu-satunya jalan keluar untuk santri yang lupa PIN —
    // sengaja hanya lewat admin, bukan lewat kios.
    if (b.dataset.act === 'pin') b.onclick = () => resetPinModal(s);
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
      <input id="m-uid" value="${esc(s?.card_uid || '')}" placeholder="tap kartu atau ketik manual"></div>
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
    </div>
    <fieldset class="ortu-set" id="m-ortu-set">
      <legend>Akses portal orang tua</legend>
      <p class="muted" style="margin:0 0 8px">Wali masuk ke <code>/ortu</code> memakai
        <b>NIS + tanggal lahir (ddmmyy)</b>, plus 4 digit terakhir HP wali bila sekolah masih
        memintanya (diatur di Dashboard → Kunci Masuk Portal Wali).</p>
      <div class="field"><label>NISN <span class="muted">(arsip sekolah, bukan kunci masuk)</span></label>
        <input id="m-nisn" inputmode="numeric" value="${esc(s?.nisn || '')}" placeholder="10 angka"></div>
      <div class="field"><label>Tanggal lahir</label>
        <input type="date" id="m-lahir" value="${esc(s?.tgl_lahir || '')}"></div>
      <div class="field"><label>No. HP wali</label>
        <input id="m-hp" inputmode="numeric" value="${esc(s?.hp_ortu || '')}" placeholder="08xxxxxxxxxx"></div>
    </fieldset>`,
    async () => {
      const body = {
        nama: $('#m-nama').value, kelas: $('#m-kelas').value,
        nis: $('#m-nis').value, card_uid: $('#m-uid').value.trim() || null,
        nisn: $('#m-nisn').value, tgl_lahir: $('#m-lahir').value, hp_ortu: $('#m-hp').value,
        golongan: $('#m-golongan').value, jabatan: $('#m-jabatan').value,
        berlaku_sampai: $('#m-berlaku').value, catatan: $('#m-catatan').value,
      };
      let id = s?.id;
      if (s) await api('/api/siswa/' + s.id, { method: 'PUT', body });
      else id = (await api('/api/siswa', { method: 'POST', body })).siswa.id;
      const f = s && $('#m-foto')?.files[0];
      if (f) {
        const data = await fileToDataUrl(f);
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
    $('#m-nis-hint').textContent = 'Harus khas — dipakai sebagai PIN bawaan kios dan alamat ' +
      'transfer antar teman' + (g.portal_ortu ? ', serta kunci masuk portal wali.' : '.');
    $('#m-ortu-set').classList.toggle('hidden', !g.portal_ortu);
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
        portal wali ${g.portal_ortu ? 'ya' : 'tidak'} ·
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
      <label class="cek"><input type="checkbox" id="gf-ortu" ${g?.portal_ortu ? 'checked' : ''}> bisa dibuka lewat portal wali</label>
      <label class="cek"><input type="checkbox" id="gf-kios" ${g === null || g?.kios ? 'checked' : ''}> boleh memakai layar kios</label>
      <label class="cek"><input type="checkbox" id="gf-transfer" ${g === null || g?.transfer ? 'checked' : ''}> boleh mengirim saldo ke teman</label>
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
      <input id="m-uid" value="${esc(presetUid || '')}" placeholder="menunggu tap kartu…"></div>`,
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
  </div>`;
  $('#btn-add-tenant').onclick = () => tenantModal(null);
  loadTenants();
}

async function loadTenants() {
  const d = await api('/api/tenants');
  $('#tn-body').innerHTML = d.tenants.map((t) => `
    <tr>
      <td><b>${esc(t.nama)}</b></td>
      <td>${t.jenis === 'koperasi'
        ? '<span class="badge kat-nm">koperasi</span>' : '<span class="muted">kantin</span>'}</td>
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
    ${t ? `
    <div class="field"><label>Status</label><select id="m-status">
      <option value="aktif" ${t.status === 'aktif' ? 'selected' : ''}>aktif</option>
      <option value="nonaktif" ${t.status === 'nonaktif' ? 'selected' : ''}>nonaktif</option></select></div>
    <div class="field"><label>Reset password akun (kosongkan jika tidak diganti)</label>
      <input id="m-pass" type="text" placeholder="password baru"></div>` : `
    <div class="field"><label>Username akun kasir</label><input id="m-user" placeholder="mis. sari"></div>
    <div class="field"><label>Password akun kasir</label><input id="m-pass" type="text"></div>`}`,
    async () => {
      if (t) {
        const d = await api('/api/tenants/' + t.id, { method: 'PUT', body: {
          nama: $('#m-nama').value, pemilik: $('#m-pemilik').value, jenis: $('#m-jenis').value,
          status: $('#m-status').value, password_baru: $('#m-pass').value || undefined,
        }});
        if (d.kategori_direset > 0)
          toast(`${d.kategori_direset} barang non-makanan ditandai ulang sebagai makanan`, 'ok');
      } else {
        await api('/api/tenants', { method: 'POST', body: {
          nama: $('#m-nama').value, pemilik: $('#m-pemilik').value, jenis: $('#m-jenis').value,
          username: $('#m-user').value, password: $('#m-pass').value,
        }});
      }
      toast('✔ Tenant disimpan', 'ok');
      loadTenants();
    });
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
    </div>
    <p class="muted">Lembar <b>${esc(pra.lembar)}</b> · berkas <b>${esc(pra.nama_berkas)}</b><br>${metode}
      ${r.n_nama_beda ? `<br>⚠️ ${r.n_nama_beda} baris namanya berbeda dari data santri` : ''}
      ${r.n_kembar ? `<br>ℹ️ ${r.n_kembar} baris memakai NIS yang sudah muncul di baris lain
        (boleh saja bila memang dua setoran)` : ''}</p>

    <div class="tblwrap" style="max-height:420px"><table>
      <thead><tr><th>Baris</th><th>NIS</th><th>Nama di berkas</th><th>Nama di data</th>
      <th class="num">Nominal</th><th class="num">Ke Kartu</th><th class="num">Ke Titipan</th>
      <th>Tanggal</th><th>Metode</th><th>Status</th></tr></thead>
      <tbody>${pra.baris.map((b) => `
        <tr class="${b.status === 'gagal' ? 'tp-menunggu' : ''}">
          <td>${b.baris}</td><td><code>${esc(b.nis || '—')}</code></td>
          <td>${esc(b.nama_berkas || '—')}</td>
          <td>${esc(b.nama_db || '—')}${b.kelas ? ` <span class="muted">${esc(b.kelas)}</span>` : ''}</td>
          <td class="num rp">${rp(b.jumlah)}</td>
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
      <p><b>${rp(r.total)}</b> akan masuk ke rekening siswa: ${rp(r.total_saldo)} ke kartu dan
        ${rp(r.total_titipan)} mengendap sebagai titipan.</p>
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
        <th>Metode</th><th>Tgl bayar</th><th>Pengirim</th><th>Bukti</th><th>Status</th><th></th></tr></thead>
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

async function muatAntreanTopup() {
  try {
    const d = await api('/api/topup-ortu' + (tpStatus ? '?status=' + tpStatus : ''));
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
        <td><span class="badge tp-${esc(p.status)}">${esc(TP_STATUS[p.status] || p.status)}</span>
          ${p.status === 'ditolak' && p.alasan ? `<br><span class="muted">${esc(p.alasan)}</span>` : ''}
          ${p.status === 'disetujui' ? `<br><span class="muted">oleh ${esc(p.operator)}</span>` : ''}</td>
        <td style="white-space:nowrap">${p.status === 'pending'
          ? `<button class="linkbtn" data-setuju="${p.id}">Setujui</button>
             <button class="linkbtn danger" data-tolak="${p.id}">Tolak</button>`
          : ''}</td>
      </tr>`).join('') || '<tr><td colspan="10" class="muted">Belum ada pengajuan pada filter ini</td></tr>';

    const cari = (id) => d.pengajuan.find((x) => x.id === Number(id));
    $('#tp-baris').querySelectorAll('[data-setuju]').forEach((b) => {
      b.onclick = () => tpSetujuiModal(cari(b.dataset.setuju));
    });
    $('#tp-baris').querySelectorAll('[data-tolak]').forEach((b) => {
      b.onclick = () => tpTolakModal(cari(b.dataset.tolak));
    });
  } catch (e) { toast(e.message, 'err'); }
}

async function muatPengaturanTopup() {
  try {
    const d = await api('/api/topup-ortu/pengaturan');
    $('#tp-aktif').value = String(d.pengaturan.topup_ortu_aktif);
    isiUang('#tp-min', d.pengaturan.topup_ortu_min);
    isiUang('#tp-maks', d.pengaturan.topup_ortu_maks);
    $('#tp-antre').value = d.pengaturan.topup_ortu_antre;
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
      ${p.bukti
        ? `<img class="tp-bukti-besar" src="/uploads/${encodeURIComponent(p.bukti)}" alt="Bukti pembayaran">`
        : '<p class="muted" style="margin:10px 0 0">Tidak ada bukti dilampirkan.</p>'}
    </div>`,
    () => tpKonfirmasiKedua(p), 'Lanjut ›');
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
