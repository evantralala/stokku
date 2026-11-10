/**
 * Kotak "Pilih semua" di dalam form: input[data-centang-semua="nama-isian"] mencentang/melepas semua kotak
 * bernama itu di form yang sama, dan ikut berubah saat kotak-kotaknya dicentang satu per satu.
 * Tanpa JavaScript kotak ini disembunyikan (hidden) dan setiap bahan dicentang manual.
 */
export function pasangCentangSemua(akar = document) {
    akar.querySelectorAll('[data-centang-semua]').forEach((semua) => {
        const form = semua.closest('form');
        const kotak = () => [...form.querySelectorAll(`input[type="checkbox"][name="${semua.dataset.centangSemua}"]`)];
        const bungkus = semua.closest('[data-centang-semua-bungkus]');

        if (bungkus) {
            bungkus.hidden = false;
        }

        const sinkron = () => {
            const daftar = kotak();
            const tercentang = daftar.filter((k) => k.checked).length;
            semua.checked = daftar.length > 0 && tercentang === daftar.length;
            semua.indeterminate = tercentang > 0 && tercentang < daftar.length;
        };

        semua.addEventListener('change', () => {
            kotak().forEach((k) => { k.checked = semua.checked; });
            sinkron();
        });
        form.addEventListener('change', (e) => {
            if (e.target !== semua) {
                sinkron();
            }
        });
        sinkron();
    });
}
