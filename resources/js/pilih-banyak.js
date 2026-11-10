/**
 * Daftar bercentang dengan bilah bawah (komponen x-pilih.bilah, x-pilih.kotak, x-pilih.semua).
 *   form[data-pilih-banyak]              bilah: tersembunyi sampai ada yang dicentang; berisi [data-pilih-teks] dan [data-pilih-batal]
 *   input[data-pilih-baris][form="id"]   kotak centang baris; ikut terkirim bersama form bilah lewat atribut form
 *   input[data-pilih-semua="id"]         centang/lepas semua baris milik bilah itu
 * Pilihan disimpan di memori, jadi tetap tercentang setelah baris diganti pembaruan otomatis (segar.js).
 */
const pilihan = new Map();

export function pasangPilihBanyak() {
    const bilah = [...document.querySelectorAll('form[data-pilih-banyak]')];

    if (bilah.length === 0) {
        return;
    }

    bilah.forEach((f) => pilihan.set(f.id, new Set()));

    document.addEventListener('change', (e) => {
        const kotak = e.target.closest('input[data-pilih-baris]');

        if (kotak && pilihan.has(kotak.getAttribute('form'))) {
            const terpilih = pilihan.get(kotak.getAttribute('form'));
            kotak.checked ? terpilih.add(kotak.value) : terpilih.delete(kotak.value);
            sinkron();
            return;
        }

        const semua = e.target.closest('input[data-pilih-semua]');

        if (semua && pilihan.has(semua.dataset.pilihSemua)) {
            const terpilih = pilihan.get(semua.dataset.pilihSemua);
            kotakMilik(semua.dataset.pilihSemua).forEach((k) => {
                semua.checked ? terpilih.add(k.value) : terpilih.delete(k.value);
            });
            sinkron();
        }
    });

    document.addEventListener('click', (e) => {
        const batal = e.target.closest('[data-pilih-batal]');

        if (batal) {
            pilihan.get(batal.closest('form')?.id)?.clear();
            sinkron();
        }
    });

    document.addEventListener('segar:dimuat', sinkron);
    sinkron();
}

function kotakMilik(id) {
    return [...document.querySelectorAll(`input[data-pilih-baris][form="${CSS.escape(id)}"]`)];
}

function sinkron() {
    pilihan.forEach((terpilih, id) => {
        const form = document.getElementById(id);

        if (!form) {
            return;
        }

        const kotak = kotakMilik(id);
        const ada = new Set(kotak.map((k) => k.value));
        [...terpilih].filter((v) => !ada.has(v)).forEach((v) => terpilih.delete(v));
        kotak.forEach((k) => {
            k.checked = terpilih.has(k.value);
        });

        const jumlah = terpilih.size;
        form.hidden = jumlah === 0;
        form.querySelector('[data-pilih-teks]').textContent = `${jumlah} dipilih`;

        document.querySelectorAll(`input[data-pilih-semua="${CSS.escape(id)}"]`).forEach((semua) => {
            semua.checked = jumlah > 0 && jumlah === kotak.length;
            semua.indeterminate = jumlah > 0 && jumlah < kotak.length;
        });
    });
}
