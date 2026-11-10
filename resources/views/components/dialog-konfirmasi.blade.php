{{-- Satu dialog untuk semua konfirmasi. Teksnya diisi resources/js/konfirmasi.js dari atribut data-konfirmasi* pada form. --}}
<dialog class="dialog" id="dialog-konfirmasi" aria-labelledby="dialog-judul" aria-describedby="dialog-pesan">
    <form method="dialog">
        <h2 id="dialog-judul" data-dialog-judul>Lanjutkan?</h2>
        <p id="dialog-pesan" data-dialog-pesan></p>

        <div class="dialog-aksi">
            <button type="submit" class="btn" value="batal" autofocus>Batal</button>
            <button type="submit" class="btn btn-merah" value="ya" data-dialog-ya>Ya, Lanjutkan</button>
        </div>
    </form>
</dialog>