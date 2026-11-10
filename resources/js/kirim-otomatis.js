/** Pilihan atau isian (mis. tanggal) dengan atribut data-kirim-otomatis langsung mengirim form-nya saat diganti. */
export function pasangKirimOtomatis(akar = document) {
    akar.querySelectorAll('select[data-kirim-otomatis], input[data-kirim-otomatis]').forEach((pilihan) => {
        pilihan.addEventListener('change', () => pilihan.form?.requestSubmit());
    });
}
