/**
 * Notifikasi HP (Web Push). Aktif bila halaman punya <meta name="push-kunci"> (kunci VAPID server).
 * - Izin sudah diberikan: perangkat langsung didaftarkan (bawaannya aktif).
 * - Izin belum ditanyakan: ajakan [data-push-ajak] tampil; tombol [data-push-aktifkan] meminta izin lalu mendaftar,
 *   [data-push-nanti] menyembunyikan ajakan selama 7 hari.
 * - [data-push-matikan] berhenti berlangganan di perangkat ini (menu Akun).
 * - [data-push-keadaan="aktif|mati|diblokir|tidak-didukung"] hanya yang sesuai keadaan perangkat yang tampil
 *   (tidak-didukung mis. Safari iPhone yang belum dipasang di Layar Utama).
 */
const KUNCI_NANTI = 'stokku:push-nanti';
const TUNDA_HARI = 7;

export function pasangPush() {
    const kunci = document.querySelector('meta[name="push-kunci"]')?.content;
    const alamat = document.querySelector('meta[name="push-alamat"]')?.content;

    if (!kunci || !alamat) {
        return;
    }

    const didukung = window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    if (!didukung) {
        tampilkanStatus('tidak-didukung');
        return;
    }

    perbarui(kunci, alamat);

    document.addEventListener('click', async (e) => {
        if (e.target.closest('[data-push-aktifkan]')) {
            e.preventDefault();
            const izin = await Notification.requestPermission();
            if (izin === 'granted') {
                await daftar(kunci, alamat);
            }
            perbarui(kunci, alamat);
        } else if (e.target.closest('[data-push-matikan]')) {
            e.preventDefault();
            await berhenti(alamat);
            perbarui(kunci, alamat);
        } else if (e.target.closest('[data-push-nanti]')) {
            e.preventDefault();
            simpanNanti();
            tampilkan('[data-push-ajak]', false);
        }
    });
}

async function perbarui(kunci, alamat) {
    const izin = Notification.permission;
    let langganan = await ambilLangganan();

    if (izin === 'granted' && !langganan) {
        langganan = await daftar(kunci, alamat);
    }

    tampilkan('[data-push-ajak]', izin === 'default' && !sedangDitunda());

    if (izin === 'denied') {
        tampilkanStatus('diblokir');
    } else if (izin === 'granted' && langganan) {
        tampilkanStatus('aktif');
    } else {
        tampilkanStatus('mati');
    }
}

async function ambilLangganan() {
    const reg = await navigator.serviceWorker.ready;

    return reg.pushManager.getSubscription();
}

async function daftar(kunci, alamat) {
    try {
        const reg = await navigator.serviceWorker.ready;
        let langganan = await reg.pushManager.getSubscription();

        if (!langganan) {
            langganan = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keBiner(kunci) });
        }

        await kirim(alamat, 'POST', langganan.toJSON());

        return langganan;
    } catch {
        return null;
    }
}

async function berhenti(alamat) {
    const langganan = await ambilLangganan();

    if (!langganan) {
        return;
    }

    await kirim(alamat, 'DELETE', { endpoint: langganan.endpoint }).catch(() => {});
    await langganan.unsubscribe().catch(() => {});
}

function kirim(alamat, metode, isi) {
    return fetch(alamat, {
        method: metode,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify(isi),
    });
}

function tampilkanStatus(keadaan) {
    document.querySelectorAll('[data-push-keadaan]').forEach((el) => {
        el.hidden = el.dataset.pushKeadaan !== keadaan;
    });
    tampilkan('[data-push-aktifkan]', keadaan === 'mati');
    tampilkan('[data-push-matikan]', keadaan === 'aktif');
}

function tampilkan(pemilih, ya) {
    document.querySelectorAll(pemilih).forEach((el) => {
        el.hidden = !ya;
    });
}

function sedangDitunda() {
    try {
        return Number(localStorage.getItem(KUNCI_NANTI) || 0) > Date.now();
    } catch {
        return false;
    }
}

function simpanNanti() {
    try {
        localStorage.setItem(KUNCI_NANTI, String(Date.now() + TUNDA_HARI * 86400000));
    } catch {
        // Penyimpanan browser tidak tersedia: ajakan muncul lagi di halaman berikutnya.
    }
}

function keBiner(base64url) {
    const isi = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const mentah = atob(isi);

    return Uint8Array.from(mentah, (c) => c.charCodeAt(0));
}
