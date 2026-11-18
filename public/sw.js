/*
 * Service worker aplikasi stok (PWA).
 * Halaman dan data selalu diambil dari server supaya angka stok tidak pernah basi; bila koneksi putus,
 * yang tampil adalah halaman offline. Aset hasil build dan gambar disimpan supaya aplikasi cepat terbuka.
 * Notifikasi HP (Web Push): pesan {judul, isi, tautan, ikon} ditampilkan di HP; ketukan membuka tautannya.
 */
const VERSI = 'stokku-v3';
const DASAR = new URL('./', self.location).pathname;
const OFFLINE = DASAR + 'offline.html';
const AWAL = [OFFLINE];

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(VERSI).then((c) => c.addAll(AWAL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys()
            .then((kunci) => Promise.all(kunci.filter((k) => k !== VERSI).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (e) => {
    const req = e.request;
    const url = new URL(req.url);

    if (req.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (req.mode === 'navigate') {
        e.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
        return;
    }

    if (url.pathname.startsWith(DASAR + 'build/') || url.pathname.startsWith(DASAR + 'img/')) {
        e.respondWith(
            caches.match(req).then((ada) => ada || fetch(req).then((res) => {
                if (res.ok) {
                    const salinan = res.clone();
                    caches.open(VERSI).then((c) => c.put(req, salinan));
                }
                return res;
            }))
        );
    }
});

self.addEventListener('push', (e) => {
    let data = {};
    try {
        data = e.data ? e.data.json() : {};
    } catch {
        data = { isi: e.data ? e.data.text() : '' };
    }

    e.waitUntil(self.registration.showNotification(data.judul || 'Notifikasi baru', {
        body: data.isi || '',
        icon: data.ikon || undefined,
        badge: data.ikon || undefined,
        data: { tautan: data.tautan || DASAR },
        tag: data.tautan || undefined,
        renotify: Boolean(data.tautan),
    }));
});

self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    const tujuan = new URL(e.notification.data?.tautan || DASAR, self.location.origin).href;

    e.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((jendela) => {
            const ada = jendela.find((j) => j.url === tujuan) || jendela.find((j) => new URL(j.url).origin === self.location.origin);
            if (ada) {
                return ada.focus().then((j) => (j && j.url !== tujuan && 'navigate' in j ? j.navigate(tujuan) : j));
            }
            return self.clients.openWindow(tujuan);
        })
    );
});
