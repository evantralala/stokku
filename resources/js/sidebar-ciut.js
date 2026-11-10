/**
 * Sidebar laptop (>= 1024px) bisa diciutkan menjadi ikon.
 * Pilihan disimpan di localStorage dengan kunci dari <html data-kunci-sidebar> (per pengguna),
 * dan dipasang sebelum halaman tergambar oleh skrip kecil di layouts/app.blade.php.
 */
const LAYAR_LEBAR = window.matchMedia('(min-width: 1024px)');
const KELAS = 'sidebar-ciut';

export function pasangCiutSidebar() {
    // Menu panjang (Admin Utama) bisa melebihi tinggi layar: pastikan menu yang sedang dibuka terlihat.
    const aktif = document.querySelector('#sidebar nav .nav-item.on');
    const nav = aktif?.closest('nav');
    if (aktif && nav && LAYAR_LEBAR.matches) {
        const bawah = aktif.offsetTop + aktif.offsetHeight - nav.scrollTop;
        if (aktif.offsetTop < nav.scrollTop || bawah > nav.clientHeight) {
            nav.scrollTop = aktif.offsetTop - (nav.clientHeight - aktif.offsetHeight) / 2;
        }
    }

    const tombol = document.querySelector('[data-ciutkan-sidebar]');
    const sidebar = document.getElementById('sidebar');
    const akar = document.documentElement;
    const kunci = akar.dataset.kunciSidebar;

    if (!tombol || !sidebar || !kunci) {
        return;
    }

    const teks = tombol.querySelector('.nav-teks');

    // Saat ciut, label menu hanya terbaca lewat title (tooltip bawaan browser).
    const aturJudul = (ciut) => {
        sidebar.querySelectorAll('.nav-item').forEach((item) => {
            const label = item.querySelector('.nav-teks')?.textContent.trim();
            if (ciut && LAYAR_LEBAR.matches && label) {
                item.title = label;
            } else {
                item.removeAttribute('title');
            }
        });
    };

    const terapkan = (ciut) => {
        akar.classList.toggle(KELAS, ciut);
        tombol.setAttribute('aria-pressed', String(ciut));
        if (teks) {
            teks.textContent = ciut ? 'Lebarkan Menu' : 'Ciutkan Menu';
        }
        aturJudul(ciut);
    };

    tombol.hidden = false;
    terapkan(akar.classList.contains(KELAS));

    tombol.addEventListener('click', () => {
        const ciut = !akar.classList.contains(KELAS);
        terapkan(ciut);
        try {
            localStorage.setItem(kunci, ciut ? '1' : '0');
        } catch {
            // Penyimpanan browser tidak tersedia: pilihan hanya berlaku di halaman ini.
        }
    });

    LAYAR_LEBAR.addEventListener('change', () => aturJudul(akar.classList.contains(KELAS)));
}
