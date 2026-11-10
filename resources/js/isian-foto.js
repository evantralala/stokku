/** Isian foto: pratinjau foto yang baru dipilih sebelum disimpan; centang "Hapus foto" meredupkan pratinjau. */
export function pasangIsianFoto(akar = document) {
    akar.querySelectorAll('[data-isian-foto]').forEach((kotak) => {
        if (kotak.dataset.terpasang) {
            return;
        }
        kotak.dataset.terpasang = '1';

        const file = kotak.querySelector('input[type="file"]');
        const pratinjau = kotak.querySelector('[data-foto-pratinjau]');
        const hapus = kotak.querySelector('[data-foto-hapus]');
        const teks = kotak.querySelector('[data-foto-teks]');
        let alamat = null;

        file?.addEventListener('change', () => {
            const pilihan = file.files?.[0];

            if (!pilihan || !pratinjau) {
                return;
            }

            if (alamat) {
                URL.revokeObjectURL(alamat);
            }
            alamat = URL.createObjectURL(pilihan);

            const gambar = document.createElement('img');
            gambar.src = alamat;
            gambar.alt = '';
            pratinjau.className = pratinjau.className.replace(/\bfoto-(kosong|warna-\d)\b/g, '').trim();
            pratinjau.classList.remove('dihapus');
            pratinjau.replaceChildren(gambar);

            if (teks) {
                teks.textContent = 'Ganti Foto';
            }
            if (hapus) {
                hapus.checked = false;
            }
        });

        hapus?.addEventListener('change', () => {
            pratinjau?.classList.toggle('dihapus', hapus.checked);
            if (hapus.checked && file) {
                file.value = '';
            }
        });

        if (hapus?.checked) {
            pratinjau?.classList.add('dihapus');
        }
    });
}
