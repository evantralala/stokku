/**
 * Isian jumlah −/+ (komponen <x-isian-jumlah>).
 * Wadah [data-isian-jumlah] dengan data-langkah, data-min, data-maks (opsional).
 * Angka dibaca dan ditulis dengan koma desimal; event input & change dikirim supaya skrip lain (draf popup, total) ikut.
 */
const baca = (teks) => {
    const bersih = String(teks).replace(/\s/g, '');
    const standar = bersih.includes(',') ? bersih.replace(/\./g, '').replace(',', '.') : bersih;
    const angka = Number.parseFloat(standar);

    return Number.isFinite(angka) ? angka : 0;
};

const tulis = (angka) => String(Math.round(angka * 1000) / 1000).replace('.', ',');

export function pasangIsianJumlah(akar = document) {
    akar.querySelectorAll('[data-isian-jumlah]').forEach((wadah) => {
        if (wadah.dataset.terpasang) {
            return;
        }
        wadah.dataset.terpasang = '1';

        const isian = wadah.querySelector('input');
        const kurang = wadah.querySelector('[data-kurang]');
        const tambah = wadah.querySelector('[data-tambah]');
        const langkah = baca(wadah.dataset.langkah || '1') || 1;
        const min = wadah.dataset.min === undefined ? 0 : baca(wadah.dataset.min);
        const maks = wadah.dataset.maks === undefined ? null : baca(wadah.dataset.maks);

        const batasi = (n) => Math.min(maks ?? Infinity, Math.max(min, n));

        const aturTombol = () => {
            if (isian.disabled || isian.readOnly) {
                kurang.disabled = true;
                tambah.disabled = true;
                return;
            }
            const n = baca(isian.value);
            kurang.disabled = isian.value.trim() !== '' && n <= min;
            tambah.disabled = maks !== null && n >= maks;
        };

        const ubah = (arah) => {
            const sekarang = isian.value.trim() === '' ? min : baca(isian.value);
            isian.value = tulis(batasi(sekarang + arah * langkah));
            isian.dispatchEvent(new Event('input', { bubbles: true }));
            isian.dispatchEvent(new Event('change', { bubbles: true }));
            aturTombol();
        };

        const ganti = wadah.querySelector('[data-ganti-keyboard]');
        if (ganti) {
            const asal = isian.inputMode || 'decimal';
            ganti.hidden = false;
            ganti.addEventListener('click', () => {
                const huruf = isian.inputMode !== 'text';
                isian.inputMode = huruf ? 'text' : asal;
                ganti.textContent = huruf ? '123' : 'abc';
                isian.blur();
                isian.focus();
            });
        }

        kurang.hidden = false;
        tambah.hidden = false;
        kurang.addEventListener('click', () => ubah(-1));
        tambah.addEventListener('click', () => ubah(1));
        isian.addEventListener('input', aturTombol);
        isian.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                e.preventDefault();
                ubah(e.key === 'ArrowUp' ? 1 : -1);
            }
        });
        aturTombol();
    });
}
