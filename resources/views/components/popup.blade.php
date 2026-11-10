{{--
    Satu wadah popup untuk semua tautan ber-atribut data-popup (lihat resources/js/popup.js).
    Isinya diambil dari bagian [data-popup-isi] halaman tujuan; tanpa JavaScript tautan membuka halaman itu biasa.
--}}
<dialog class="popup" id="popup" aria-labelledby="popup-judul" data-ukuran="pendek">
    <div class="popup-kepala">
        <h2 id="popup-judul" data-popup-judul></h2>
        <button type="button" class="popup-tutup" data-popup-tutup aria-label="Tutup">
            <x-ikon nama="silang" width="22" height="22" />
        </button>
    </div>
    <div class="popup-badan" data-popup-badan></div>
</dialog>
