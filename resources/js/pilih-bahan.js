/**
 * Isian pilih bahan yang bisa diketik (komponen x-form.pilih-bahan).
 * Daftar bahan dibaca dari <script type="application/json" id="..."> sesuai data-sumber.
 * Setelah dipilih, memicu event "bahan-dipilih" dengan detail {id, nama, satuan}.
 */
import { bahanPersis, cariBahan } from './cocok-bahan';

const MAKS_HASIL = 30;
const sumberData = new WeakMap();

export function pasangPilihBahan(akar = document) {
    akar.querySelectorAll('[data-pilih-bahan]:not([data-siap])').forEach(siapkan);
}

/** Data dibaca per elemen sumber, jadi isi popup yang dimuat ulang (dengan data baru) tidak memakai data lama. */
function bacaSumber(id) {
    const el = document.getElementById(id);

    if (!el) {
        return [];
    }

    if (!sumberData.has(el)) {
        sumberData.set(el, JSON.parse(el.textContent || '[]'));
    }

    return sumberData.get(el);
}

function siapkan(wadah) {
    wadah.dataset.siap = '1';

    const teks = wadah.querySelector('[data-pilih-teks]');
    const nilai = wadah.querySelector('[data-pilih-nilai]');
    const daftar = wadah.querySelector('[role="listbox"]');
    const data = bacaSumber(wadah.dataset.sumber);

    let hasil = [];
    let aktif = -1;

    const buka = () => {
        daftar.hidden = false;
        teks.setAttribute('aria-expanded', 'true');
    };

    const tutup = () => {
        daftar.hidden = true;
        teks.setAttribute('aria-expanded', 'false');
        teks.removeAttribute('aria-activedescendant');
        aktif = -1;
    };

    const tandai = (indeks) => {
        const opsi = daftar.querySelectorAll('[role="option"]');
        opsi.forEach((el, i) => el.setAttribute('aria-selected', String(i === indeks)));
        aktif = indeks;

        if (opsi[indeks]) {
            teks.setAttribute('aria-activedescendant', opsi[indeks].id);
            opsi[indeks].scrollIntoView({ block: 'nearest' });
        }
    };

    const pilih = (bahan) => {
        nilai.value = bahan.id;
        teks.value = bahan.nama;
        teks.classList.remove('galat');
        tutup();
        wadah.dispatchEvent(new CustomEvent('bahan-dipilih', { bubbles: true, detail: bahan }));
    };

    const tampilkan = () => {
        hasil = cariBahan(teks.value, data, MAKS_HASIL);

        daftar.replaceChildren();

        if (hasil.length === 0) {
            const kosong = document.createElement('li');
            kosong.className = 'pilih-kosong';
            kosong.textContent = 'Tidak ada bahan yang cocok.';
            daftar.append(kosong);
        }

        hasil.forEach((bahan, i) => {
            const opsi = document.createElement('li');
            opsi.id = `${daftar.id}-${i}`;
            opsi.setAttribute('role', 'option');
            opsi.setAttribute('aria-selected', 'false');

            const nama = document.createElement('span');
            nama.textContent = bahan.nama;
            const ket = document.createElement('small');
            ket.textContent = bahan.satuan;
            opsi.append(nama, ket);

            // mousedown dicegah supaya isian tidak kehilangan fokus sebelum klik terbaca.
            opsi.addEventListener('mousedown', (e) => e.preventDefault());
            opsi.addEventListener('click', () => pilih(bahan));
            daftar.append(opsi);
        });

        buka();
        aktif = -1;
    };

    teks.addEventListener('input', () => {
        nilai.value = '';
        tampilkan();
    });

    teks.addEventListener('focus', tampilkan);

    teks.addEventListener('blur', () => {
        // Nama yang diketik persis sama dianggap memilih bahan itu.
        if (!nilai.value && teks.value.trim()) {
            const cocok = bahanPersis(teks.value, data);
            if (cocok) {
                pilih(cocok);
            }
        }
        setTimeout(tutup, 120);
    });

    teks.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (daftar.hidden) {
                tampilkan();
            }
            tandai(Math.min(aktif + 1, hasil.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            tandai(Math.max(aktif - 1, 0));
        } else if (e.key === 'Enter' && !daftar.hidden) {
            e.preventDefault();
            const target = hasil[aktif >= 0 ? aktif : 0];
            if (target) {
                pilih(target);
            }
        } else if (e.key === 'Escape' && !daftar.hidden) {
            e.preventDefault();
            tutup();
        }
    });
}
