/**
 * Aplikasi bisa dipasang di HP (PWA).
 * Service worker didaftarkan dari <meta name="service-worker">; hanya jalan di HTTPS atau localhost.
 * Tombol [data-pasang-aplikasi] muncul bila browser menawarkan pemasangan (Chrome/Edge Android & laptop).
 * Petunjuk [data-pasang-ios] muncul di Safari iPhone/iPad, yang memasang lewat Bagikan > Tambah ke Layar Utama.
 * [data-aplikasi-terpasang] muncul bila aplikasi sudah dibuka dari layar utama (bukan data-terpasang: itu penanda skrip yang sudah terpasang).
 */
let tawaran = null;

export function pasangPwa() {
    const alamat = document.querySelector('meta[name="service-worker"]')?.content;

    if (alamat && 'serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.register(alamat, { scope: new URL('./', alamat).pathname }).catch(() => {});
    }

    const terpasang = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    tampilkan('[data-aplikasi-terpasang]', terpasang);
    tampilkan('[data-pasang-ios]', ios && !terpasang);

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        tawaran = e;
        tampilkan('[data-pasang-aplikasi]', true);
    });

    window.addEventListener('appinstalled', () => {
        tawaran = null;
        tampilkan('[data-pasang-aplikasi]', false);
    });

    document.addEventListener('click', async (e) => {
        if (!e.target.closest('[data-pasang-aplikasi]') || !tawaran) {
            return;
        }

        tawaran.prompt();
        await tawaran.userChoice.catch(() => null);
        tawaran = null;
        tampilkan('[data-pasang-aplikasi]', false);
    });
}

function tampilkan(pemilih, ya) {
    document.querySelectorAll(pemilih).forEach((el) => {
        el.hidden = !ya;
    });
}
