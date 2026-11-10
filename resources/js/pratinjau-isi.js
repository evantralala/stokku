/**
 * Kalimat "Artinya: 1 kg = 1000 gram" di form bahan baku.
 * Wadah: [data-pratinjau-isi], berisi [data-satuan-dasar] atau [data-satuan-dasar-tetap],
 * [data-satuan-beli], [data-isi], dan [data-pratinjau-teks].
 */
export function pasangPratinjauIsi(akar = document) {
    akar.querySelectorAll('[data-pratinjau-isi]').forEach((wadah) => {
        const dasar = wadah.querySelector('select[data-satuan-dasar]');
        const dasarTetap = wadah.querySelector('[data-satuan-dasar-tetap]');
        const beli = wadah.querySelector('select[data-satuan-beli]');
        const isi = wadah.querySelector('[data-isi]');
        const teks = wadah.querySelector('[data-pratinjau-teks]');

        if (!beli || !isi || !teks) {
            return;
        }

        const namaTerpilih = (pilihan) => (pilihan && pilihan.value ? pilihan.selectedOptions[0].textContent.trim() : '');

        const perbarui = () => {
            const namaDasar = dasar ? namaTerpilih(dasar) : (dasarTetap?.dataset.satuanDasarTetap ?? '');
            const namaBeli = namaTerpilih(beli);
            const angka = isi.value.trim();

            teks.textContent = namaDasar && namaBeli && angka ? `Artinya: 1 ${namaBeli} = ${angka} ${namaDasar}` : '';
        };

        [dasar, beli, isi].forEach((el) => {
            el?.addEventListener('input', perbarui);
            el?.addEventListener('change', perbarui);
        });

        perbarui();
    });
}
