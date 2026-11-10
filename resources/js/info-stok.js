/**
 * Setelah bahan dipilih, tampilkan keterangan stok bahan itu di baris yang sama.
 * Form: [data-form-info-stok]; baris: [data-baris] berisi [data-info-stok].
 * Teks diambil dari data bahan (kunci "info"); kunci "kurang" menandai stok di bawah minimum.
 */
export function pasangInfoStok(akar = document) {
    akar.querySelectorAll('[data-form-info-stok]').forEach((form) => {
        form.addEventListener('bahan-dipilih', (e) => {
            const info = e.target.closest('[data-baris]')?.querySelector('[data-info-stok]');

            if (info) {
                info.textContent = e.detail.info ?? '';
                info.classList.toggle('info-kurang', Boolean(e.detail.kurang));
            }
        });
    });
}
