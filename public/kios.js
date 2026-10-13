'use strict';
/*
 * Kios Santri — layar mandiri yang dipasang di depan reader kartu.
 *
 * Aturan alur (ditegakkan juga di server, ini hanya cerminannya di layar):
 *   - Cek saldo cukup TAP.
 *   - Donasi: TAP lalu PIN. Jenis & besaran donasi mengikuti daftar admin.
 *   - Ganti PIN: TAP lalu PIN lama + PIN baru. PIN bawaan = NIS.
 *   - Lupa PIN tidak ada jalan keluarnya di sini — harus ke admin kantin.
 *
 * Tidak ada login dan tidak ada cookie: sesi kios berumur pendek, hidup di
 * memori server, dan ditutup begitu santri selesai / diam terlalu lama.
 */

const $ = (s) => document.querySelector(s);
const rp = (n) => 'Rp' + Number(n || 0).toLocaleString('id-ID');
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const normUid = (s) => String(s || '').replace(/[^0-9a-zA-Z]/g, '').toUpperCase();
const initials = (nama) => String(nama || '?').split(/\s+/).slice(0, 2).map((w) => w[0] || '').join('').toUpperCase();

/*
 * Ikon digambar sebagai SVG, bukan emoji: kios sekolah biasanya PC Linux tanpa
 * papan huruf emoji, dan di sana emoji muncul sebagai kotak kosong.
 */
const IKON = {
  kirim: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/>' +
    '<path d="M22 2 15 22l-4-9-9-4Z"/></svg>',
  kartu: `<svg viewBox="0 0 64 44" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
    <rect x="2" y="2" width="42" height="40" rx="6"/><path d="M2 14h42"/><circle cx="12" cy="28" r="3"/><path d="M20 28h14"/>
    <path d="M51 14a12 12 0 0 1 0 16M58 8a20 20 0 0 1 0 28"/></svg>`,
  hati: `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 21s-8-5.1-8-11a4.6 4.6 0 0 1 8-3 4.6 4.6 0 0 1 8 3c0 5.9-8 11-8 11z"/></svg>`,
  gembok: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
    <rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15.5" r="1.4" fill="currentColor"/></svg>`,
  centang: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12.5l5.5 5.5L20 7"/></svg>`,
  silang: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>`,
  riwayat: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="9"/><path d="M12 7v5.5l3.5 2"/></svg>`,
  iuran: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c.7-3.3 3.1-5.2 6.5-5.2S15 16.7 15.5 20"/>
    <path d="M16 4.8a3.2 3.2 0 0 1 0 6.6M18.2 14.5c1.8.6 3 2.2 3.3 5"/></svg>`,
  tabungan: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1" fill="currentColor"/></svg>`,
};

const BULAN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
/** '2026-08' → 'Agustus 2026' */
function namaBulan(kode) {
  const [th, bl] = String(kode || '').split('-');
  return `${BULAN[Number(bl) - 1] || '?'} ${th}`;
}

let toastTimer;
function toast(msg, kind) {
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'show' + (kind ? ' ' + kind : '');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (t.className = ''), 4000);
}

async function api(path, body) {
  const r = await fetch(path, {
    method: body === undefined ? 'GET' : 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const d = await r.json().catch(() => ({}));
  if (!r.ok) {
    const e = new Error(d.error || 'Gagal');
    e.status = r.status;
    throw e;
  }
  return d;
}

// ========================================================== keadaan layar
let INFO = { aktif: true, program: [], maks_donasi_harian: 0 };
let SESI = null;            // hasil /api/kios/tap untuk santri yang sedang di depan layar
let layar = 'idle';         // idle | santri | program | nominal | pin | riwayat | hasil | tutup
let alur = null;            // rincian alur yang sedang berjalan (donasi / ganti pin / riwayat)
let RIW = null;             // riwayat yang sedang dibaca — dibuang bersama sesi
let pin = '';               // isian PIN sementara — tidak pernah disimpan ke mana pun
let sisaDetik = 0;
let jamTimer = null;

// ========================================================== hitung mundur
// Sesi kios ditutup sendiri kalau santri meninggalkan layar tanpa menekan
// "Selesai" — kartu berikutnya tidak boleh menumpang sesi orang sebelumnya.
let mundurTimer = null;
function mulaiMundur(detik) {
  hentikanMundur();
  sisaDetik = detik;
  mundurTimer = setInterval(() => {
    sisaDetik -= 1;
    const el = $('#k-mundur');
    if (el) el.textContent = sisaDetik <= 15 ? `Kembali ke layar awal dalam ${sisaDetik} detik` : '';
    if (sisaDetik <= 0) selesai(true);
  }, 1000);
}
function hentikanMundur() { clearInterval(mundurTimer); mundurTimer = null; }
/** Setiap sentuhan santri memperpanjang waktu layar. */
function segarkanMundur() { if (mundurTimer && SESI) sisaDetik = SESI.sesi_detik || 60; }

// ====================================================== mode layar penuh
/*
 * Yang BISA dijamin halaman ini: begitu layar penuh lepas, kios langsung
 * terkunci dan tidak bisa dipakai sampai sandi staf dimasukkan (diperiksa di
 * server, bukan di berkas ini).
 *
 * Yang TIDAK bisa: menahan Esc/F11. Peramban memang tidak mengizinkan halaman
 * membatalkan keluar dari layar penuh — itu jaminan keamanan peramban, bukan
 * kekurangan yang bisa ditambal. Penguncian sungguhan urusan cara peramban
 * dijalankan (`chrome --kiosk`), lihat README bagian "Mode layar penuh".
 *
 * Status "mode kios menyala" disimpan di localStorage supaya listrik kedip atau
 * peramban tertutup tidak diam-diam mengembalikan kios ke keadaan terbuka.
 */
const KUNCI_KEY = 'kios-mode-terkunci';
let TERKUNCI = false;     // gembok sedang menutup layar
let sandi = '';           // isian sandi staf — tidak pernah disimpan
const modeKios = () => localStorage.getItem(KUNCI_KEY) === '1';

async function mintaLayarPenuh() {
  // requestFullscreen HANYA boleh dipanggil dari sentuhan/klik pengguna, jadi
  // kios tidak akan pernah bisa masuk layar penuh sendiri sesudah dimuat ulang.
  try { await document.documentElement.requestFullscreen(); } catch (e) {
    toast('Peramban menolak layar penuh: ' + e.message, 'err');
  }
}

async function nyalakanModeKios() {
  localStorage.setItem(KUNCI_KEY, '1');
  await mintaLayarPenuh();
  perbaruiGembok();
}

function matikanModeKios() {
  localStorage.removeItem(KUNCI_KEY);
  TERKUNCI = false;
  sandi = '';
  gambarGembok();
  if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
  render();
  toast('Mode kios dimatikan');
}

function perbaruiGembok() {
  const perluKunci = modeKios() && !document.fullscreenElement;
  if (perluKunci && !TERKUNCI) {
    TERKUNCI = true;
    sandi = '';
    // Layar penuh lepas saat ada santri di depan layar → tutup sesinya. Data
    // santri tidak boleh tertinggal di balik gembok untuk dibaca orang lain.
    if (SESI) selesai(false);
  }
  if (!perluKunci) TERKUNCI = false;
  gambarGembok();
}
document.addEventListener('fullscreenchange', perbaruiGembok);

const HURUF = ['qwertyuiop', 'asdfghjkl', 'zxcvbnm'];
function gambarGembok() {
  const el = $('#k-gembok');
  if (!TERKUNCI) { el.className = 'hidden'; el.innerHTML = ''; return; }
  el.className = '';
  const titik = Array.from({ length: Math.max(sandi.length, 0) }, () => '<span class="k-titik isi"></span>').join('')
    || '<span class="k-sub">ketik sandi…</span>';
  const baris = HURUF.map((r) => `<div class="k-krow">${[...r]
    .map((c) => `<button class="k-ktuts" data-huruf="${c}">${c}</button>`).join('')}</div>`).join('');
  const angka = `<div class="k-krow">${[...'1234567890']
    .map((c) => `<button class="k-ktuts" data-huruf="${c}">${c}</button>`).join('')}</div>`;
  el.innerHTML = `<div class="k-gembok-kotak">
    <span class="k-gembok-ikon">${IKON.gembok}</span>
    <h1>Layar Kios Terkunci</h1>
    <p class="k-sub">Layar penuh terlepas. Tekan <b>Lanjutkan</b> untuk kembali,
      atau masukkan sandi staf untuk keluar dari mode kios.</p>
    <button class="k-btn k-btn-utama" data-gembok="lanjut">Lanjutkan — kembali ke layar penuh</button>
    <div class="k-gembok-sandi">
      <div class="k-titik-baris">${titik}</div>
      ${angka}${baris}
      <div class="k-krow">
        <button class="k-ktuts k-ktuts-lain" data-gembok="hapus">⌫ Hapus</button>
        <button class="k-ktuts k-ktuts-ok" data-gembok="buka">Buka Kunci</button>
      </div>
      <p class="k-sub">Papan ketik di atas untuk layar sentuh. Kalau kios punya papan
        ketik sungguhan, ketik langsung lalu tekan Enter.</p>
    </div>
  </div>`;
  el.querySelectorAll('[data-huruf]').forEach((b) => {
    b.onclick = () => { if (sandi.length < 64) { sandi += b.dataset.huruf; gambarGembok(); } };
  });
  el.querySelectorAll('[data-gembok]').forEach((b) => {
    b.onclick = () => {
      const a = b.dataset.gembok;
      if (a === 'lanjut') mintaLayarPenuh();
      else if (a === 'hapus') { sandi = sandi.slice(0, -1); gambarGembok(); }
      else if (a === 'buka') kirimSandiLayar();
    };
  });
}

async function kirimSandiLayar() {
  if (!sandi) { toast('Sandi masih kosong', 'err'); return; }
  const isian = sandi;
  sandi = '';
  gambarGembok();
  try {
    await api('/api/kios/buka-layar', { sandi: isian });
    matikanModeKios();
  } catch (e) { toast(e.message, 'err'); }
}

// ========================================================== pembaca kartu
// Reader keyboard-wedge mengetik UID + Enter sangat cepat (< 100 ms antar
// karakter). Ketikan lambat dari papan ketik biasa tidak akan tertangkap.
let kbBuf = '', kbLast = 0, tapTerakhir = { uid: '', at: 0 };
document.addEventListener('keydown', (e) => {
  // Selama gembok menutup layar, semua ketikan milik isian sandi — reader kartu
  // dan keypad PIN mati total, supaya kartu yang tertempel tidak membuka sesi
  // di belakang gembok.
  if (TERKUNCI) {
    if (e.key === 'Enter') { e.preventDefault(); kirimSandiLayar(); return; }
    if (e.key === 'Backspace') { sandi = sandi.slice(0, -1); gambarGembok(); return; }
    if (e.key.length === 1 && sandi.length < 64) { sandi += e.key; gambarGembok(); }
    return;
  }
  const now = performance.now();
  if (now - kbLast > 100) kbBuf = '';
  kbLast = now;
  if (e.key === 'Enter') {
    const uid = normUid(kbBuf);
    kbBuf = '';
    e.preventDefault();
    if (uid.length >= 6) kartuDitempel(uid);
    else if (layar === 'pin') ketikPin('ok');
    return;
  }
  // Papan ketik angka boleh dipakai kalau kios memang punya satu. Angka dari
  // reader ikut masuk ke sini, tapi tidak jadi masalah: begitu Enter-nya tiba
  // dan terbaca sebagai kartu, isian PIN dikosongkan ulang oleh kartuDitempel.
  if (e.key.length === 1) {
    kbBuf += e.key;
    if (layar === 'pin' && /^\d$/.test(e.key)) ketikPin(e.key);
    return;
  }
  if (e.key === 'Backspace' && layar === 'pin') ketikPin('hapus');
});

async function kartuDitempel(uid) {
  if (TERKUNCI) return;           // kios terkunci: tap tidak membuka sesi apa pun
  const now = Date.now();
  if (uid === tapTerakhir.uid && now - tapTerakhir.at < 2000) return;   // anti baca ganda
  tapTerakhir = { uid, at: now };
  pin = '';                       // buang sisa ketikan reader di layar PIN
  // Tap kapan saja langsung membuka sesi baru: kalau santri berikutnya sudah
  // menempel kartu, layar tidak boleh tertinggal di data santri sebelumnya.
  try {
    const d = await api('/api/kios/tap', { uid });
    if (!d.terdaftar) { SESI = null; RIW = null; layar = 'tutup'; render({ pesan: 'Kartu ini belum terdaftar. Silakan ke admin kantin.' }); return; }
    SESI = d;
    pin = ''; alur = null; RIW = null;   // riwayat santri sebelumnya tidak boleh tertinggal di layar
    layar = 'siswa';
    render();
    mulaiMundur(d.sesi_detik || 60);
  } catch (e) {
    SESI = null; RIW = null;
    layar = 'tutup';
    render({ pesan: e.message });
    setTimeout(() => { if (layar === 'tutup') keIdle(); }, 6000);
  }
}

// ========================================================== navigasi layar
async function selesai(otomatis) {
  hentikanMundur();
  const token = SESI?.token;
  SESI = null; pin = ''; alur = null; RIW = null;
  if (token) api('/api/kios/selesai', { token }).catch(() => {});
  keIdle();
  if (otomatis) toast('Sesi ditutup otomatis');
}

async function keIdle() {
  layar = 'idle';
  render();
  try { INFO = await api('/api/kios/info'); } catch { /* biarkan daftar lama */ }
  if (layar === 'idle') render();
}

function kembaliKeSiswa() { layar = 'siswa'; pin = ''; alur = null; segarkanMundur(); render(); }

// ========================================================== penggambaran
function render(extra) {
  const el = $('#k-layar');
  if (layar === 'idle') el.innerHTML = idleHtml();
  else if (layar === 'tutup') el.innerHTML = tutupHtml(extra?.pesan || '');
  else if (layar === 'siswa') el.innerHTML = siswaHtml();
  else if (layar === 'program') el.innerHTML = programHtml();
  else if (layar === 'nominal') el.innerHTML = nominalHtml();
  else if (layar === 'trf-nis') el.innerHTML = nisHtml();
  else if (layar === 'trf-nominal') el.innerHTML = trfNominalHtml();
  else if (layar === 'iur-pilih') el.innerHTML = iuranPilihHtml();
  else if (layar === 'iur-nominal') el.innerHTML = iuranNominalHtml();
  else if (layar === 'tab-pilih') el.innerHTML = tabPilihHtml();
  else if (layar === 'tab-nominal') el.innerHTML = tabNominalHtml();
  else if (layar === 'papan-ketik') el.innerHTML = papanKetikHtml();
  else if (layar === 'pin') el.innerHTML = pinHtml();
  else if (layar === 'riwayat') el.innerHTML = riwayatHtml();
  else if (layar === 'hasil') el.innerHTML = hasilHtml(extra);
  pasangAksi();
}

function kepalaHtml(judul) {
  return `<header class="k-head">
    <img src="/img/logo-imbos.png" alt="">
    <div class="k-head-judul"><b>${esc(judul)}</b><span>IMBOS Cashless · Insan Mulia Boarding School</span></div>
    <span class="k-mundur" id="k-mundur"></span>
    <button class="k-keluar" data-aksi="selesai">Selesai</button>
  </header>`;
}

function idleHtml() {
  if (!INFO.aktif) {
    return `<div class="k-idle">
      <img class="k-logo" src="/img/logo-imbos.png" alt="Logo IMBOS">
      <h1>Kios Santri sedang ditutup</h1>
      <p class="k-sub">Silakan hubungi admin kantin.</p>
    </div>`;
  }
  const program = INFO.program.map((p) => `
    <div class="k-prog-kecil">
      <b>${esc(p.nama)}</b>
      <span>${p.nominal.map((n) => rp(n)).join(' · ')}</span>
    </div>`).join('');
  return `<div class="k-idle">
    <img class="k-logo" src="/img/logo-imbos.png" alt="Logo IMBOS">
    <h1>Kios Santri IMBOS Cashless</h1>
    <div class="k-tapzone"><span class="k-tap-ikon">${IKON.kartu}</span>
      <b>Tempelkan kartu ke pembaca</b>
      <span class="k-sub">Cek saldo cukup tap · Donasi: tap lalu masukkan PIN</span>
    </div>
    ${program ? `<div class="k-prog-list"><span class="k-sub">Donasi yang sedang dibuka</span>${program}</div>` : ''}
    <div class="k-jam" id="k-jam"></div>
    ${modeKios()
      ? '<p class="k-sub k-mode-kios">Mode kios menyala — keluar dari layar penuh akan mengunci layar ini.</p>'
      : '<button class="k-btn k-btn-kecil k-mode-kios" data-aksi="kunci">Nyalakan mode kios (layar penuh)</button>'}
  </div>`;
}

function tutupHtml(pesan) {
  return `<div class="k-idle">
    <img class="k-logo" src="/img/logo-imbos.png" alt="Logo IMBOS">
    <h1>${esc(pesan)}</h1>
    <button class="k-btn k-btn-utama" data-aksi="idle">Kembali</button>
  </div>`;
}

function avatarKios() {
  const s = SESI.siswa;
  // Foto diambil dengan token sesi kios (lihat penyaji /uploads di server).
  // Kalau berkasnya hilang, ikon inisial menggantikan — jangan sampai layar
  // kios memajang gambar rusak.
  if (s.foto) return `<img class="k-avatar" src="/uploads/${esc(s.foto)}?kios=${esc(SESI.token)}" alt=""
    onerror="avatarInisial(this)">`;
  return `<div class="k-avatar">${esc(initials(s.nama))}</div>`;
}

function avatarInisial(img) {
  const d = document.createElement('div');
  d.className = 'k-avatar';
  d.textContent = initials(SESI?.siswa?.nama);
  img.replaceWith(d);
}

/**
 * Satu baris transaksi. `denganSaldo` menambahkan saldo sesudah transaksi,
 * seperti buku tabungan — hanya dipakai di layar riwayat, di kartu depan
 * kolom itu justru mengaburkan saldo berjalan yang sudah dipajang besar.
 */
function barisTxHtml(t, denganSaldo) {
  const plus = t.jumlah >= 0;
  const wkt = new Date(t.created_at).toLocaleString('id-ID',
    { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
  const label = t.tipe === 'beli' ? (t.tenant_nama || 'Jajan')
    : t.tipe === 'topup' ? 'Isi saldo'
    : t.tipe === 'donasi' ? (t.keterangan || 'Donasi')
    : t.tipe === 'tabungan' ? (t.keterangan || 'Tabungan')
    : t.tipe === 'transfer_keluar' ? (t.keterangan || 'Kirim ke teman')
    : t.tipe === 'transfer_masuk' ? (t.keterangan || 'Terima dari teman') : 'Penyesuaian';
  const ket = t.tipe === 'beli' && t.keterangan ? ` · ${t.keterangan}` : '';
  return `<div class="k-tx">
    <span class="k-tx-ikon ${plus ? 'plus' : 'minus'}">${plus ? '+' : '−'}</span>
    <span class="k-tx-isi"><b>${esc(label)}</b><span class="k-sub">${esc(wkt + ket)}</span></span>
    <span class="k-tx-nilai ${plus ? 'plus' : 'minus'}">${plus ? '+' : '−'}${rp(Math.abs(t.jumlah))}
      ${denganSaldo ? `<small class="k-tx-saldo">sisa ${rp(t.saldo_sesudah)}</small>` : ''}</span>
  </div>`;
}

function siswaHtml() {
  const s = SESI.siswa;
  const tx = (SESI.transaksi || []).map((t) => barisTxHtml(t, false)).join('')
    || '<p class="k-sub">Belum ada transaksi.</p>';

  const bisaDonasi = s.status === 'aktif' && SESI.program.length > 0 && SESI.sisa_donasi > 0;
  const trf = keadaanTransfer();
  const iur = keadaanIuran();
  const tab = keadaanTabungan();
  const saldoTabungan = SESI.tabungan_saldo || [];
  return `${kepalaHtml('Saldo Kartu')}
  <main class="k-main">
    <div class="k-kartu">
      ${avatarKios()}
      <div class="k-kartu-id">
        <b>${esc(s.nama)}</b>
        <span class="k-sub">Kelas ${esc(s.kelas || '-')}</span>
        ${s.status !== 'aktif' ? '<span class="k-badge-blokir">Kartu diblokir</span>' : ''}
      </div>
      <div class="k-kartu-saldo">
        <span class="k-sub">Saldo saat ini</span>
        <b>${rp(s.saldo)}</b>
        ${s.saldo_titipan > 0
          ? `<span class="k-sub">+ titipan ${rp(s.saldo_titipan)}</span>` : ''}
      </div>
    </div>

    ${SESI.smart ? `<div class="titipan-catatan">
      Saldo kartu baru saja terisi ulang otomatis <b>${rp(SESI.smart.isi)}</b> dari saldo titipan.
      Sisa titipan <b>${rp(SESI.smart.sisa_titipan)}</b> akan mengisi kartu lagi saat saldo menipis.
    </div>` : s.saldo_titipan > 0 ? `<div class="titipan-catatan">
      Ada <b>${rp(s.saldo_titipan)}</b> saldo titipan atas nama Anda — belum bisa dibelanjakan,
      dan akan otomatis mengisi kartu ketika saldo mulai habis.
    </div>` : ''}

    ${saldoTabungan.length ? `<div class="k-panel">
      <h2>Tabungan Saya</h2>
      ${saldoTabungan.map((t) => `
        <div class="k-tx">
          <span class="k-tx-ikon plus">●</span>
          <span class="k-tx-isi"><b>${esc(t.nama)}</b>${t.target ? `<span class="k-sub">target ${rp(t.target)}</span>` : ''}</span>
          <span class="k-tx-nilai plus">${rp(t.saldo)}</span>
        </div>`).join('')}
    </div>` : ''}

    ${SESI.pin_bawaan && SESI.pin_siap ? `<div class="k-peringatan">
      <span class="k-ikon-kecil">${IKON.gembok}</span> PIN Anda masih PIN bawaan (NIS). Ganti sekarang agar kartu Anda aman.</div>` : ''}
    ${!SESI.pin_siap ? `<div class="k-peringatan">
      <span class="k-ikon-kecil">${IKON.gembok}</span> PIN belum bisa dipakai karena NIS Anda belum terdaftar. Hubungi admin kantin.</div>` : ''}

    <div class="k-aksi">
      ${trf.bisa || trf.alasan ? `
      <button class="k-btn k-btn-utama" data-aksi="transfer" ${trf.bisa ? '' : 'disabled'}>
        <span class="k-btn-ikon">${IKON.kirim}</span> Kirim ke Teman
        <small>${trf.bisa
          ? `pakai NIS teman · maks ${rp(SESI.transfer.maks)}`
          : esc(trf.alasan)}</small>
      </button>` : ''}
      ${iur.bisa || iur.alasan ? `
      <button class="k-btn k-btn-utama" data-aksi="iuran" ${iur.bisa ? '' : 'disabled'}>
        <span class="k-btn-ikon">${IKON.iuran}</span> Bayar Iuran
        <small>${iur.bisa ? 'pilih penanggung jawab' : esc(iur.alasan)}</small>
      </button>` : ''}
      <button class="k-btn k-btn-utama" data-aksi="donasi" ${bisaDonasi ? '' : 'disabled'}>
        <span class="k-btn-ikon">${IKON.hati}</span> Donasi
        <small>${s.status !== 'aktif' ? 'kartu diblokir'
          : !SESI.program.length ? 'belum ada donasi dibuka'
          : SESI.sisa_donasi <= 0 ? 'batas donasi hari ini tercapai'
          : 'tap sudah, tinggal PIN'}</small>
      </button>
      ${tab.bisa || tab.alasan ? `
      <button class="k-btn k-btn-utama" data-aksi="tabungan" ${tab.bisa ? '' : 'disabled'}>
        <span class="k-btn-ikon">${IKON.tabungan}</span> Tabungan
        <small>${tab.bisa ? 'pilih jenis, lalu PIN' : esc(tab.alasan)}</small>
      </button>` : ''}
      <button class="k-btn" data-aksi="riwayat" ${SESI.pin_siap ? '' : 'disabled'}>
        <span class="k-btn-ikon">${IKON.riwayat}</span> Riwayat
        <small>${SESI.pin_siap ? 'perlu PIN · bisa pilih periode' : 'NIS belum terdaftar'}</small>
      </button>
      <button class="k-btn" data-aksi="ganti-pin" ${SESI.pin_siap ? '' : 'disabled'}>
        <span class="k-btn-ikon">${IKON.gembok}</span> Ganti PIN<small>PIN bawaan = NIS</small>
      </button>
      <button class="k-btn" data-aksi="selesai"><span class="k-btn-ikon">${IKON.centang}</span> Selesai<small>tutup layar ini</small></button>
    </div>

    <div class="k-panel">
      <h2>5 transaksi terakhir</h2>
      ${tx}
      <p class="k-sub">Riwayat lebih lama ada di tombol <b>Riwayat</b> di atas.</p>
    </div>
    <p class="k-sub k-catatan">Donasi hari ini ${rp(SESI.donasi_hari_ini)} dari batas ${rp(SESI.maks_donasi_harian)}.
      Lupa PIN? Hubungi admin kantin — PIN hanya bisa direset oleh admin.</p>
  </main>`;
}

function programHtml() {
  const kartu = SESI.program.map((p) => `
    <button class="k-pilih" data-program="${p.id}">
      <b>${esc(p.nama)}</b>
      ${p.deskripsi ? `<span class="k-sub">${esc(p.deskripsi)}</span>` : ''}
      <span class="k-nominal-ringkas">${p.nominal.map((n) => rp(n)).join(' · ')}</span>
    </button>`).join('');
  return `${kepalaHtml('Pilih Jenis Donasi')}
  <main class="k-main">
    <p class="k-sub">Untuk <b>${esc(SESI.siswa.nama)}</b> · saldo ${rp(SESI.siswa.saldo)} ·
      sisa batas donasi hari ini ${rp(SESI.sisa_donasi)}</p>
    <div class="k-pilihan">${kartu}</div>
    <button class="k-btn k-btn-kecil" data-aksi="kembali">‹ Kembali</button>
  </main>`;
}

function nominalHtml() {
  const p = alur.program;
  const tombol = p.nominal.map((n) => {
    const cukup = n <= SESI.siswa.saldo && n <= SESI.sisa_donasi;
    return `<button class="k-nominal" data-jumlah="${n}" ${cukup ? '' : 'disabled'}>
      ${rp(n)}${cukup ? '' : '<small>tidak cukup</small>'}</button>`;
  }).join('');
  return `${kepalaHtml('Pilih Besaran Donasi')}
  <main class="k-main">
    <p class="k-sub"><b>${esc(p.nama)}</b> · saldo ${rp(SESI.siswa.saldo)} ·
      sisa batas hari ini ${rp(SESI.sisa_donasi)}</p>
    <div class="k-nominal-grid">${tombol}</div>
    <p class="k-sub">Besaran donasi ditentukan sekolah — tidak bisa diketik bebas.</p>
    <button class="k-btn k-btn-kecil" data-aksi="program">‹ Ganti jenis donasi</button>
  </main>`;
}

// ======================================================= transfer ke teman
// Alurnya: tombol → ketik NIS teman → layar konfirmasi nama → pilih nominal →
// PIN. Nama penerima ditampilkan SEBELUM nominal diminta dan sekali lagi di
// layar PIN, karena satu angka salah pada NIS berarti uang mendarat di kartu
// orang lain dan tidak ada tombol batal setelah itu.

/**
 * Boleh-tidaknya mengirim, beserta alasannya kalau tidak. Tombolnya sengaja
 * tetap terlihat (dalam keadaan mati) ketika alasannya bersifat sementara —
 * "jatah hari ini habis" adalah keterangan yang dicari santri, sedangkan tombol
 * yang lenyap tanpa jejak hanya membuatnya menekan-nekan layar.
 */
function keadaanTransfer() {
  const t = SESI?.transfer;
  if (!t) return { bisa: false, alasan: '' };            // sekolah mematikan fiturnya
  if (t.bisa) return { bisa: true, alasan: '' };
  if (SESI.siswa.status !== 'aktif') return { bisa: false, alasan: 'kartu diblokir' };
  if (t.sisa_kali <= 0) return { bisa: false, alasan: 'jatah kiriman hari ini habis' };
  if (t.sisa_harian < t.min) return { bisa: false, alasan: 'batas kirim hari ini tercapai' };
  if (SESI.siswa.saldo < t.min) return { bisa: false, alasan: `saldo kurang dari ${rp(t.min)}` };
  return { bisa: false, alasan: 'belum bisa dipakai' };
}

/** Sama seperti keadaanTransfer(), plus alasan khusus daftar PJ kosong. */
function keadaanIuran() {
  const t = SESI?.iuran;
  if (!t) return { bisa: false, alasan: '' };
  if (t.bisa) return { bisa: true, alasan: '' };
  if (SESI.siswa.status !== 'aktif') return { bisa: false, alasan: 'kartu diblokir' };
  if (!t.daftar || !t.daftar.length) return { bisa: false, alasan: 'belum ada PJ terdaftar' };
  if (t.sisa_kali <= 0) return { bisa: false, alasan: 'jatah bayar hari ini habis' };
  if (t.sisa_harian < t.min) return { bisa: false, alasan: 'batas bayar hari ini tercapai' };
  if (SESI.siswa.saldo < t.min) return { bisa: false, alasan: `saldo kurang dari ${rp(t.min)}` };
  return { bisa: false, alasan: 'belum bisa dipakai' };
}

function keadaanTabungan() {
  const t = SESI?.tabungan;
  if (!t) return { bisa: false, alasan: '' };
  if (t.bisa) return { bisa: true, alasan: '' };
  if (SESI.siswa.status !== 'aktif') return { bisa: false, alasan: 'kartu diblokir' };
  if (!SESI.tabungan_jenis || !SESI.tabungan_jenis.length) return { bisa: false, alasan: 'belum ada jenis dibuka' };
  if (t.sisa_harian < t.min) return { bisa: false, alasan: 'batas menabung hari ini tercapai' };
  if (SESI.siswa.saldo < t.min) return { bisa: false, alasan: `saldo kurang dari ${rp(t.min)}` };
  return { bisa: false, alasan: 'belum bisa dipakai' };
}

function nisHtml() {
  const nis = alur.nis || '';
  return `${kepalaHtml('Kirim Saldo ke Teman')}
  <main class="k-main">
    <p class="k-sub">Dari <b>${esc(SESI.siswa.nama)}</b> · saldo ${rp(SESI.siswa.saldo)} ·
      sisa jatah kirim hari ini ${rp(SESI.transfer.sisa_harian)}</p>
    <div class="k-isian-besar">
      <span class="k-isian-label">NIS teman yang dituju</span>
      <b class="k-isian-nilai">${nis ? esc(nis) : '<span class="k-isian-kosong">— — — —</span>'}</b>
    </div>
    ${papanAngkaHtml('cari', 'Cari', !nis)}
    <p class="k-sub">NIS tercetak di kartu kantin temanmu. Nama pemiliknya akan
      ditampilkan dulu sebelum uang dikirim.</p>
    <button class="k-btn k-btn-kecil" data-aksi="kembali">‹ Batal</button>
  </main>`;
}

function trfNominalHtml() {
  const p = alur.penerima;
  const maks = alur.maks;
  // Pilihan cepat: kelipatan yang lazim, disaring ke jatah yang benar-benar
  // tersisa. Mengetik sendiri tetap bisa lewat papan angka di bawahnya.
  const cepat = [1000, 2000, 5000, 10000, 20000, 25000]
    .filter((n) => n >= alur.min && n <= maks);
  const ketik = alur.jumlah || 0;
  return `${kepalaHtml('Berapa yang Dikirim?')}
  <main class="k-main">
    <div class="k-penerima">
      <span class="k-sub">Uang akan masuk ke kartu</span>
      <b>${esc(p.nama)}</b>
      <span class="k-sub">${esc(p.label_unit)} ${esc(p.kelas || '-')} · ${esc(p.golongan)} · NIS ${esc(alur.nis)}</span>
    </div>
    <div class="k-nominal-grid">${cepat.map((n) => `
      <button class="k-nominal ${ketik === n ? 'sel' : ''}" data-trf-cepat="${n}">${rp(n)}</button>`).join('')}
    </div>
    <div class="k-isian-besar">
      <span class="k-isian-label">Nominal</span>
      <b class="k-isian-nilai">${ketik ? rp(ketik) : '<span class="k-isian-kosong">Rp0</span>'}</b>
    </div>
    ${papanAngkaHtml('kirim', 'Lanjut', !(ketik >= alur.min && ketik <= maks))}
    <p class="k-sub">Antara ${rp(alur.min)} dan ${rp(maks)} —
      dibatasi saldo dan sisa jatah kirim hari ini.</p>
    <button class="k-btn k-btn-kecil" data-aksi="transfer-ulang">‹ Ganti NIS teman</button>
  </main>`;
}

/** Papan angka yang sama dipakai untuk NIS maupun nominal. */
function papanAngkaHtml(aksiOk, labelOk, matikanOk) {
  const angka = [1, 2, 3, 4, 5, 6, 7, 8, 9].map((n) =>
    `<button class="k-tuts" data-angka="${n}">${n}</button>`).join('');
  return `<div class="k-keypad">
    ${angka}
    <button class="k-tuts k-tuts-lain" data-angka="hapus">⌫</button>
    <button class="k-tuts" data-angka="0">0</button>
    <button class="k-tuts k-tuts-ok" data-angka="${aksiOk}" ${matikanOk ? 'disabled' : ''}>${esc(labelOk)}</button>
  </div>`;
}

// ========================================================= iuran ke PJ
// Alurnya: tombol → pilih PJ dari DAFTAR (bukan ketik NIY, santri tidak
// mungkin hafal) → nominal (keypad, sama seperti transfer) → deskripsi WAJIB
// (papan ketik di layar, lihat papanKetikHtml) → PIN.

function iuranPilihHtml() {
  const daftar = SESI.iuran.daftar || [];
  return `${kepalaHtml('Pilih Penerima Iuran')}
  <main class="k-main">
    <p class="k-sub">Dari <b>${esc(SESI.siswa.nama)}</b> · saldo ${rp(SESI.siswa.saldo)}</p>
    <div class="k-pilihan">${daftar.map((p) => `
      <button class="k-pilih pilih-pj" data-pj="${p.id}">
        <span class="k-pilih-avatar">${esc(initials(p.nama))}</span>
        <span class="k-pilih-teks"><b>${esc(p.nama)}</b>
          ${p.label ? `<span class="k-pilih-label">${esc(p.label)}</span>` : ''}</span>
      </button>`).join('')}
    </div>
    <p class="k-sub">Tidak menemukan nama PJ Anda? Sampaikan ke admin cashless agar didaftarkan.</p>
    <button class="k-btn k-btn-kecil" data-aksi="kembali">‹ Batal</button>
  </main>`;
}

function iuranNominalHtml() {
  const p = alur.penerima;
  const maks = alur.maks;
  const cepat = [5000, 10000, 20000, 50000].filter((n) => n >= alur.min && n <= maks);
  const ketik = alur.jumlah || 0;
  return `${kepalaHtml('Berapa yang Dibayar?')}
  <main class="k-main">
    <div class="k-penerima"><span class="k-sub">Uang akan masuk ke kartu</span>
      <b>${esc(p.nama)}</b>${p.label ? `<span class="k-sub">${esc(p.label)}</span>` : ''}</div>
    <div class="k-nominal-grid">${cepat.map((n) => `
      <button class="k-nominal ${ketik === n ? 'sel' : ''}" data-trf-cepat="${n}">${rp(n)}</button>`).join('')}
    </div>
    <div class="k-isian-besar">
      <span class="k-isian-label">Nominal</span>
      <b class="k-isian-nilai">${ketik ? rp(ketik) : '<span class="k-isian-kosong">Rp0</span>'}</b>
    </div>
    ${papanAngkaHtml('lanjut', 'Lanjut', !(ketik >= alur.min && ketik <= maks))}
    <p class="k-sub">Antara ${rp(alur.min)} dan ${rp(maks)} — dibatasi saldo dan sisa jatah hari ini.</p>
    <button class="k-btn k-btn-kecil" data-aksi="iuran">‹ Ganti penerima</button>
  </main>`;
}

function mulaiIuran() {
  const t = SESI.iuran;
  alur = { jenis: 'iuran', penerima: null, min: t.min, maks: t.maks, jumlah: 0, deskripsi: '' };
  layar = 'iur-pilih';
  segarkanMundur();
  render();
}

// ==================================================== tabungan
// Lebih sederhana dari iuran: pilih jenis dari daftar → nominal (keypad,
// bebas) → langsung PIN. Tidak perlu deskripsi wajib — server sudah
// menyebut jenisnya sendiri di keterangan ("Tabungan <jenis>").

function tabPilihHtml() {
  const daftar = SESI.tabungan_jenis || [];
  return `${kepalaHtml('Pilih Jenis Tabungan')}
  <main class="k-main">
    <p class="k-sub">Dari <b>${esc(SESI.siswa.nama)}</b> · saldo ${rp(SESI.siswa.saldo)} ·
      sisa jatah menabung hari ini ${rp(SESI.tabungan.sisa_harian)}</p>
    <div class="k-pilihan">${daftar.map((j) => `
      <button class="k-pilih" data-jenis="${j.id}">
        <b>${esc(j.nama)}</b>
        ${j.deskripsi ? `<span class="k-sub">${esc(j.deskripsi)}</span>` : ''}
        ${j.target ? `<span class="k-sub">target ${rp(j.target)}</span>` : ''}
      </button>`).join('')}
    </div>
    <button class="k-btn k-btn-kecil" data-aksi="kembali">‹ Batal</button>
  </main>`;
}

function tabNominalHtml() {
  const j = alur.jenisPilihan;
  const maks = alur.maks;
  const cepat = [5000, 10000, 20000, 50000].filter((n) => n >= alur.min && n <= maks);
  const ketik = alur.jumlah || 0;
  return `${kepalaHtml('Berapa yang Ditabung?')}
  <main class="k-main">
    <div class="k-penerima"><span class="k-sub">Masuk ke tabungan</span><b>${esc(j.nama)}</b></div>
    <div class="k-nominal-grid">${cepat.map((n) => `
      <button class="k-nominal ${ketik === n ? 'sel' : ''}" data-trf-cepat="${n}">${rp(n)}</button>`).join('')}
    </div>
    <div class="k-isian-besar">
      <span class="k-isian-label">Nominal</span>
      <b class="k-isian-nilai">${ketik ? rp(ketik) : '<span class="k-isian-kosong">Rp0</span>'}</b>
    </div>
    ${papanAngkaHtml('lanjut', 'Lanjut', !(ketik >= alur.min && ketik <= maks))}
    <p class="k-sub">Antara ${rp(alur.min)} dan ${rp(maks)} — dibatasi saldo dan sisa jatah hari ini.</p>
    <button class="k-btn k-btn-kecil" data-aksi="tabungan">‹ Ganti jenis</button>
  </main>`;
}

function mulaiTabungan() {
  const t = SESI.tabungan;
  alur = { jenis: 'tabungan', jenisPilihan: null, min: t.min, maks: t.maks, jumlah: 0 };
  layar = 'tab-pilih';
  segarkanMundur();
  render();
}

function pilihJenisTabungan(id) {
  const j = (SESI.tabungan_jenis || []).find((x) => x.id === Number(id));
  if (!j) return;
  alur.jenisPilihan = j;
  alur.maks = SESI.tabungan.maks;
  alur.jumlah = 0;
  layar = 'tab-nominal';
  segarkanMundur();
  render();
}

function mulaiPinTabungan() {
  alur.judul = 'Masukkan PIN';
  alur.info = `Menabung <b>${rp(alur.jumlah)}</b> ke <b>${esc(alur.jenisPilihan.nama)}</b>.
    Masukkan PIN untuk menyetujui. PIN bawaan adalah NIS Anda.`;
  pin = '';
  layar = 'pin';
  segarkanMundur();
  render();
}

function pilihPJ(id) {
  const p = (SESI.iuran.daftar || []).find((x) => x.id === Number(id));
  if (!p) return;
  alur.penerima = p;
  // Beda dari transfer: penerima sudah diketahui dari daftar (bukan hasil
  // pencarian NIS), jadi tidak perlu bolak-balik ke server untuk menghitung
  // ulang jatah — angka dari bekal() saat tap sudah cukup segar.
  alur.maks = SESI.iuran.maks;
  alur.jumlah = 0;
  layar = 'iur-nominal';
  segarkanMundur();
  render();
}

// --------------------------------------------------- papan ketik di layar
// Kios tidak punya keyboard fisik maupun keyboard OS yang bisa diandalkan
// (monitor sentuh, bukan tablet) — jadi kolom teks bebas di sini (deskripsi
// iuran, catatan transfer) dilayani papan ketik SENDIRI, bukan <input> biasa.
// Satu implementasi dipakai KEDUANYA lewat alur.papanUntuk ('deskripsi' |
// 'catatan') supaya aturannya (wajib/opsional, panjang maksimal) tidak
// bercabang jadi dua kode yang bisa bergeser sendiri-sendiri.
const PAPAN_BARIS = [
  ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'],
  ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'],
  ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l'],
  ['⇧', 'z', 'x', 'c', 'v', 'b', 'n', 'm', '-'],
];

function papanKetikHtml() {
  const wajib = !!alur.papanWajib;
  const nilai = alur.papanNilai || '';
  const cepat = alur.papanCepat || [];
  const besar = !!alur.papanBesar;
  const baris = PAPAN_BARIS.map((row, i) => `<div class="k-kbd-baris">${row.map((k) => {
    if (k === '⇧') return `<button class="k-key k-key-shift ${besar ? 'aktif' : ''}" data-kbd="shift">⇧</button>`;
    const huruf = i >= 1 && i <= 3 ? (besar ? k.toUpperCase() : k) : k;
    return `<button class="k-key" data-kbd="${esc(huruf)}">${esc(huruf)}</button>`;
  }).join('')}</div>`).join('');
  return `${kepalaHtml(alur.papanJudul)}
  <main class="k-main">
    ${alur.papanKeterangan ? `<p class="k-sub" style="margin:0 0 8px">${alur.papanKeterangan}</p>` : ''}
    ${cepat.length ? `<div class="k-chip-baris">${cepat.map((c) => `
      <button class="k-chip ${nilai === c ? 'aktif' : ''}" data-kbd-cepat="${esc(c)}">${esc(c)}</button>`).join('')}
      <button class="k-chip ${alur.papanCustom ? 'aktif' : ''}" data-kbd-custom="1">Lainnya…</button>
    </div>` : ''}
    ${!cepat.length || alur.papanCustom ? `
    <div class="k-teks-field"><label>${esc(alur.papanLabel || 'Deskripsi')}</label>
      <div class="isi ${nilai ? '' : 'kosong'}">${nilai ? esc(nilai) : 'ketik di papan bawah…'}</div></div>
    ${wajib ? '<p class="k-wajib">✱ wajib diisi</p>' : ''}
    <div class="k-kbd">${baris}
      <div class="k-kbd-baris">
        <button class="k-key k-key-lebar" data-kbd="spasi">spasi</button>
        <button class="k-key k-key-lebar" data-kbd="hapus">⌫ hapus</button>
      </div>
    </div>` : ''}
    <div style="display:flex; gap:10px; margin-top:14px">
      ${!wajib ? '<button class="k-btn k-btn-kecil" data-kbd-lewati="1">Lewati</button>' : ''}
      <button class="k-btn k-btn-utama" style="flex:1" data-kbd-selesai="1"
        ${wajib && nilai.trim().length < 3 ? 'disabled' : ''}>${esc(alur.papanTombol || 'Lanjut')}</button>
    </div>
  </main>`;
}

function ketikPapan(tuts) {
  if (tuts === 'shift') { alur.papanBesar = !alur.papanBesar; return render(); }
  if (tuts === 'hapus') { alur.papanNilai = (alur.papanNilai || '').slice(0, -1); return render(); }
  const tambah = tuts === 'spasi' ? ' ' : tuts;
  const nilai = (alur.papanNilai || '') + tambah;
  if (nilai.length > 80) return;
  alur.papanNilai = nilai;
  return render();
}

function selesaiPapanKetik(nilai) {
  const v = (nilai ?? alur.papanNilai ?? '').trim();
  if (alur.papanUntuk === 'deskripsi') { alur.deskripsi = v; mintaPinIuran(); }
  else if (alur.papanUntuk === 'catatan') { alur.catatan = v; mintaPinTransfer(); }
}

function mulaiDeskripsiIuran() {
  alur.papanUntuk = 'deskripsi';
  alur.papanJudul = 'Untuk Apa Iuran Ini?';
  alur.papanKeterangan = `Kirim <b>${rp(alur.jumlah)}</b> ke <b>${esc(alur.penerima.nama)}</b>. ` +
    'PJ memakai catatan ini untuk mencocokkan siapa yang sudah membayar.';
  alur.papanLabel = 'Deskripsi';
  alur.papanTombol = 'Lanjut ke PIN';
  alur.papanWajib = true;
  alur.papanCepat = ['Kas Kelompok', 'Kegiatan Kelas', 'Piknik', 'Study Tour'];
  alur.papanCustom = false;
  alur.papanNilai = alur.deskripsi || '';
  alur.papanBesar = false;
  layar = 'papan-ketik';
  segarkanMundur();
  render();
}

function mulaiCatatanTransfer() {
  alur.papanUntuk = 'catatan';
  alur.papanJudul = 'Tambah Catatan? (opsional)';
  alur.papanKeterangan = `Kirim <b>${rp(alur.jumlah)}</b> ke <b>${esc(alur.penerima.nama)}</b>.`;
  alur.papanLabel = 'Catatan';
  alur.papanTombol = 'Lanjut ke PIN';
  alur.papanWajib = false;
  alur.papanCepat = [];
  alur.papanCustom = true;
  alur.papanNilai = alur.catatan || '';
  alur.papanBesar = false;
  layar = 'papan-ketik';
  segarkanMundur();
  render();
}

function mulaiTransfer() {
  const t = SESI.transfer;
  alur = { jenis: 'transfer', nis: '', min: t.min, maks: t.maks, jumlah: 0, catatan: '' };
  layar = 'trf-nis';
  render();
}

/** Ketikan papan angka pada layar NIS / nominal. */
async function ketikAngka(tuts) {
  if (layar === 'trf-nis') {
    if (tuts === 'hapus') alur.nis = alur.nis.slice(0, -1);
    else if (tuts === 'cari') return cariPenerima();
    else if (alur.nis.length < 20) alur.nis += tuts;
    return render();
  }
  if (layar === 'trf-nominal') {
    if (tuts === 'hapus') alur.jumlah = Math.floor(alur.jumlah / 10);
    else if (tuts === 'kirim') return mulaiCatatanTransfer();
    // Batas digit menjaga angka tetap masuk akal; batas nominal sesungguhnya
    // tetap ditegakkan server, bukan di sini.
    else if (alur.jumlah < 100000000) alur.jumlah = alur.jumlah * 10 + Number(tuts);
    return render();
  }
  if (layar === 'iur-nominal') {
    if (tuts === 'hapus') alur.jumlah = Math.floor(alur.jumlah / 10);
    else if (tuts === 'lanjut') return mulaiDeskripsiIuran();
    else if (alur.jumlah < 100000000) alur.jumlah = alur.jumlah * 10 + Number(tuts);
    return render();
  }
  if (layar === 'tab-nominal') {
    if (tuts === 'hapus') alur.jumlah = Math.floor(alur.jumlah / 10);
    else if (tuts === 'lanjut') return mulaiPinTabungan();
    else if (alur.jumlah < 100000000) alur.jumlah = alur.jumlah * 10 + Number(tuts);
    return render();
  }
}

async function cariPenerima() {
  if (!alur.nis) return;
  try {
    const d = await api('/api/kios/transfer/cari', { token: SESI.token, nis: alur.nis });
    alur.penerima = d.penerima;
    alur.min = d.min;
    alur.maks = d.maks;
    alur.jumlah = 0;
    if (d.maks < d.min) {
      toast(`Sisa jatah kirim hari ini kurang dari ${rp(d.min)}`, 'err');
      return;
    }
    layar = 'trf-nominal';
    segarkanMundur();
    render();
  } catch (e) { tanganiGagal(e, 'trf-nis'); }
}

function mintaPinTransfer() {
  alur.judul = 'Masukkan PIN';
  alur.info = `Kirim <b>${rp(alur.jumlah)}</b> ke <b>${esc(alur.penerima.nama)}</b>
    (${esc(alur.penerima.label_unit)} ${esc(alur.penerima.kelas || '-')}, NIS ${esc(alur.nis)}).
    ${alur.catatan ? `Catatan: <b>${esc(alur.catatan)}</b>. ` : ''}
    Uang yang sudah terkirim tidak bisa ditarik kembali. Masukkan PIN untuk menyetujui.`;
  pin = '';
  layar = 'pin';
  render();
}

function mintaPinIuran() {
  alur.judul = 'Masukkan PIN';
  alur.info = `Bayar iuran <b>${rp(alur.jumlah)}</b> ke <b>${esc(alur.penerima.nama)}</b> —
    "${esc(alur.deskripsi)}". Uang yang sudah terkirim tidak bisa ditarik kembali.
    Masukkan PIN untuk menyetujui.`;
  pin = '';
  layar = 'pin';
  render();
}

function pinHtml() {
  const titik = Array.from({ length: 8 }, (_, i) =>
    `<span class="k-titik ${i < pin.length ? 'isi' : ''}"></span>`).join('');
  const angka = [1, 2, 3, 4, 5, 6, 7, 8, 9].map((n) =>
    `<button class="k-tuts" data-tuts="${n}">${n}</button>`).join('');
  return `${kepalaHtml(alur.judul)}
  <main class="k-main k-main-pin">
    <p class="k-sub k-pin-info">${alur.info}</p>
    <div class="k-titik-baris">${titik}</div>
    <div class="k-keypad">
      ${angka}
      <button class="k-tuts k-tuts-lain" data-tuts="hapus">⌫</button>
      <button class="k-tuts" data-tuts="0">0</button>
      <button class="k-tuts k-tuts-ok" data-tuts="ok">OK</button>
    </div>
    <button class="k-btn k-btn-kecil" data-aksi="batal-pin">‹ Batal</button>
  </main>`;
}

function riwayatHtml() {
  const r = RIW;
  const chip = (nilai, teks) =>
    `<button class="k-chip ${r.periode === nilai && nilai !== 'bulan' ? 'aktif' : ''}" data-periode="${nilai}">${teks}</button>`;

  // Daftar bulan hanya berisi bulan yang benar-benar punya transaksi (dikirim
  // server), jadi tidak ada pilihan yang selalu kosong.
  const opsiBulan = (r.bulan_tersedia || []).map((b) =>
    `<option value="${esc(b)}" ${r.periode === 'bulan' && r.bulan === b ? 'selected' : ''}>${esc(namaBulan(b))}</option>`).join('');
  const pilihBulan = opsiBulan
    ? `<select class="k-chip k-chip-select ${r.periode === 'bulan' ? 'aktif' : ''}" id="k-bulan">
         <option value="">Pilih bulan…</option>${opsiBulan}
       </select>`
    : '';

  const isi = r.memuat && !r.transaksi.length
    ? '<p class="k-sub">Memuat…</p>'
    : (r.transaksi.map((t) => barisTxHtml(t, true)).join('')
       || '<p class="k-sub">Tidak ada transaksi pada periode ini.</p>');

  return `${kepalaHtml('Riwayat Transaksi')}
  <main class="k-main">
    <div class="k-kartu k-kartu-ringkas">
      <div class="k-kartu-id">
        <b>${esc(SESI.siswa.nama)}</b>
        <span class="k-sub">Kelas ${esc(SESI.siswa.kelas || '-')}</span>
      </div>
      <div class="k-kartu-saldo"><span class="k-sub">Saldo saat ini</span><b>${rp(r.saldo)}</b></div>
    </div>

    <div class="k-chip-baris">
      ${chip('hari', 'Hari ini')}${chip('7', '7 hari')}${chip('30', '30 hari')}${pilihBulan}
    </div>

    <div class="k-ringkas">
      <div><span class="k-sub">Masuk</span><b class="${r.total_masuk ? 'plus' : ''}">${r.total_masuk ? '+' : ''}${rp(r.total_masuk)}</b></div>
      <div><span class="k-sub">Keluar</span><b class="${r.total_keluar ? 'minus' : ''}">${r.total_keluar ? '−' : ''}${rp(r.total_keluar)}</b></div>
      <div><span class="k-sub">Transaksi</span><b>${r.total}</b></div>
    </div>

    <div class="k-panel">
      <h2>${esc(judulPeriode(r))}</h2>
      ${isi}
      ${r.ada_lagi ? `<button class="k-btn k-btn-kecil" data-aksi="riwayat-lagi" ${r.memuat ? 'disabled' : ''}>
        ${r.memuat ? 'Memuat…' : 'Muat lebih banyak'}</button>` : ''}
      ${!r.ada_lagi && r.total > r.transaksi.length
        ? '<p class="k-sub">Riwayat lebih lama bisa diminta ke admin kantin.</p>' : ''}
    </div>

    <button class="k-btn k-btn-kecil" data-aksi="kembali">‹ Kembali</button>
    <p class="k-sub k-catatan">Layar menutup sendiri kalau didiamkan — tekan tombol mana saja untuk memperpanjang.</p>
  </main>`;
}

function judulPeriode(r) {
  if (r.periode === 'bulan') return namaBulan(r.bulan);
  if (r.periode === '7') return '7 hari terakhir';
  if (r.periode === '30') return '30 hari terakhir';
  return 'Hari ini';
}

function hasilHtml(h) {
  return `${kepalaHtml(h.judul)}
  <main class="k-main">
    <div class="k-hasil ${h.gagal ? 'gagal' : ''}">
      <div class="k-hasil-ikon">${h.gagal ? IKON.silang : IKON.centang}</div>
      <b>${esc(h.judul)}</b>
      ${h.rincian ? `<p>${h.rincian}</p>` : ''}
      ${h.saldo !== undefined ? `<p class="k-hasil-saldo">Sisa saldo <b>${rp(h.saldo)}</b></p>` : ''}
      <p class="k-sub">Terima kasih. Layar kembali sendiri sebentar lagi.</p>
      <button class="k-btn k-btn-utama" data-aksi="selesai">Selesai</button>
    </div>
  </main>`;
}

// ========================================================== aksi & isian
function pasangAksi() {
  document.querySelectorAll('[data-aksi]').forEach((b) => {
    b.onclick = () => {
      segarkanMundur();
      const a = b.dataset.aksi;
      if (a === 'selesai') selesai(false);
      else if (a === 'idle') keIdle();
      else if (a === 'kembali') kembaliKeSiswa();
      else if (a === 'batal-pin') batalPin();
      else if (a === 'donasi') { layar = 'program'; render(); }
      else if (a === 'transfer') mulaiTransfer();
      else if (a === 'transfer-ulang') { alur.penerima = null; alur.jumlah = 0; layar = 'trf-nis'; render(); }
      else if (a === 'iuran') mulaiIuran();
      else if (a === 'tabungan') mulaiTabungan();
      else if (a === 'program') { layar = 'program'; render(); }
      else if (a === 'ganti-pin') mulaiGantiPin();
      else if (a === 'kunci') nyalakanModeKios();
      else if (a === 'riwayat') mulaiRiwayat();
      else if (a === 'riwayat-lagi') muatRiwayat({ page: RIW.page + 1, tambah: true });
    };
  });
  document.querySelectorAll('[data-periode]').forEach((b) => {
    b.onclick = () => { segarkanMundur(); muatRiwayat({ periode: b.dataset.periode, page: 1 }); };
  });
  const selBulan = $('#k-bulan');
  if (selBulan) selBulan.onchange = () => {
    segarkanMundur();
    if (selBulan.value) muatRiwayat({ periode: 'bulan', bulan: selBulan.value, page: 1 });
  };
  document.querySelectorAll('[data-program]').forEach((b) => {
    b.onclick = () => {
      segarkanMundur();
      alur = { jenis: 'donasi', program: SESI.program.find((p) => p.id === Number(b.dataset.program)) };
      layar = 'nominal';
      render();
    };
  });
  document.querySelectorAll('[data-jumlah]').forEach((b) => {
    b.onclick = () => {
      segarkanMundur();
      alur.jumlah = Number(b.dataset.jumlah);
      alur.judul = 'Masukkan PIN';
      alur.info = `Donasi <b>${esc(alur.program.nama)}</b> sebesar <b>${rp(alur.jumlah)}</b>.
        Masukkan PIN untuk menyetujui. PIN bawaan adalah NIS Anda.`;
      pin = '';
      layar = 'pin';
      render();
    };
  });
  document.querySelectorAll('[data-tuts]').forEach((b) => {
    b.onclick = () => { segarkanMundur(); ketikPin(b.dataset.tuts); };
  });
  document.querySelectorAll('[data-angka]').forEach((b) => {
    b.onclick = () => { segarkanMundur(); ketikAngka(b.dataset.angka); };
  });
  document.querySelectorAll('[data-trf-cepat]').forEach((b) => {
    b.onclick = () => { segarkanMundur(); alur.jumlah = Number(b.dataset.trfCepat); render(); };
  });
  document.querySelectorAll('[data-pj]').forEach((b) => {
    b.onclick = () => { segarkanMundur(); pilihPJ(b.dataset.pj); };
  });
  document.querySelectorAll('[data-jenis]').forEach((b) => {
    b.onclick = () => { segarkanMundur(); pilihJenisTabungan(b.dataset.jenis); };
  });
  document.querySelectorAll('[data-kbd]').forEach((b) => {
    b.onclick = () => { segarkanMundur(); ketikPapan(b.dataset.kbd); };
  });
  document.querySelectorAll('[data-kbd-cepat]').forEach((b) => {
    b.onclick = () => {
      segarkanMundur();
      alur.papanNilai = b.dataset.kbdCepat;
      alur.papanCustom = false;
      render();
    };
  });
  document.querySelectorAll('[data-kbd-custom]').forEach((b) => {
    b.onclick = () => { segarkanMundur(); alur.papanCustom = true; alur.papanNilai = ''; render(); };
  });
  document.querySelectorAll('[data-kbd-lewati]').forEach((b) => {
    b.onclick = () => { segarkanMundur(); selesaiPapanKetik(''); };
  });
  document.querySelectorAll('[data-kbd-selesai]').forEach((b) => {
    b.onclick = () => { segarkanMundur(); selesaiPapanKetik(); };
  });
  if (layar === 'idle') mulaiJam();
}

function mulaiJam() {
  clearInterval(jamTimer);
  const gambar = () => {
    const el = $('#k-jam');
    if (!el) { clearInterval(jamTimer); return; }
    el.textContent = new Date().toLocaleString('id-ID',
      { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
  };
  gambar();
  jamTimer = setInterval(gambar, 10000);
}

function ketikPin(tuts) {
  if (layar !== 'pin') return;
  if (tuts === 'hapus') pin = pin.slice(0, -1);
  else if (tuts === 'ok') return kirimPin();
  else if (pin.length < 8) pin += tuts;
  const titik = document.querySelectorAll('.k-titik');
  titik.forEach((t, i) => t.classList.toggle('isi', i < pin.length));
}

function batalPin() {
  pin = '';
  if (alur?.jenis === 'donasi') { layar = 'nominal'; render(); }
  else if (alur?.jenis === 'transfer' || alur?.jenis === 'iuran') { layar = 'papan-ketik'; render(); }
  else kembaliKeSiswa();
}

// ---- ganti PIN: PIN lama → PIN baru → ulangi PIN baru ----
function mulaiGantiPin() {
  alur = {
    jenis: 'pin', langkah: 'lama',
    judul: 'Ganti PIN — PIN lama',
    info: 'Masukkan PIN Anda yang berlaku sekarang. Kalau belum pernah diganti, PIN Anda adalah NIS.',
  };
  pin = '';
  layar = 'pin';
  render();
}

// ---- riwayat: tap → PIN sekali → boleh ganti periode & buka halaman berikutnya ----
function mulaiRiwayat() {
  // PIN hanya diminta sekali per tap (server mengingatnya di sesi kios), jadi
  // kembali ke riwayat setelah menengok layar lain tidak minta PIN lagi.
  if (RIW) { layar = 'riwayat'; render(); return; }
  alur = {
    jenis: 'riwayat',
    judul: 'Riwayat Transaksi — PIN',
    info: 'Masukkan PIN untuk membuka riwayat transaksi Anda. PIN bawaan adalah NIS Anda.',
  };
  pin = '';
  layar = 'pin';
  render();
}

async function muatRiwayat(o = {}) {
  if (!SESI) return;
  const bukaAwal = !RIW;                       // permintaan pertama masih berdiri di layar PIN
  const periode = o.periode || RIW?.periode || 'hari';
  const bulan = periode === 'bulan' ? (o.bulan ?? RIW?.bulan ?? null) : null;
  const page = o.page || 1;
  const dasar = RIW || { transaksi: [], total: 0, total_masuk: 0, total_keluar: 0, ada_lagi: false, bulan_tersedia: [], saldo: SESI.siswa.saldo };

  if (!bukaAwal) {
    // Ganti periode / muat halaman berikutnya: tunjukkan keadaan memuat dulu,
    // "Muat lebih banyak" tetap memajang baris yang sudah terbaca.
    RIW = { ...dasar, periode, bulan, page, memuat: true, transaksi: o.tambah ? dasar.transaksi : [] };
    layar = 'riwayat';
    render();
  }

  try {
    const d = await api('/api/kios/riwayat', { token: SESI.token, periode, bulan, page, pin: o.pin });
    if (!SESI) return;                          // sudah keburu ditutup / kartu lain ditempel
    RIW = { ...d, memuat: false, transaksi: o.tambah ? [...dasar.transaksi, ...d.transaksi] : d.transaksi };
    layar = 'riwayat';
    segarkanMundur();
    render();
  } catch (e) {
    RIW = bukaAwal ? null : { ...dasar, memuat: false };
    tanganiGagal(e, 'riwayat');
  }
}

async function kirimPin() {
  if (pin.length < 4) { toast('PIN minimal 4 angka', 'err'); return; }
  const isian = pin;
  pin = '';

  if (alur.jenis === 'riwayat') { await muatRiwayat({ pin: isian, periode: 'hari', page: 1 }); return; }

  if (alur.jenis === 'pin') {
    if (alur.langkah === 'lama') {
      alur.pinLama = isian;
      alur.langkah = 'baru';
      alur.judul = 'Ganti PIN — PIN baru';
      alur.info = 'Buat PIN baru 4–8 angka. Jangan pakai angka berurutan, angka sama semua, atau NIS Anda.';
      render();
      return;
    }
    if (alur.langkah === 'baru') {
      alur.pinBaru = isian;
      alur.langkah = 'ulang';
      alur.judul = 'Ganti PIN — ulangi PIN baru';
      alur.info = 'Ketik sekali lagi PIN baru Anda untuk memastikan tidak salah ketik.';
      render();
      return;
    }
    if (isian !== alur.pinBaru) {
      toast('PIN baru tidak sama — ulangi dari awal', 'err');
      mulaiGantiPin();
      return;
    }
    try {
      await api('/api/kios/ganti-pin', { token: SESI.token, pin_lama: alur.pinLama, pin_baru: alur.pinBaru });
      hentikanMundur();
      layar = 'hasil';
      render({ judul: 'PIN berhasil diganti', rincian: 'Pakai PIN baru Anda untuk donasi berikutnya. Jangan beri tahu siapa pun.' });
      SESI = null; RIW = null;
      setTimeout(() => { if (layar === 'hasil') keIdle(); }, 7000);
    } catch (e) { tanganiGagal(e, 'lama'); }
    return;
  }

  // ---- transfer ke teman ----
  if (alur.jenis === 'transfer') {
    try {
      const d = await api('/api/kios/transfer', {
        token: SESI.token, nis: alur.nis, jumlah: alur.jumlah, pin: isian,
        // tx_id yang sama pada kiriman ulang: kios yang koneksinya putus tepat
        // setelah uang berpindah tidak boleh mengirim untuk kedua kalinya.
        tx_id: alur.txId || (alur.txId = 'trf-' + Date.now() + '-' + Math.random().toString(16).slice(2, 8)),
      });
      hentikanMundur();
      layar = 'hasil';
      render({
        judul: d.duplikat ? 'Transfer sudah tercatat' : 'Transfer berhasil',
        rincian: `<b>${rp(d.jumlah)}</b> terkirim ke <b>${esc(d.penerima.nama)}</b>`,
        saldo: d.saldo,
      });
      SESI = null; RIW = null;
      setTimeout(() => { if (layar === 'hasil') keIdle(); }, 7000);
    } catch (e) { tanganiGagal(e, 'trf-nominal'); }
    return;
  }

  // ---- iuran ke PJ ----
  if (alur.jenis === 'iuran') {
    try {
      const d = await api('/api/kios/iuran', {
        token: SESI.token, penerima_id: alur.penerima.id, jumlah: alur.jumlah,
        deskripsi: alur.deskripsi, pin: isian,
        tx_id: alur.txId || (alur.txId = 'iur-' + Date.now() + '-' + Math.random().toString(16).slice(2, 8)),
      });
      hentikanMundur();
      layar = 'hasil';
      render({
        judul: d.duplikat ? 'Iuran sudah tercatat' : 'Iuran berhasil',
        rincian: `<b>${rp(d.jumlah)}</b> terkirim ke <b>${esc(d.penerima.nama)}</b> — "${esc(alur.deskripsi)}"`,
        saldo: d.saldo,
      });
      SESI = null; RIW = null;
      setTimeout(() => { if (layar === 'hasil') keIdle(); }, 7000);
    } catch (e) { tanganiGagal(e, 'iur-nominal'); }
    return;
  }

  // ---- tabungan ----
  if (alur.jenis === 'tabungan') {
    try {
      const d = await api('/api/kios/tabungan', {
        token: SESI.token, jenis_id: alur.jenisPilihan.id, jumlah: alur.jumlah, pin: isian,
        tx_id: alur.txId || (alur.txId = 'tab-' + Date.now() + '-' + Math.random().toString(16).slice(2, 8)),
      });
      hentikanMundur();
      layar = 'hasil';
      render({
        judul: d.duplikat ? 'Tabungan sudah tercatat' : 'Menabung berhasil',
        rincian: `<b>${rp(d.jumlah)}</b> masuk ke <b>${esc(d.jenis)}</b> — saldo tabungan kini <b>${rp(d.saldo_tabungan)}</b>`,
        saldo: d.saldo,
      });
      SESI = null; RIW = null;
      setTimeout(() => { if (layar === 'hasil') keIdle(); }, 7000);
    } catch (e) { tanganiGagal(e, 'tab-nominal'); }
    return;
  }

  // ---- donasi ----
  try {
    const d = await api('/api/kios/donasi', {
      token: SESI.token, program_id: alur.program.id, jumlah: alur.jumlah, pin: isian,
      // tx_id menjaga kiriman ulang (koneksi kios putus lalu tersambung lagi)
      // tidak berubah jadi dua donasi.
      tx_id: alur.txId || (alur.txId = 'kios-' + Date.now() + '-' + Math.random().toString(16).slice(2, 8)),
    });
    hentikanMundur();
    layar = 'hasil';
    render({
      judul: d.duplikat ? 'Donasi sudah tercatat' : 'Donasi berhasil',
      rincian: `<b>${esc(d.program)}</b> sebesar <b>${rp(d.jumlah)}</b>`,
      saldo: d.saldo,
    });
    SESI = null; RIW = null;
    setTimeout(() => { if (layar === 'hasil') keIdle(); }, 7000);
  } catch (e) { tanganiGagal(e, 'nominal'); }
}

/**
 * Gagal karena sesi habis → kembali ke layar awal (santri harus tap lagi).
 * Gagal karena PIN/saldo/batas → tetap di layar, santri boleh mencoba lagi.
 */
function tanganiGagal(e, kembaliKe) {
  toast(e.message, 'err');
  if (e.status === 410 || e.status === 503) { selesai(false); return; }
  if (e.status === 402 || e.status === 429 || e.status === 403 || e.status === 409) {
    hentikanMundur();
    const judul = alur?.jenis === 'riwayat' ? 'Riwayat tidak dapat dibuka'
      : alur?.jenis === 'pin' ? 'PIN tidak dapat diganti'
      : alur?.jenis === 'transfer' ? 'Transfer tidak dapat diproses'
      : alur?.jenis === 'iuran' ? 'Iuran tidak dapat diproses'
      : alur?.jenis === 'tabungan' ? 'Tabungan tidak dapat diproses'
      : 'Donasi tidak dapat diproses';
    layar = 'hasil';
    render({ judul, rincian: esc(e.message), gagal: true });
    setTimeout(() => { if (layar === 'hasil') selesai(false); }, 7000);
    return;
  }
  pin = '';
  if (kembaliKe === 'lama' && alur?.jenis === 'pin') mulaiGantiPin();
  else if (kembaliKe === 'trf-nis' || kembaliKe === 'trf-nominal' || kembaliKe === 'iur-nominal'
    || kembaliKe === 'tab-nominal') {
    layar = kembaliKe; render();
  } else render();
}

// ========================================================== jalan
// Dimuat ulang saat mode kios menyala (listrik kedip, peramban ditutup) →
// layar langsung terkunci, dan hanya sandi staf yang bisa mematikannya.
perbaruiGembok();
keIdle();
setInterval(() => { if (layar === 'idle') keIdle(); }, 60000);   // ikuti perubahan admin
