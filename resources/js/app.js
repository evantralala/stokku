import { pasangLaciMenu } from './laci-menu';
import { pasangCiutSidebar } from './sidebar-ciut';
import { pasangKonfirmasi } from './konfirmasi';
import { pasangToast } from './toast';
import { pasangKirimOtomatis } from './kirim-otomatis';
import { pasangPratinjauIsi } from './pratinjau-isi';
import { pasangPilihBahan } from './pilih-bahan';
import { pasangBarisDinamis } from './baris-dinamis';
import { pasangTampilJika } from './tampil-jika';
import { pasangInfoStok } from './info-stok';
import { pasangOpname } from './opname';
import { pasangPenjualan } from './penjualan';
import { pasangPopup } from './popup';
import { pasangPintasan } from './pintasan';
import { pasangSaringStok } from './saring-stok';
import { pasangPilihIsiStok } from './pilih-isi-stok';
import { pasangPilihBanyak } from './pilih-banyak';
import { pasangSaringBantuan } from './saring-bantuan';
import { pasangIsianJumlah } from './isian-jumlah';
import { pasangPilihFile } from './pilih-file';
import { pasangSegar } from './segar';
import { pasangTambahStok } from './tambah-stok';
import { pasangIsianFoto } from './isian-foto';
import { pasangLihatSandi } from './lihat-sandi';
import { pasangPwa } from './pwa';
import { pasangBukaLipatan } from './buka-lipatan';
import { pasangPratinjauImpor } from './pratinjau-impor';
import { pasangCentangSemua } from './centang-semua';
import { pasangTahanKirimUlang, pasangTandaiTerkirim } from './kirim-sekali';
import { pasangTempelDaftar } from './tempel-daftar';

/** Pemasang yang bekerja pada isi tertentu; dipanggil lagi untuk isi popup yang baru dimuat. */
const pemasangIsi = [
    pasangKirimOtomatis,
    pasangPratinjauIsi,
    pasangPilihBahan,
    pasangBarisDinamis,
    pasangTampilJika,
    pasangInfoStok,
    pasangOpname,
    pasangPenjualan,
    pasangIsianJumlah,
    pasangPilihFile,
    pasangTambahStok,
    pasangIsianFoto,
    pasangCentangSemua,
    pasangTempelDaftar,
];

pasangTahanKirimUlang();
pasangLaciMenu();
pasangCiutSidebar();
pasangKonfirmasi();
pasangToast();
pemasangIsi.forEach((pasang) => pasang());
pasangPintasan();
pasangSaringStok();
pasangPilihIsiStok();
pasangPilihBanyak();
pasangSaringBantuan();
pasangLihatSandi();
pasangPwa();
pasangBukaLipatan();
pasangPratinjauImpor();

document.addEventListener('popup:dimuat', (e) => {
    pemasangIsi.forEach((pasang) => pasang(e.detail.akar));
});
document.addEventListener('segar:dimuat', (e) => {
    pemasangIsi.forEach((pasang) => pasang(e.detail.akar));
});

pasangPopup();
pasangSegar();
pasangTandaiTerkirim();

// Arahkan ke isian pertama yang salah setelah validasi gagal.
const isianSalah = document.querySelector('.isian.galat');
if (isianSalah) {
    isianSalah.focus({ preventScroll: true });
    isianSalah.scrollIntoView({ block: 'center' });
}
