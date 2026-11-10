/**
 * Pilih bahan di halaman Stok, lalu pilih aksinya di bilah bawah: Isi (popup isi stok) atau Kosongkan (Admin Utama).
 *   [data-pilih-isi]          kotak centang per baris (kolom kanan tabel), value = id bahan;
 *                             data-isi / data-kosong = 1 bila bahan itu bisa diisi / dikosongkan, data-perlu = 1 bila habis/menipis
 *   [data-pilih-isi-semua]    kotak centang di kepala tabel: semua baris terlihat di kategori itu
 *   [data-isi-stok-bilah]     bilah bawah (data-label "Minta"/"Isi", data-teks-kosong), berisi [data-isi-stok-teks],
 *                             [data-isi-stok-batal], a[data-isi-stok-tombol] dan a[data-kosong-tombol] (data-dasar = alamat popup)
 * Tanpa centang, tombol Isi membuka semua bahan habis/menipis yang sedang terlihat (saringan di halaman ikut);
 * tombol Kosongkan hanya muncul bila ada bahan dicentang yang stoknya tidak 0.
 * Pilihan disimpan di memori, jadi tetap tercentang setelah baris diganti pembaruan otomatis (segar.js).
 */
const terpilih = new Set();

export function pasangPilihIsiStok() {
    if (!document.querySelector('[data-isi-stok-bilah]')) {
        return;
    }

    document.addEventListener('change', (e) => {
        const kotak = e.target.closest('[data-pilih-isi]');

        if (kotak) {
            catat(kotak, kotak.checked);
            sinkron();
            return;
        }

        const semua = e.target.closest('[data-pilih-isi-semua]');

        if (semua) {
            kotakDi(semua.closest('table'))
                .filter((k) => !k.closest('tr')?.hidden)
                .forEach((k) => catat(k, semua.checked));
            sinkron();
        }
    });

    // Didaftarkan sebelum popup.js, jadi tombol tanpa bahan terlihat tidak membuka popup.
    document.addEventListener('click', (e) => {
        if (e.target.closest(':is([data-isi-stok-tombol], [data-kosong-tombol])[aria-disabled="true"]')) {
            e.preventDefault();
            return;
        }

        if (e.target.closest('[data-isi-stok-batal]')) {
            terpilih.clear();
            sinkron();
        }
    });

    // Saringan cari/chip/kategori mengubah baris yang terlihat; perbarui jumlah "semua".
    document.addEventListener('input', jadwalkan);
    document.addEventListener('click', jadwalkan);
    document.addEventListener('segar:dimuat', sinkron);

    sinkron();
}

function catat(kotak, centang) {
    kotak.checked = centang;

    if (centang) {
        terpilih.add(kotak.value);
    } else {
        terpilih.delete(kotak.value);
    }
}

function kotakDi(akar) {
    return akar ? [...akar.querySelectorAll('[data-pilih-isi]')] : [];
}

let jeda = null;

function jadwalkan() {
    clearTimeout(jeda);
    jeda = setTimeout(sinkron, 0);
}

function sinkron() {
    const semuaKotak = kotakDi(document);
    const ada = new Set(semuaKotak.map((k) => k.value));

    [...terpilih].forEach((id) => {
        if (!ada.has(id)) {
            terpilih.delete(id);
        }
    });

    semuaKotak.forEach((k) => {
        k.checked = terpilih.has(k.value);
    });

    document.querySelectorAll('[data-pilih-isi-semua]').forEach((kepala) => {
        const kotak = kotakDi(kepala.closest('table'));
        const dicentang = kotak.filter((k) => k.checked).length;

        kepala.checked = kotak.length > 0 && dicentang === kotak.length;
        kepala.indeterminate = dicentang > 0 && dicentang < kotak.length;
    });

    const bilah = document.querySelector('[data-isi-stok-bilah]');

    if (!bilah) {
        return;
    }

    const dipilih = semuaKotak.filter((k) => k.checked);
    const jumlah = dipilih.length;

    bilah.querySelector('[data-isi-stok-teks]').textContent = jumlah > 0 ? `${jumlah} bahan dipilih` : bilah.dataset.teksKosong;
    bilah.querySelector('[data-isi-stok-batal]').hidden = jumlah === 0;
    bilah.classList.toggle('ada-pilihan', jumlah > 0);

    const isi = bilah.querySelector('[data-isi-stok-tombol]');

    if (isi) {
        const terlihat = semuaKotak.filter((k) => k.dataset.isi === '1' && k.dataset.perlu === '1'
            && !k.closest('tr')?.hidden && !k.closest('details')?.hidden);
        const ids = jumlah > 0 ? dipilih.filter((k) => k.dataset.isi === '1').map((k) => k.value) : terlihat.map((k) => k.value);
        const label = bilah.dataset.label;

        arahkan(isi, ids);
        bilah.querySelector('[data-isi-stok-label]').textContent = jumlah > 0 ? `${label} Terpilih (${ids.length})` : `${label} Semua (${ids.length})`;
    }

    const kosong = bilah.querySelector('[data-kosong-tombol]');

    if (kosong) {
        const ids = dipilih.filter((k) => k.dataset.kosong === '1').map((k) => k.value);

        arahkan(kosong, ids);
        kosong.hidden = ids.length === 0;
        bilah.querySelector('[data-kosong-label]').textContent = `Kosongkan (${ids.length})`;
    }
}

function arahkan(tombol, ids) {
    const url = new URL(tombol.dataset.dasar, window.location.href);

    ids.forEach((id) => url.searchParams.append('bahan[]', id));
    tombol.href = url.href;
    tombol.classList.toggle('nonaktif', ids.length === 0);
    tombol.setAttribute('aria-disabled', ids.length === 0 ? 'true' : 'false');
}
