/**
 * Konfirmasi sebelum mengirim form, memakai <dialog id="dialog-konfirmasi">.
 *
 * Pemakaian di form, atau di satu tombol kirim bila form punya beberapa tombol:
 *   data-konfirmasi="Pesan penjelasan"          (wajib, mengaktifkan konfirmasi)
 *   data-konfirmasi-judul="Hapus satuan?"     (opsional)
 *   data-konfirmasi-tombol="Ya, hapus"          (opsional)
 *
 * Semua form POST juga dikunci setelah dikirim supaya tidak terkirim dua kali.
 * Skrip lain bisa memakai mintaKonfirmasi({ judul, pesan, tombol }) yang mengembalikan Promise<boolean>.
 */
export function pasangKonfirmasi() {
    const dialog = document.getElementById('dialog-konfirmasi');

    document.addEventListener('submit', (e) => {
        const form = e.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const pengirim = e.submitter ?? null;
        const sumber = pengirim?.dataset.konfirmasi ? pengirim : form;

        if (dialog && sumber.dataset.konfirmasi && !form.dataset.dikonfirmasi) {
            e.preventDefault();
            mintaKonfirmasi({
                judul: sumber.dataset.konfirmasiJudul,
                pesan: sumber.dataset.konfirmasi,
                tombol: sumber.dataset.konfirmasiTombol,
            }).then((setuju) => {
                if (setuju) {
                    form.dataset.dikonfirmasi = '1';
                    kirimUlang(form, pengirim);
                }
            });
            return;
        }

        if (form.method.toLowerCase() === 'post') {
            // Ditunda supaya data form (termasuk nilai tombol yang ditekan) terkumpul lebih dulu.
            setTimeout(() => kunciTombol(form), 0);
        }
    });

    // Halaman yang dibuka lewat tombol kembali harus bisa dipakai lagi.
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) {
            document.querySelectorAll('form [data-terkunci]').forEach((tombol) => {
                tombol.disabled = false;
                tombol.removeAttribute('data-terkunci');
            });
            document.querySelectorAll('form[data-dikonfirmasi]').forEach((form) => {
                delete form.dataset.dikonfirmasi;
            });
        }
    });
}

/** Kirim ulang dengan tombol yang sama supaya nilai tombol (name/value) ikut terkirim. */
function kirimUlang(form, pengirim) {
    if (typeof form.requestSubmit === 'function') {
        if (pengirim) {
            form.requestSubmit(pengirim);
        } else {
            form.requestSubmit();
        }
        return;
    }

    form.submit();
}

/** Tampilkan dialog konfirmasi; hasilnya true bila pengguna memilih tombol ya. */
export function mintaKonfirmasi({ judul, pesan, tombol } = {}) {
    const dialog = document.getElementById('dialog-konfirmasi');

    if (!dialog) {
        return Promise.resolve(window.confirm(pesan || judul || 'Lanjutkan?'));
    }

    dialog.querySelector('[data-dialog-judul]').textContent = judul || 'Lanjutkan?';
    dialog.querySelector('[data-dialog-pesan]').textContent = pesan || '';
    dialog.querySelector('[data-dialog-ya]').textContent = tombol || 'Ya, Lanjutkan';

    dialog.returnValue = '';
    dialog.showModal();

    return new Promise((selesai) => {
        dialog.addEventListener('close', () => selesai(dialog.returnValue === 'ya'), { once: true });
    });
}

function kunciTombol(form) {
    form.querySelectorAll('button[type="submit"], button:not([type])').forEach((tombol) => {
        tombol.disabled = true;
        tombol.setAttribute('data-terkunci', '');
    });
}
