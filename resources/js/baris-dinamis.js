import { pasangPilihBahan } from './pilih-bahan';
import { pasangIsianJumlah } from './isian-jumlah';

/**
 * Daftar baris yang bisa ditambah/dihapus, mis. bahan di form resep.
 * Wadah [data-baris-dinamis] berisi [data-daftar-baris], <template data-templat-baris>
 * (indeks ditulis "__i__"), tombol [data-tambah-baris], dan tombol [data-hapus-baris] di setiap [data-baris].
 */
export function pasangBarisDinamis(akar = document) {
    akar.querySelectorAll('[data-baris-dinamis]').forEach((wadah) => {
        const daftar = wadah.querySelector('[data-daftar-baris]');
        const templat = wadah.querySelector('template[data-templat-baris]');
        let indeks = Number(wadah.dataset.barisBerikutnya || daftar.children.length);

        const tambah = () => {
            daftar.insertAdjacentHTML('beforeend', templat.innerHTML.replaceAll('__i__', String(indeks++)));
            const baru = daftar.lastElementChild;
            pasangPilihBahan(baru);
            pasangIsianJumlah(baru);
            baru.querySelector('[data-pilih-teks]')?.focus();
        };

        wadah.querySelector('[data-tambah-baris]')?.addEventListener('click', tambah);

        daftar.addEventListener('click', (e) => {
            const tombol = e.target.closest('[data-hapus-baris]');
            if (!tombol) {
                return;
            }

            tombol.closest('[data-baris]')?.remove();

            // Selalu sisakan satu baris kosong supaya form tidak hilang.
            if (daftar.children.length === 0) {
                tambah();
            }
        });

        // Setelah bahan dipilih: tampilkan satuannya dan pindah ke isian takaran.
        wadah.addEventListener('bahan-dipilih', (e) => {
            const baris = e.target.closest('[data-baris]');
            const satuan = baris?.querySelector('.isian-satuan-teks, .isian-jumlah-satuan');
            if (satuan) {
                satuan.textContent = e.detail.satuan;
            }
            baris?.querySelector('[data-jumlah]')?.focus();
        });
    });
}
