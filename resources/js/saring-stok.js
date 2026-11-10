/**
 * Saringan halaman Stok yang langsung bekerja tanpa memuat ulang:
 *   - [data-cari-instan]     ketikan menyaring baris menurut nama bahan;
 *   - [data-saring-lampu]    chip lampu (habis/menipis/aman);
 *   - [data-saring-kategori] rel kategori di laptop.
 *
 * Wadah [data-saring-stok="id-daftar"] membawa saringan dari server (data-server-*). Baris yang sudah
 * disaring server tidak ada di halaman, jadi:
 *   - chip/kategori dipakai langsung hanya bila server belum menyaring jenis itu; selain itu tautan dibuka biasa;
 *   - bila kata cari tidak lagi memuat kata cari dari server, form dikirim supaya server memuat semua baris.
 * Alamat di bilah browser ikut diperbarui sehingga muat ulang/kembali tetap pada saringan yang sama.
 */
const JEDA_KIRIM = 450;

export function pasangSaringStok(akar = document) {
    const wadah = akar.querySelector?.('[data-saring-stok]');
    const daftar = wadah ? document.getElementById(wadah.dataset.saringStok) : null;

    if (!wadah || !daftar || wadah.dataset.terpasang) {
        return;
    }
    wadah.dataset.terpasang = '1';

    const server = {
        lampu: wadah.dataset.serverLampu ?? '',
        status: wadah.dataset.serverStatus ?? '',
        kategori: wadah.dataset.serverKategori ?? '',
        cari: (wadah.dataset.serverCari ?? '').toLowerCase(),
    };

    const form = wadah.querySelector('form');
    const isian = wadah.querySelector('[data-cari-instan]');
    const baris = [...daftar.querySelectorAll('[data-stok-baris]')];
    const kelompok = [...daftar.querySelectorAll('details[data-kategori]')];
    const kosong = daftar.querySelector('[data-stok-kosong]');
    const chipLampu = [...wadah.querySelectorAll('[data-saring-lampu]')];
    const relKategori = [...document.querySelectorAll('[data-saring-kategori]')];

    kelompok.forEach((k) => {
        k.dataset.bukaAwal = k.open ? '1' : '';
    });

    const keadaan = {
        teks: (isian?.value ?? '').trim().toLowerCase(),
        lampu: server.lampu,
        kategori: server.kategori,
    };

    let jedaKirim = null;

    const tandai = (daftarTombol, kunci, nilai) => {
        daftarTombol.forEach((el) => {
            if (el.dataset[kunci] === nilai) {
                el.setAttribute('aria-current', 'true');
            } else {
                el.removeAttribute('aria-current');
            }
        });
    };

    const perbaruiAlamat = () => {
        const url = new URL(window.location.href);
        const atur = (nama, nilai) => (nilai ? url.searchParams.set(nama, nilai) : url.searchParams.delete(nama));

        atur('cari', isian?.value.trim() ?? '');
        atur('lampu', keadaan.lampu);
        atur('kategori', keadaan.kategori);
        window.history.replaceState(window.history.state, '', url);
    };

    const aturTersembunyi = (nama, nilai) => {
        if (!form) {
            return;
        }
        let el = form.querySelector(`input[type="hidden"][name="${nama}"]`);
        if (!nilai) {
            el?.remove();
            return;
        }
        if (!el) {
            el = Object.assign(document.createElement('input'), { type: 'hidden', name: nama });
            form.append(el);
        }
        el.value = nilai;
    };

    const terapkan = () => {
        const adaSaring = keadaan.teks !== '' || keadaan.lampu !== '' || keadaan.kategori !== '';
        let total = 0;

        kelompok.forEach((k) => {
            const kategoriCocok = keadaan.kategori === '' || k.dataset.kategori === keadaan.kategori;
            let tampil = 0;

            k.querySelectorAll('[data-stok-baris]').forEach((b) => {
                const cocok = kategoriCocok
                    && (keadaan.lampu === '' || b.dataset.lampu === keadaan.lampu)
                    && (keadaan.teks === '' || b.dataset.cari.includes(keadaan.teks));
                b.hidden = !cocok;
                tampil += cocok ? 1 : 0;
            });

            k.hidden = tampil === 0;
            k.open = adaSaring ? tampil > 0 : k.dataset.bukaAwal === '1';
            total += tampil;
        });

        if (kosong) {
            kosong.hidden = total > 0;
        }

        tandai(chipLampu, 'saringLampu', server.status !== '' ? null : keadaan.lampu);
        tandai(relKategori, 'saringKategori', keadaan.kategori);
        aturTersembunyi('lampu', keadaan.lampu);
        aturTersembunyi('kategori', keadaan.kategori);
        perbaruiAlamat();
    };

    isian?.addEventListener('input', () => {
        clearTimeout(jedaKirim);
        keadaan.teks = isian.value.trim().toLowerCase();

        if (server.cari !== '' && !keadaan.teks.includes(server.cari)) {
            jedaKirim = setTimeout(() => form?.requestSubmit(), JEDA_KIRIM);
            return;
        }

        terapkan();
    });

    form?.addEventListener('submit', (e) => {
        const perluServer = server.cari !== '' && !keadaan.teks.includes(server.cari);

        if (!perluServer && e.submitter === null && document.activeElement === isian) {
            e.preventDefault();
            isian.blur();
        }
    });

    chipLampu.forEach((chip) => {
        chip.addEventListener('click', (e) => {
            if (server.lampu !== '' || server.status !== '' || e.metaKey || e.ctrlKey || e.shiftKey) {
                return;
            }
            e.preventDefault();
            keadaan.lampu = chip.dataset.saringLampu;
            terapkan();
        });
    });

    relKategori.forEach((item) => {
        item.addEventListener('click', (e) => {
            if (server.kategori !== '' || e.metaKey || e.ctrlKey || e.shiftKey) {
                return;
            }
            e.preventDefault();
            keadaan.kategori = item.dataset.saringKategori;
            terapkan();
            daftar.scrollIntoView({ block: 'start', behavior: 'smooth' });
        });
    });

    if (keadaan.teks !== '' && server.cari === '') {
        terapkan();
    }
}
