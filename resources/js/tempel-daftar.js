import { bahanTerbaik } from './cocok-bahan';

/**
 * Tempel Daftar (komponen x-tempel-daftar): tulisan bebas satu bahan per baris, mis. dari WhatsApp
 * ("beras 10 kg", "telor 2x30", "10 kg tepung"), diubah menjadi baris form. Jumlah ditulis apa adanya;
 * server yang menghitungnya ke satuan bahan. Baris yang bahannya tidak dikenali dibiarkan di kotak tempel.
 */
const ANGKA = String.raw`\d[\d.,/½¼¾]*`;
const JUMLAH = String.raw`${ANGKA}(?:\s*[x×*]\s*${ANGKA})*(?:\s*[a-zA-Z]+\.?)?`;

/** "beras 10 kg" / "10 kg beras" / "- Beras: 10kg" -> {nama: "beras", jumlah: "10 kg"} */
export function pecahBaris(baris) {
    const bersih = baris.replace(/^\s*(?:[-*•·]|\d+[.)])\s+/, '').trim();

    if (!bersih) {
        return null;
    }

    const akhir = bersih.match(new RegExp(String.raw`^(.*?\D)[\s:=-]*(${JUMLAH})\s*$`));
    if (akhir && /[a-zA-Z]/.test(akhir[1])) {
        return { nama: akhir[1].replace(/[\s:=-]+$/, '').trim(), jumlah: akhir[2].trim() };
    }

    const awal = bersih.match(new RegExp(String.raw`^(${JUMLAH})\s+(.+)$`));
    if (awal) {
        return { nama: awal[2].trim(), jumlah: awal[1].trim() };
    }

    return { nama: bersih, jumlah: '' };
}

export function pasangTempelDaftar(akar = document) {
    akar.querySelectorAll('[data-tempel-daftar]:not([data-siap])').forEach((wadah) => {
        wadah.dataset.siap = '1';

        const teks = wadah.querySelector('[data-tempel-teks]');
        const hasil = wadah.querySelector('[data-tempel-hasil]');
        const form = wadah.closest('[data-baris-dinamis]');
        const sumber = document.getElementById(wadah.dataset.sumber);

        wadah.querySelector('[data-tempel-isi]')?.addEventListener('click', () => {
            const data = sumber ? JSON.parse(sumber.textContent || '[]') : [];
            const sisa = [];
            let terisi = 0;

            teks.value.split(/\r?\n/).forEach((baris) => {
                const bagian = pecahBaris(baris);
                if (!bagian) {
                    return;
                }

                const bahan = bahanTerbaik(bagian.nama, data);
                if (!bahan) {
                    sisa.push(baris.trim());
                    return;
                }

                isiBaris(form, bahan, bagian.jumlah);
                terisi++;
            });

            teks.value = sisa.join('\n');
            hasil.textContent = [
                terisi ? `${terisi} bahan diisi ke daftar.` : '',
                sisa.length ? `${sisa.length} baris belum dikenali, betulkan nama bahannya lalu tekan Isi lagi.` : '',
            ].filter(Boolean).join(' ');
        });
    });
}

/** Isi baris kosong pertama (atau tambah baris baru) dengan bahan dan jumlahnya. */
function isiBaris(form, bahan, jumlah) {
    const kosong = [...form.querySelectorAll('[data-daftar-baris] [data-baris]')]
        .find((baris) => !baris.querySelector('[data-pilih-nilai]')?.value);

    let baris = kosong;
    if (!baris) {
        form.querySelector('[data-tambah-baris]')?.click();
        baris = form.querySelector('[data-daftar-baris]').lastElementChild;
    }

    const pilih = baris.querySelector('[data-pilih-bahan]');
    pilih.querySelector('[data-pilih-nilai]').value = bahan.id;
    pilih.querySelector('[data-pilih-teks]').value = bahan.nama;
    pilih.dispatchEvent(new CustomEvent('bahan-dipilih', { bubbles: true, detail: bahan }));

    const isian = baris.querySelector('input[name$="[qty]"]');
    if (isian && jumlah) {
        isian.value = jumlah;
        isian.dispatchEvent(new Event('input', { bubbles: true }));
        isian.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
