/**
 * Pilihan tanggal di popup Impor Penjualan cabang: alamat tombol Unduh Templat ikut tanggal yang dipilih
 * (?tanggal=), supaya templat berisi tanggal itu. Tanpa JavaScript templat memakai tanggal terpilih awal.
 */
export function pasangTanggalImpor(akar = document) {
    akar.querySelectorAll('[data-pilih-tanggal-impor]').forEach((pilihan) => {
        const tautan = pilihan.parentElement?.querySelector('[data-templat-tanggal]');
        if (!tautan || pilihan.dataset.terpasang) {
            return;
        }
        pilihan.dataset.terpasang = '1';
        pilihan.addEventListener('change', (e) => {
            if (e.target.name !== 'tanggal') {
                return;
            }
            const alamat = new URL(tautan.href, window.location.origin);
            alamat.searchParams.set('tanggal', e.target.value);
            tautan.href = alamat.toString();
        });
    });
}
