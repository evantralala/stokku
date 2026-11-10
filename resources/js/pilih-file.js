/** Kotak pilih file: tampilkan nama file yang dipilih di [data-nama-file] dalam label yang sama. */
export function pasangPilihFile(akar = document) {
    akar.querySelectorAll('.impor-file input[type="file"]').forEach((isian) => {
        if (isian.dataset.terpasang) {
            return;
        }
        isian.dataset.terpasang = '1';

        const teks = isian.closest('.impor-file')?.querySelector('[data-nama-file]');
        const awal = teks?.textContent ?? '';

        isian.addEventListener('change', () => {
            const nama = isian.files?.[0]?.name;
            if (teks) {
                teks.textContent = nama ?? awal;
            }
            isian.closest('.impor-file')?.classList.toggle('terisi', Boolean(nama));
        });
    });
}
