/**
 * Pembaruan otomatis halaman, dalam hitungan detik.
 * Setiap beberapa detik halaman menanyakan penanda perubahan data (<meta name="url-versi">, satu baca cache di server).
 * Bila penandanya berganti, halaman yang sedang dibuka diminta ulang di latar, lalu elemen bertanda [data-segar] (ber-id)
 * yang berubah diganti dengan versi terbaru, tanpa memuat ulang halaman. Bila lama tidak ada perubahan, isi tetap
 * disegarkan sesekali supaya keterangan waktu (mis. batas keputusan) ikut bergerak.
 *
 *   <meta name="jeda-segar" content="3">      jeda menanyakan penanda, dalam detik (dari config/stokku.php)
 *   <meta name="versi-data" content="...">    penanda saat halaman dibuat
 *   data-segar                                 ganti seluruh elemen
 *   data-segar="baris"                         ganti, tetapi pertahankan atribut hidden (saringan di halaman)
 *
 * Dilewati selama tab tidak terlihat, popup/dialog terbuka, pengguna sedang mengisi isian, atau ada form yang diubah.
 * Setelah elemen diganti, event "segar:dimuat" (detail.akar) memberi tahu pemasang lain.
 */
const SEGAR_PAKSA_MS = 60000;

let berubah = false;
let berjalan = false;
let versiDikenal = '';
let terakhirSegar = Date.now();

export function pasangSegar() {
    const jeda = Number(document.querySelector('meta[name="jeda-segar"]')?.content ?? 0) * 1000;
    const urlVersi = document.querySelector('meta[name="url-versi"]')?.content;

    if (!jeda || !urlVersi || !document.querySelector('[data-segar][id]')) {
        return;
    }

    versiDikenal = document.querySelector('meta[name="versi-data"]')?.content ?? '';

    document.addEventListener('input', (e) => {
        if (e.target.closest('form') && !e.target.closest('[data-cari-instan], [data-cari-utama]')) {
            berubah = true;
        }
    });

    const periksa = () => periksaVersi(urlVersi);

    setInterval(periksa, jeda);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            periksa();
        }
    });
}

async function periksaVersi(urlVersi) {
    if (berjalan || sedangSibuk()) {
        return;
    }

    let versi = null;

    try {
        const respons = await fetch(urlVersi, { headers: { Accept: 'application/json' }, cache: 'no-store' });
        versi = respons.ok ? (await respons.json()).versi : null;
    } catch {
        // Jaringan terputus sebentar: coba lagi pada putaran berikutnya.
        return;
    }

    if ((versi && versi !== versiDikenal) || Date.now() - terakhirSegar > SEGAR_PAKSA_MS) {
        if (await segarkan()) {
            versiDikenal = versi ?? versiDikenal;
        }
    }
}

function sedangSibuk() {
    const aktif = document.activeElement;

    return (
        document.hidden ||
        berubah ||
        document.querySelector('dialog[open]') !== null ||
        (aktif && aktif.matches('input, textarea, select') && !aktif.matches('[type="search"]'))
    );
}

/** @returns {Promise<boolean>} true bila isi halaman berhasil dibandingkan dengan versi terbaru */
async function segarkan() {
    if (berjalan || sedangSibuk()) {
        return false;
    }

    berjalan = true;

    try {
        const respons = await fetch(window.location.href, {
            headers: { 'X-Segar': '1', Accept: 'text/html' },
            credentials: 'same-origin',
            cache: 'no-store',
        });

        if (respons.redirected) {
            // Mis. sesi habis: biarkan sampai pengguna berpindah halaman, jangan diulang terus.
            return true;
        }

        if (!respons.ok || sedangSibuk()) {
            return false;
        }

        const baru = new DOMParser().parseFromString(await respons.text(), 'text/html');

        document.querySelectorAll('[data-segar][id]').forEach((lama) => {
            const pengganti = baru.getElementById(lama.id);

            if (!pengganti || pengganti.outerHTML === lama.outerHTML) {
                return;
            }

            const node = document.importNode(pengganti, true);

            if (lama.dataset.segar === 'baris') {
                node.hidden = lama.hidden;
            }

            lama.replaceWith(node);
            document.dispatchEvent(new CustomEvent('segar:dimuat', { detail: { akar: node } }));
        });

        terakhirSegar = Date.now();

        return true;
    } catch {
        // Jaringan terputus sebentar: coba lagi pada putaran berikutnya.
        return false;
    } finally {
        berjalan = false;
    }
}
