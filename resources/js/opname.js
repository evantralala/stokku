/**
 * Halaman hitung opname: cari bahan dalam daftar panjang, dan hitung berapa bahan yang sudah diisi.
 */
export function pasangOpname(akar = document) {
    akar.querySelectorAll('[data-opname]').forEach((form) => {
        const cari = form.querySelector('[data-opname-cari]');
        const sudah = form.querySelector('[data-opname-sudah]');
        const baris = [...form.querySelectorAll('[data-opname-baris]')];
        const judul = [...form.querySelectorAll('[data-opname-judul]')];

        const hitungSudah = () => {
            if (sudah) {
                sudah.textContent = baris.filter((b) => b.querySelector('[data-opname-qty]')?.value.trim() !== '').length;
            }
        };

        cari?.addEventListener('input', () => {
            const kata = cari.value.trim().toLowerCase();

            baris.forEach((b) => {
                b.hidden = kata !== '' && !b.dataset.cari.includes(kata);
            });

            // Judul kategori disembunyikan bila semua bahan di bawahnya tersembunyi.
            judul.forEach((j) => {
                let el = j.nextElementSibling;
                let adaTampil = false;
                while (el && !el.matches('[data-opname-judul]')) {
                    if (el.matches('[data-opname-baris]') && !el.hidden) {
                        adaTampil = true;
                    }
                    el = el.nextElementSibling;
                }
                j.hidden = !adaTampil;
            });
        });

        form.addEventListener('input', (e) => {
            if (e.target.matches('[data-opname-qty]')) {
                hitungSudah();
            }
        });
    });
}
