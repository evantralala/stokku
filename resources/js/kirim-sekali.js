/**
 * Mencegah form tersimpan dua kali karena tombol Simpan ditekan berulang (mis. HP lambat).
 * Setelah form POST benar-benar terkirim (tidak dibatalkan konfirmasi atau validasi), kiriman berikutnya
 * dari form yang sama diabaikan dan tombolnya tampak sibuk. Dibuka lagi bila halaman ditampilkan ulang
 * lewat tombol Kembali, atau setelah BATAS_MS bila tidak ada jawaban (mis. unduhan yang tidak pindah halaman).
 * Form dengan data-kirim-ulang tidak dibatasi.
 */
const BATAS_MS = 10000;

function dibatasi(form) {
    return form instanceof HTMLFormElement
        && (form.getAttribute('method') || 'get').toLowerCase() === 'post'
        && !form.hasAttribute('data-kirim-ulang');
}

function buka(form) {
    delete form.dataset.terkirim;
    form.removeAttribute('aria-busy');
    form.querySelectorAll('.sedang-kirim').forEach((t) => t.classList.remove('sedang-kirim'));
}

/** Dipasang paling awal: menahan kiriman kedua sebelum pemasang lain bereaksi. */
export function pasangTahanKirimUlang() {
    window.addEventListener('submit', (e) => {
        if (dibatasi(e.target) && e.target.dataset.terkirim === '1') {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    window.addEventListener('pageshow', (e) => {
        if (e.persisted) {
            document.querySelectorAll('form[data-terkirim]').forEach(buka);
        }
    });
}

/** Dipasang paling akhir: saat itu sudah pasti apakah kiriman dibatalkan pemasang lain (konfirmasi, popup). */
export function pasangTandaiTerkirim() {
    document.addEventListener('submit', (e) => {
        const form = e.target;

        if (e.defaultPrevented || !dibatasi(form)) {
            return;
        }

        form.dataset.terkirim = '1';
        form.setAttribute('aria-busy', 'true');
        (e.submitter ?? form.querySelector('[type="submit"]'))?.classList.add('sedang-kirim');
        setTimeout(() => buka(form), BATAS_MS);
    });
}
