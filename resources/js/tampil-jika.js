/**
 * Tampilkan elemen hanya bila isian tertentu bernilai tertentu.
 * Contoh: <div data-tampil-jika="role:admin_cabang"> tampil bila isian bernama "role" bernilai admin_cabang.
 * Isian boleh berupa select, input, atau sekelompok radio dengan nama yang sama.
 * Isian di dalam elemen yang disembunyikan ikut dimatikan supaya tidak terkirim.
 */
export function pasangTampilJika(akar = document) {
    akar.querySelectorAll('[data-tampil-jika]').forEach((el) => {
        const pemisah = el.dataset.tampilJika.lastIndexOf(':');
        const nama = el.dataset.tampilJika.slice(0, pemisah);
        const nilai = el.dataset.tampilJika.slice(pemisah + 1);
        const form = el.closest('form');
        const pemicu = form?.elements.namedItem(nama);

        if (!pemicu) {
            return;
        }

        const perbarui = () => {
            const tampil = pemicu.value === nilai;
            el.hidden = !tampil;
            el.querySelectorAll('input, select, textarea').forEach((isian) => {
                isian.disabled = !tampil;
            });
        };

        form.addEventListener('change', (e) => {
            if (e.target.name === nama) {
                perbarui();
            }
        });
        perbarui();
    });
}
