/**
 * Tombol mata di isian kata sandi: menampilkan atau menyembunyikan sandi.
 * Tombol tersembunyi tanpa JavaScript; atribut data-lihat-sandi berisi id isian.
 */
export function pasangLihatSandi(akar = document) {
    akar.querySelectorAll('[data-lihat-sandi]').forEach((tombol) => {
        const isian = document.getElementById(tombol.dataset.lihatSandi);
        if (!isian) {
            return;
        }
        tombol.hidden = false;
        tombol.addEventListener('click', () => {
            const tampil = isian.type === 'password';
            isian.type = tampil ? 'text' : 'password';
            tombol.setAttribute('aria-pressed', String(tampil));
            tombol.setAttribute('aria-label', tampil ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
            isian.focus();
        });
    });
}
