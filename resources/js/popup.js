/**
 * Popup untuk form dan detail. Isinya diambil dari halaman biasa lewat fetch (bagian [data-popup-isi]),
 * jadi tanpa JavaScript tautan tetap membuka halaman itu seperti biasa.
 *
 * Pemakaian:
 *   <a href="…/tambah" data-popup>…</a>              popup pendek, di tengah layar
 *   <a href="…" data-popup="panjang">…</a>           form panjang: hampir selayar penuh di HP
 *   data-popup-judul="…"                             judul popup (bawaan: judul halaman tujuan)
 *   Halaman tujuan menandai bagian isinya dengan atribut data-popup-isi.
 *   data-popup-ukuran="panjang|pendek" di bagian isi menentukan ukuran popup form itu, dari tautan mana pun
 *   dibukanya (tombol Tambah di kepala halaman, tautan di daftar kosong, tautan di form lain).
 *
 * Pengaman:
 *   - tidak tertutup saat klik di luar; tombol tutup, Esc, dan Batal menanyakan "Buang isian?" bila ada perubahan;
 *   - isian disimpan sementara di browser (draf) dan dipulihkan saat popup dibuka lagi;
 *   - setelah simpan, server mengarahkan ulang seperti biasa sehingga halaman dimuat ulang;
 *   - bila validasi gagal, layout memberi <meta name="popup-ulang"> dan popup dibuka lagi berikut pesan error-nya;
 *   - setelah "Simpan & tambah lagi", layout memberi <meta name="popup-buka"> dan popup tambah dibuka lagi dalam keadaan kosong.
 *
 * Skrip yang perlu dipasang pada isi popup mendengarkan event "popup:dimuat" (detail.akar).
 */
import { mintaKonfirmasi } from './konfirmasi';

const LAMA_DRAF = 24 * 60 * 60 * 1000;
const JEDA_SIMPAN = 400;
const AWALAN_DRAF = 'draf:';
const KUNCI_TERKIRIM = 'popup-terkirim';
const NAMA_DIABAIKAN = new Set(['_token', '_method', '_popup']);
const TIPE_DIABAIKAN = new Set(['hidden', 'password', 'file', 'submit', 'button', 'reset', 'image']);

let dialog = null;
let judul = null;
let badan = null;
let aktif = null;
let muatan = 0;
let jedaSimpan = null;

export function pasangPopup() {
    dialog = document.getElementById('popup');

    if (!dialog) {
        return;
    }

    judul = dialog.querySelector('[data-popup-judul]');
    badan = dialog.querySelector('[data-popup-badan]');

    rapikanDraf();

    document.addEventListener('click', (e) => {
        const tautan = e.target.closest('a[data-popup]');

        if (!tautan || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }

        e.preventDefault();
        buka(tautan.href, { pemicu: tautan });
    });

    dialog.addEventListener('cancel', (e) => {
        e.preventDefault();
        mintaTutup();
    });

    dialog.addEventListener('click', (e) => {
        if (e.target.closest('[data-popup-tutup], [data-popup-batal]')) {
            e.preventDefault();
            mintaTutup();
        }
    });

    badan.addEventListener('input', simpanDrafNanti);
    badan.addEventListener('change', simpanDrafNanti);

    // Didaftarkan setelah konfirmasi.js, jadi form yang masih menunggu konfirmasi sudah defaultPrevented.
    document.addEventListener('submit', (e) => {
        if (!aktif || e.defaultPrevented || !badan.contains(e.target)) {
            return;
        }

        clearTimeout(jedaSimpan);
        simpanDraf();
        tulisSesi(KUNCI_TERKIRIM, aktif.kunci ?? '');
    });

    const ulang = document.querySelector('meta[name="popup-ulang"]')?.content;
    const bukaLagi = document.querySelector('meta[name="popup-buka"]')?.content;

    if (ulang) {
        buka(ulang, { ulang: true });
    } else if (bukaLagi) {
        buka(bukaLagi);
    }
}

async function buka(alamat, { pemicu = null, ulang = false } = {}) {
    const url = new URL(alamat, window.location.href);

    if (url.origin !== window.location.origin) {
        window.location.href = url.href;
        return;
    }

    const kunciAlamat = url.pathname + url.search;
    const tautan = pemicu ?? cariTautan(kunciAlamat);
    const judulTautan = tautan?.dataset.popupJudul || null;
    const nomor = ++muatan;

    aktif = null;
    dialog.dataset.ukuran = tautan?.dataset.popup === 'panjang' ? 'panjang' : 'pendek';
    judul.textContent = judulTautan ?? 'Memuat…';
    badan.replaceChildren(teksMemuat());

    dialog.classList.remove('menutup');

    if (!dialog.open) {
        dialog.showModal();
    }

    const isi = await ambilIsi(url);

    if (nomor !== muatan || !dialog.open) {
        return;
    }

    if (!isi) {
        window.location.href = url.href;
        return;
    }

    if (isi.ukuran) {
        dialog.dataset.ukuran = isi.ukuran;
    }

    judul.textContent = judulTautan ?? isi.judul;
    badan.replaceChildren(isi.elemen);
    tandaiForm(kunciAlamat);
    document.dispatchEvent(new CustomEvent('popup:dimuat', { detail: { akar: badan } }));

    const kunci = kunciDraf(kunciAlamat);
    const awal = potret();
    let dipulihkan = false;

    if (ulang) {
        hapusDraf(kunci);
    } else {
        dipulihkan = pulihkanDraf(kunci);
    }

    aktif = { alamat: kunciAlamat, kunci, awal: ulang ? null : awal, pemicu: tautan };

    if (dipulihkan) {
        tampilkanCatatanDraf();
    }

    fokuskan(ulang);
}

async function ambilIsi(url) {
    try {
        const respons = await fetch(url, {
            headers: { 'X-Popup': '1', Accept: 'text/html' },
            credentials: 'same-origin',
        });

        if (!respons.ok || new URL(respons.url).pathname !== url.pathname) {
            return null;
        }

        const halaman = new DOMParser().parseFromString(await respons.text(), 'text/html');
        const bagian = halaman.querySelector('[data-popup-isi]');

        if (!bagian) {
            return null;
        }

        return {
            elemen: document.importNode(bagian, true),
            ukuran: ['panjang', 'pendek'].includes(bagian.dataset.popupUkuran) ? bagian.dataset.popupUkuran : null,
            judul: halaman.querySelector('.bilah-judul h1')?.textContent.trim() ?? '',
        };
    } catch {
        return null;
    }
}

/** Penanda _popup ikut terkirim supaya server bisa membuka popup ini lagi bila validasi gagal. */
function tandaiForm(alamat) {
    formPost().forEach((form) => {
        const penanda = document.createElement('input');
        penanda.type = 'hidden';
        penanda.name = '_popup';
        penanda.value = alamat;
        form.append(penanda);
    });
}

async function mintaTutup() {
    if (aktif && adaPerubahan()) {
        const buang = await mintaKonfirmasi({
            judul: 'Buang isian?',
            pesan: 'Isian yang belum disimpan akan hilang.',
            tombol: 'Ya, buang',
        });

        if (!buang) {
            return;
        }

        hapusDraf(aktif?.kunci);
    }

    tutup();
}

function tutup() {
    const pemicu = aktif?.pemicu;

    clearTimeout(jedaSimpan);
    muatan++;
    aktif = null;

    tungguAnimasiTutup(() => {
        dialog.close();
        badan.replaceChildren();
        pemicu?.focus();
    });
}

/** Popup bergeser keluar dulu (resources/css/transisi.css) sebelum benar-benar ditutup; tanpa animasi langsung tutup. */
function tungguAnimasiTutup(selesai) {
    const nomor = muatan;
    let beres = false;
    const akhiri = () => {
        if (beres) {
            return;
        }

        beres = true;
        dialog.classList.remove('menutup');

        if (nomor === muatan) {
            selesai();
        }
    };

    dialog.classList.add('menutup');

    if (getComputedStyle(dialog).animationName === 'none') {
        akhiri();
        return;
    }

    const padaDialog = (e) => {
        if (e.target === dialog) {
            dialog.removeEventListener('animationend', padaDialog);
            akhiri();
        }
    };

    dialog.addEventListener('animationend', padaDialog);
    setTimeout(() => {
        dialog.removeEventListener('animationend', padaDialog);
        akhiri();
    }, 300);
}

function fokuskan(ulang) {
    const salah = badan.querySelector('.isian.galat, [aria-invalid="true"]');

    if (salah) {
        salah.focus({ preventScroll: true });
        salah.scrollIntoView({ block: 'center' });
        return;
    }

    // Di HP fokus otomatis memunculkan papan ketik; cukup di perangkat bermouse.
    if (!ulang && window.matchMedia('(pointer: fine)').matches) {
        badan.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])')?.focus();
    }
}

function teksMemuat() {
    const el = document.createElement('p');
    el.className = 'popup-memuat';
    el.setAttribute('role', 'status');
    el.textContent = 'Memuat…';

    return el;
}

function tampilkanCatatanDraf() {
    const catatan = document.createElement('div');
    catatan.className = 'popup-draf';
    catatan.setAttribute('role', 'status');

    const teks = document.createElement('span');
    teks.textContent = 'Isian yang belum disimpan dipulihkan.';

    const tombol = document.createElement('button');
    tombol.type = 'button';
    tombol.textContent = 'Mulai dari Awal';
    tombol.addEventListener('click', () => {
        const { alamat, kunci, pemicu } = aktif ?? {};

        if (alamat) {
            hapusDraf(kunci);
            buka(alamat, { pemicu });
        }
    });

    catatan.append(teks, tombol);
    badan.prepend(catatan);
}

function cariTautan(alamat) {
    return [...document.querySelectorAll('a[data-popup]')].find((a) => {
        const url = new URL(a.href, window.location.href);

        return url.pathname + url.search === alamat;
    }) ?? null;
}

/* ---------- isian & draf ---------- */

function formPost() {
    return [...badan.querySelectorAll('form')].filter((form) => form.method.toLowerCase() === 'post');
}

function isianDraf(el) {
    return el.name && !NAMA_DIABAIKAN.has(el.name) && !TIPE_DIABAIKAN.has(el.type);
}

/** Isian form sebagai daftar [nama, nilai, dicentang?] sesuai urutan di halaman. */
function ambilIsian() {
    const hasil = [];

    formPost().forEach((form) => {
        for (const el of form.elements) {
            if (!isianDraf(el) || el.disabled) {
                continue;
            }

            if (el.type === 'checkbox' || el.type === 'radio') {
                if (el.checked) {
                    hasil.push([el.name, el.value, 1]);
                }
            } else if (el.type === 'select-multiple') {
                [...el.selectedOptions].forEach((opsi) => hasil.push([el.name, opsi.value, 1]));
            } else {
                hasil.push([el.name, el.value]);
            }
        }
    });

    return hasil;
}

function potret() {
    return JSON.stringify(ambilIsian());
}

function adaPerubahan() {
    return aktif !== null && (aktif.awal === null || potret() !== aktif.awal);
}

function pulihkanDraf(kunci) {
    const draf = bacaDraf(kunci);

    if (!draf) {
        return false;
    }

    const nilai = new Map();
    const dicentang = new Set();

    draf.isian.forEach(([nama, isi, centang]) => {
        if (centang) {
            dicentang.add(`${nama}\u0000${isi}`);
        } else {
            nilai.set(nama, [...(nilai.get(nama) ?? []), isi]);
        }
    });

    let berubah = false;

    formPost().forEach((form) => {
        for (const el of form.elements) {
            if (!isianDraf(el)) {
                continue;
            }

            let diubah = false;

            if (el.type === 'checkbox' || el.type === 'radio') {
                const centang = dicentang.has(`${el.name}\u0000${el.value}`);
                diubah = el.checked !== centang;
                el.checked = centang;
            } else if (el.type === 'select-multiple') {
                [...el.options].forEach((opsi) => {
                    const pilih = dicentang.has(`${el.name}\u0000${opsi.value}`);
                    diubah = diubah || opsi.selected !== pilih;
                    opsi.selected = pilih;
                });
            } else if (nilai.get(el.name)?.length) {
                const isi = nilai.get(el.name).shift();
                diubah = el.value !== isi;
                el.value = isi;
            }

            if (diubah) {
                berubah = true;
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    });

    return berubah;
}

function simpanDrafNanti() {
    clearTimeout(jedaSimpan);
    jedaSimpan = setTimeout(simpanDraf, JEDA_SIMPAN);
}

function simpanDraf() {
    if (!aktif?.kunci) {
        return;
    }

    try {
        if (adaPerubahan()) {
            localStorage.setItem(aktif.kunci, JSON.stringify({ waktu: Date.now(), isian: ambilIsian() }));
        } else {
            localStorage.removeItem(aktif.kunci);
        }
    } catch {
        // Penyimpanan browser penuh atau dimatikan: popup tetap bisa dipakai tanpa draf.
    }
}

function kunciDraf(alamat) {
    const pengguna = document.querySelector('meta[name="pengguna"]')?.content;

    return pengguna ? `${AWALAN_DRAF}${pengguna}:${alamat}` : null;
}

function bacaDraf(kunci) {
    if (!kunci) {
        return null;
    }

    try {
        const draf = JSON.parse(localStorage.getItem(kunci) ?? 'null');

        if (draf && Array.isArray(draf.isian) && Date.now() - draf.waktu <= LAMA_DRAF) {
            return draf;
        }

        localStorage.removeItem(kunci);
    } catch {
        return null;
    }

    return null;
}

function hapusDraf(kunci) {
    if (!kunci) {
        return;
    }

    try {
        localStorage.removeItem(kunci);
    } catch {
        // Abaikan: penyimpanan browser tidak tersedia.
    }
}

/** Buang draf form yang baru saja terkirim, serta draf yang sudah terlalu lama. */
function rapikanDraf() {
    try {
        const terkirim = sessionStorage.getItem(KUNCI_TERKIRIM);

        if (terkirim !== null) {
            sessionStorage.removeItem(KUNCI_TERKIRIM);
            hapusDraf(terkirim);
        }

        Object.keys(localStorage)
            .filter((kunci) => kunci.startsWith(AWALAN_DRAF))
            .forEach((kunci) => bacaDraf(kunci));
    } catch {
        // Abaikan: penyimpanan browser tidak tersedia.
    }
}

function tulisSesi(kunci, nilai) {
    try {
        sessionStorage.setItem(kunci, nilai);
    } catch {
        // Abaikan: penyimpanan browser tidak tersedia.
    }
}
