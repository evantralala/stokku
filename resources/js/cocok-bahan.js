/**
 * Pencocok nama bahan yang toleran: urutan kata bebas ("jepang beras"), singkatan ("brs jpg"),
 * salah ketik kecil ("telor" -> Telur), tanpa spasi ("berasjepang"), dan nama lain bahan (kolom Nama Lain).
 * Dipakai kotak "Ketik nama bahan" (pilih-bahan.js) dan Tempel Daftar (tempel-daftar.js).
 */
export const normal = (teks) => String(teks ?? '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, ' ').trim();

const daftarNama = (bahan) => [bahan.nama, ...String(bahan.lain || '').split(/[,;\n]+/)].map(normal).filter(Boolean);

function jarak(a, b) {
    const baris = Array.from({ length: b.length + 1 }, (_, j) => j);

    for (let i = 1; i <= a.length; i++) {
        let kiri = i;
        let diagonal = i - 1;

        for (let j = 1; j <= b.length; j++) {
            const atas = baris[j];
            const nilai = Math.min(atas + 1, kiri + 1, diagonal + (a[i - 1] === b[j - 1] ? 0 : 1));
            diagonal = atas;
            baris[j] = nilai;
            kiri = nilai;
        }
    }

    return baris[b.length];
}

/** Huruf-huruf kata ada berurutan di kandidat, huruf pertama sama ("brs" -> "beras"). */
const singkatan = (kata, kandidat) => {
    if (kata[0] !== kandidat[0]) {
        return false;
    }

    let i = 0;
    for (const huruf of kandidat) {
        if (huruf === kata[i]) {
            i++;
        }
    }

    return i === kata.length;
};

function skorKata(kata, kandidat) {
    if (kandidat === kata) return 4;
    if (kandidat.startsWith(kata)) return 3;
    if (kata.length >= 3 && kandidat.includes(kata)) return 2.5;
    if (kata.length >= 2 && singkatan(kata, kandidat)) return 2;
    if (kata[0] !== kandidat[0]) return 0;
    if (kata.length >= 4 && Math.abs(kata.length - kandidat.length) <= 2 && jarak(kata, kandidat) <= 1) return 1.5;
    if (kata.length >= 6 && jarak(kata, kandidat) <= 2) return 1;

    return 0;
}

/** Skor kecocokan (0 = tidak cocok). Semua kata yang diketik harus cocok dengan salah satu kata nama bahan. */
export function skorBahan(teks, bahan) {
    const cari = normal(teks);

    if (!cari) {
        return 1;
    }

    let terbaik = 0;
    const kataCari = cari.split(' ');

    for (const nama of daftarNama(bahan)) {
        if (nama === cari) {
            return 100;
        }

        if (nama.includes(cari)) {
            terbaik = Math.max(terbaik, nama.startsWith(cari) ? 60 : 50);
            continue;
        }

        if (cari.length >= 4 && nama.replace(/ /g, '').includes(cari.replace(/ /g, ''))) {
            terbaik = Math.max(terbaik, 40);
            continue;
        }

        const kataNama = nama.split(' ');
        let total = 0;
        let cocok = true;

        for (const kata of kataCari) {
            const skor = Math.max(0, ...kataNama.map((k) => skorKata(kata, k)));
            if (!skor) {
                cocok = false;
                break;
            }
            total += skor;
        }

        if (cocok) {
            terbaik = Math.max(terbaik, (total / kataCari.length) * 9);
        }
    }

    return terbaik;
}

/** Bahan yang cocok, paling cocok di atas. */
export function cariBahan(teks, data, maks = 30) {
    if (!normal(teks)) {
        return data.slice(0, maks);
    }

    return data
        .map((bahan) => [skorBahan(teks, bahan), bahan])
        .filter(([skor]) => skor > 0)
        .sort((a, b) => b[0] - a[0] || a[1].nama.localeCompare(b[1].nama))
        .slice(0, maks)
        .map(([, bahan]) => bahan);
}

/** Bahan yang namanya atau nama lainnya sama persis dengan tulisan, atau null. */
export function bahanPersis(teks, data) {
    const cari = normal(teks);

    return cari ? data.find((bahan) => daftarNama(bahan).includes(cari)) ?? null : null;
}

/** Satu bahan yang paling cocok untuk tulisan bebas (Tempel Daftar), atau null bila tidak yakin. */
export function bahanTerbaik(teks, data) {
    const persis = bahanPersis(teks, data);

    if (persis) {
        return persis;
    }

    const urut = data.map((bahan) => [skorBahan(teks, bahan), bahan]).filter(([skor]) => skor >= 13).sort((a, b) => b[0] - a[0]);

    if (urut.length === 0 || (urut[1] && urut[1][0] === urut[0][0])) {
        return null;
    }

    return urut[0][1];
}
