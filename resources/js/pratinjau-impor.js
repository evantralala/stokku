/**
 * Pratinjau impor Excel: angka di tombol Simpan dihitung ulang saat tebakan "Maksudnya …?" dijawab
 * (baris siap + baris Perlu dicek yang semua tebakannya sudah dijawab), dan tombol "Ya untuk Semua".
 * Tanpa JavaScript jawaban tetap terkirim; angka di tombol hanya tidak ikut berubah.
 */
export function pasangPratinjauImpor() {
    const form = document.querySelector('[data-pratinjau-impor]');
    if (!form) {
        return;
    }

    const jumlah = form.querySelector('[data-jumlah-simpan]');
    const tombol = form.querySelector('[data-tombol-simpan]');
    const siap = Number(form.dataset.siap) || 0;

    const terjawab = (kunci) => form.querySelector(`[data-tebakan="${kunci}"] input:checked`) !== null;

    const hitung = () => {
        const cek = [...form.querySelectorAll('.impor-baris[data-tunggu]')]
            .filter((baris) => baris.dataset.tunggu.split(' ').every(terjawab)).length;
        const total = siap + cek;
        jumlah.textContent = String(total);
        tombol.disabled = total === 0;
        form.querySelectorAll('[data-tebakan]').forEach((kotak) => {
            kotak.classList.toggle('terjawab', terjawab(kotak.dataset.tebakan));
        });
    };

    const semua = form.querySelector('[data-terima-semua]');
    if (semua) {
        semua.hidden = false;
        semua.addEventListener('click', () => {
            form.querySelectorAll('[data-tebakan] input[value="terima"]').forEach((r) => { r.checked = true; });
            hitung();
        });
    }

    form.addEventListener('change', hitung);
    hitung();
}
