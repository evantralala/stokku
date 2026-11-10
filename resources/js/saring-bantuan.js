/** Saring topik bantuan langsung saat mengetik; topik yang cocok dibuka. */
export function pasangSaringBantuan() {
    const akar = document.querySelector('[data-saring-bantuan]');
    const isian = document.querySelector('[data-cari-bantuan]');

    if (!akar || !isian) {
        return;
    }

    const topik = [...akar.querySelectorAll('details[data-cari]')];
    const kosong = akar.querySelector('[data-bantuan-kosong]');
    const terbukaAwal = new Set(topik.filter((t) => t.open));

    const saring = () => {
        const kata = isian.value.trim().toLowerCase();
        let tampil = 0;

        topik.forEach((t) => {
            const cocok = kata === '' || t.dataset.cari.includes(kata);
            t.hidden = !cocok;
            t.open = kata === '' ? terbukaAwal.has(t) : cocok;
            tampil += cocok ? 1 : 0;
        });

        if (kosong) {
            kosong.hidden = tampil > 0;
        }
    };

    isian.addEventListener('input', saring);
}
