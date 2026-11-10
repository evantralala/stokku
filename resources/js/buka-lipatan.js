/**
 * Lipatan <details data-lipatan-hash> terbuka sendiri bila alamat halaman menunjuk bagian di dalamnya
 * (mis. Pengaturan#cadangan setelah membuat cadangan), lalu bagian itu digulir ke layar.
 */
export function pasangBukaLipatan() {
    const buka = () => {
        const id = decodeURIComponent(window.location.hash.slice(1));
        const target = id ? document.getElementById(id) : null;
        const lipatan = target?.closest('details[data-lipatan-hash]');
        if (!lipatan) {
            return;
        }
        if (!lipatan.open) {
            lipatan.open = true;
        }
        target.scrollIntoView({ block: 'start' });
    };

    buka();
    window.addEventListener('hashchange', buka);
}
