{{--
    Arah transisi antarhalaman (resources/css/transisi.css): "maju" (isi masuk dari kanan), "mundur" (masuk dari kiri),
    atau "sama" (halaman yang sama dimuat lagi, mis. setelah simpan di popup atau ganti saringan: hanya memudar).
    Mundur bila: tombol kembali browser, pindah ke menu yang letaknya di atas menu sekarang, atau kembali ke halaman induk
    (mis. dari detail dokumen ke daftarnya). Muat ulang tanpa transisi. Transisi yang dibatalkan browser tidak dilaporkan sebagai galat. Harus di <head> supaya terpasang sebelum halaman tampil.
--}}
<script>
    (function () {
        var KUNCI = 'arah-transisi';

        function urutanMenu(alamat) {
            var menu = document.querySelectorAll('.sidebar a.nav-item');
            var cocok = -1;
            var panjang = 0;

            for (var i = 0; i < menu.length; i++) {
                var jalur = new URL(menu[i].href).pathname;

                if ((alamat === jalur || alamat.indexOf(jalur + '/') === 0) && jalur.length > panjang) {
                    cocok = i;
                    panjang = jalur.length;
                }
            }

            return cocok;
        }

        function arah(dari, ke, jenis, mundurRiwayat) {
            if (jenis === 'traverse') {
                return mundurRiwayat ? 'mundur' : 'maju';
            }

            if (ke.pathname === dari.pathname) {
                return 'sama';
            }

            var a = urutanMenu(dari.pathname);
            var b = urutanMenu(ke.pathname);

            if (a !== -1 && b !== -1 && a !== b) {
                return b < a ? 'mundur' : 'maju';
            }

            if (ke.pathname !== dari.pathname && dari.pathname.indexOf(ke.pathname.replace(/\/$/, '') + '/') === 0) {
                return 'mundur';
            }

            return 'maju';
        }

        function diamkan(transisi) {
            [transisi.ready, transisi.finished, transisi.updateCallbackDone].forEach(function (janji) {
                if (janji) { janji.catch(function () {}); }
            });
        }

        window.addEventListener('pageswap', function (e) {
            if (!e.viewTransition) {
                return;
            }

            diamkan(e.viewTransition);

            if (!e.activation) {
                return;
            }

            var ke = new URL(e.activation.entry.url);
            var mundur = e.activation.from && e.activation.entry.index < e.activation.from.index;
            var hasil = arah(new URL(location.href), ke, e.activation.navigationType, mundur);

            try { e.viewTransition.types.add(hasil); } catch (_) {}

            try { sessionStorage.setItem(KUNCI, hasil); } catch (_) {}
        });

        window.addEventListener('pagereveal', function (e) {
            if (!e.viewTransition) {
                return;
            }

            diamkan(e.viewTransition);

            var aktivasi = window.navigation && navigation.activation;

            if (aktivasi && aktivasi.navigationType === 'reload') {
                try { e.viewTransition.skipTransition(); } catch (_) {}
                return;
            }

            var hasil = null;

            try {
                hasil = sessionStorage.getItem(KUNCI);
                sessionStorage.removeItem(KUNCI);
            } catch (_) {}

            if (!hasil && aktivasi && aktivasi.from) {
                hasil = aktivasi.navigationType === 'traverse' && aktivasi.entry.index < aktivasi.from.index ? 'mundur' : 'maju';
            }

            try { e.viewTransition.types.add(hasil || 'maju'); } catch (_) {}
        });
    })();
</script>
