/**
 * Pintasan keyboard untuk laptop.
 *   "/"  memfokuskan kotak cari halaman ([data-cari-utama]); bila tidak ada, kotak cari di kepala ([data-cari-global]).
 * Tidak aktif saat sedang mengetik, saat popup/dialog terbuka, atau saat laci menu terbuka.
 */
const sedangMengetik = (el) =>
    el instanceof HTMLElement && (el.isContentEditable || el.matches('input, textarea, select'));

const terlihat = (el) => el.getClientRects().length > 0;

export function pasangPintasan() {
    document.addEventListener('keydown', (e) => {
        if (e.key !== '/' || e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) {
            return;
        }

        if (sedangMengetik(e.target) || document.querySelector('dialog[open]') || document.body.classList.contains('menu-terbuka')) {
            return;
        }

        const kotak = [...document.querySelectorAll('[data-cari-utama], [data-cari-global]')]
            .sort((a, b) => Number(b.hasAttribute('data-cari-utama')) - Number(a.hasAttribute('data-cari-utama')))
            .find(terlihat);

        if (!kotak) {
            return;
        }

        e.preventDefault();
        kotak.focus();
        kotak.select?.();
    });
}
