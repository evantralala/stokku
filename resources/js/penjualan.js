/**
 * Form penjualan harian gaya kasir:
 * - jumlahkan porsi semua menu saat diisi (termasuk lewat tombol −/+);
 * - [data-cari-menu] menyaring kartu menu [data-menu-kartu] langsung; kelompok tanpa menu yang cocok disembunyikan;
 * - [data-perkiraan] memuat ulang perkiraan stok dari server setiap porsi atau tanggal berubah, dan ringkasannya
 *   ditambahkan ke pesan konfirmasi simpan.
 */
const JEDA_PERKIRAAN = 500;

function pasangPerkiraan(form) {
    const wadah = form.querySelector('[data-perkiraan]');

    if (!wadah) {
        return;
    }

    const pesanAwal = form.dataset.konfirmasi ?? '';
    let jeda = null;
    let urutan = 0;

    const pakaiRingkas = () => {
        const ringkas = wadah.querySelector('[data-ringkas]')?.dataset.ringkas ?? '';
        form.dataset.konfirmasi = ringkas ? `${pesanAwal} ${ringkas}` : pesanAwal;
    };

    const muat = async () => {
        const nomor = ++urutan;
        const data = new FormData(form);
        data.delete('_method');
        wadah.setAttribute('aria-busy', 'true');

        try {
            const jawab = await fetch(wadah.dataset.perkiraanUrl, {
                method: 'POST',
                body: data,
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                credentials: 'same-origin',
            });

            if (jawab.ok && nomor === urutan) {
                wadah.innerHTML = await jawab.text();
                pakaiRingkas();
            }
        } catch {
            // Perkiraan hanya bantuan; bila gagal dimuat, form tetap bisa disimpan.
        } finally {
            if (nomor === urutan) {
                wadah.removeAttribute('aria-busy');
            }
        }
    };

    const jadwalkan = () => {
        clearTimeout(jeda);
        jeda = setTimeout(muat, JEDA_PERKIRAAN);
    };

    form.addEventListener('input', (e) => {
        if (e.target.matches('[data-penjualan-porsi]')) {
            jadwalkan();
        }
    });
    form.addEventListener('change', (e) => {
        if (e.target.matches('input[name="tanggal"]')) {
            jadwalkan();
        }
    });

    pakaiRingkas();
}
export function pasangPenjualan(akar = document) {
    akar.querySelectorAll('[data-penjualan]').forEach((form) => {
        const total = form.querySelector('[data-penjualan-total]');
        const cari = form.querySelector('[data-cari-menu]');

        const hitung = () => {
            if (!total) {
                return;
            }
            const jumlah = [...form.querySelectorAll('[data-penjualan-porsi]')]
                .reduce((n, isian) => n + (parseInt(isian.value.replace(/\D/g, ''), 10) || 0), 0);
            total.textContent = jumlah.toLocaleString('id-ID');
        };

        form.addEventListener('input', (e) => {
            if (e.target.matches('[data-penjualan-porsi]')) {
                hitung();
            }
        });

        cari?.addEventListener('input', () => {
            const kata = cari.value.trim().toLowerCase();

            form.querySelectorAll('[data-menu-kelompok]').forEach((kelompok) => {
                let tampil = 0;
                kelompok.querySelectorAll('[data-menu-kartu]').forEach((kartu) => {
                    const cocok = kata === '' || kartu.dataset.cari.includes(kata);
                    kartu.hidden = !cocok;
                    tampil += cocok ? 1 : 0;
                });
                kelompok.hidden = tampil === 0;
            });
        });

        cari?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });

        hitung();
        pasangPerkiraan(form);
    });
}
