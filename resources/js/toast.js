/**
 * Toast pesan setelah aksi (komponen x-toast). Toast sukses hilang sendiri, toast galat tetap
 * sampai ditutup. Skrip lain bisa memanggil tampilkanToast(teks, 'sukses' | 'galat').
 */
const LAMA_TAMPIL = 5000;
const LAMA_PUDAR = 300;

export function pasangToast() {
    document.addEventListener('click', (e) => {
        const tombol = e.target.closest('[data-tutup-toast]');

        if (tombol) {
            hapus(tombol.closest('.toast'));
        }
    });

    document.querySelectorAll('[data-toast-wadah] > .toast-sukses').forEach(jadwalkanHilang);
}

export function tampilkanToast(teks, jenis = 'sukses') {
    const wadah = document.querySelector('[data-toast-wadah]');
    const templat = wadah?.querySelector('template[data-toast-templat]');

    if (!templat) {
        return;
    }

    const toast = templat.content.firstElementChild.cloneNode(true);
    toast.classList.add(`toast-${jenis}`);
    toast.setAttribute('role', jenis === 'galat' ? 'alert' : 'status');
    toast.querySelector(`[data-ikon]:not([data-ikon="${jenis}"])`)?.remove();
    toast.querySelector('p').textContent = teks;
    wadah.append(toast);

    if (jenis === 'sukses') {
        jadwalkanHilang(toast);
    }
}

function jadwalkanHilang(toast) {
    setTimeout(() => hapus(toast), LAMA_TAMPIL + LAMA_PUDAR);
}

function hapus(toast) {
    if (!toast?.isConnected) {
        return;
    }

    toast.style.transition = `opacity ${LAMA_PUDAR}ms ease`;
    toast.style.opacity = '0';
    setTimeout(() => toast.remove(), LAMA_PUDAR);
}
