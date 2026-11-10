/** Form tambah stok langsung: setelah bahan dipilih, tampilkan satuannya lalu fokus ke jumlah. */
export function pasangTambahStok(akar = document) {
    akar.querySelectorAll('[data-form-tambah-stok]').forEach((form) => {
        form.addEventListener('bahan-dipilih', (e) => {
            const satuan = form.querySelector('.isian-jumlah-satuan');
            if (satuan) {
                satuan.textContent = e.detail.satuan ?? '';
            }

            form.querySelector('[data-jumlah]')?.focus();
        });
    });
}
