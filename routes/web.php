<?php

use App\Http\Controllers\AdminUtama\BahanBakuController;
use App\Http\Controllers\AdminUtama\PeriodeBukuController;
use App\Http\Controllers\AdminUtama\DashboardController as AdminUtamaDashboardController;
use App\Http\Controllers\AdminUtama\ExcelMasterController;
use App\Http\Controllers\AdminUtama\KirimanController as AdminUtamaKirimanController;
use App\Http\Controllers\AdminUtama\KategoriController;
use App\Http\Controllers\AdminUtama\KategoriMenuController;
use App\Http\Controllers\AdminUtama\KontrolSistemController;
use App\Http\Controllers\AdminUtama\LaporanController;
use App\Http\Controllers\AdminUtama\LokasiController;
use App\Http\Controllers\AdminUtama\PindahGudangPusatController;
use App\Http\Controllers\AdminUtama\PenjualanController as AdminUtamaPenjualanController;
use App\Http\Controllers\AdminUtama\MenuController;
use App\Http\Controllers\AdminUtama\IdentitasUsahaController;
use App\Http\Controllers\AdminUtama\ImporSekaligusController;
use App\Http\Controllers\AdminUtama\PengaturanController;
use App\Http\Controllers\AdminUtama\PersetujuanController;
use App\Http\Controllers\AdminUtama\PersetujuanWajarController;
use App\Http\Controllers\AdminUtama\PenggunaController;
use App\Http\Controllers\AdminUtama\ResepController;
use App\Http\Controllers\AdminUtama\RiwayatController;
use App\Http\Controllers\AdminUtama\SatuanController;
use App\Http\Controllers\AdminUtama\StokController as AdminUtamaStokController;
use App\Http\Controllers\AdminUtama\StokMinimumController;
use App\Http\Controllers\AkunController;
use App\Services\FotoService;
use App\Services\Impor\DaftarExcelMaster;
use App\Http\Controllers\BantuanController;
use App\Http\Controllers\FotoController;
use App\Http\Controllers\IdentitasController;
use App\Services\IdentitasUsahaService;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\NotifikasiController;
use App\Http\Controllers\Cabang\BarangKeluarController as CabangBarangKeluarController;
use App\Http\Controllers\Cabang\DashboardController as CabangDashboardController;
use App\Http\Controllers\Cabang\KirimBarangController;
use App\Http\Controllers\Cabang\OpnameController as CabangOpnameController;
use App\Http\Controllers\Cabang\PenjualanController;
use App\Http\Controllers\Cabang\PenjualanExcelController;
use App\Http\Controllers\Cabang\StokController as CabangStokController;
use App\Http\Controllers\Cabang\PermintaanController;
use App\Http\Controllers\Cabang\PermintaanExcelController;
use App\Http\Controllers\Cabang\TerimaBarangController;
use App\Http\Controllers\Pusat\BarangKeluarController as PusatBarangKeluarController;
use App\Http\Controllers\Pusat\BarangMasukController;
use App\Http\Controllers\Pusat\BarangMasukExcelController;
use App\Http\Controllers\Pusat\DashboardController as PusatDashboardController;
use App\Http\Controllers\Pusat\KirimanController as PusatKirimanController;
use App\Http\Controllers\Pusat\OpnameController as PusatOpnameController;
use App\Http\Controllers\Pusat\StokController as PusatStokController;
use App\Http\Controllers\Pusat\TerimaBarangController as PusatTerimaBarangController;
use App\Support\DaftarKontrol;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check()
        ? redirect()->route(Auth::user()->ruteDashboard())
        : redirect()->route('login');
});

// Tamu (belum login)
// Logo usaha dan manifest aplikasi HP (tanpa login: dipakai juga di halaman masuk)
Route::get('/identitas/{berkas}', [IdentitasController::class, 'berkas'])
    ->whereIn('berkas', array_keys(IdentitasUsahaService::BERKAS))
    ->name('identitas.berkas');
Route::get('/manifest.webmanifest', [IdentitasController::class, 'manifest'])->name('manifest');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');

    Route::get('/lupa-password', function () {
        return redirect()->route('login')
            ->withErrors(['login' => 'Untuk mengganti password, silakan hubungi Admin Utama.']);
    })->name('password.request');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// Akun sendiri (semua peran)
Route::middleware(['auth', 'role:admin_utama,admin_pusat,admin_cabang'])
    ->controller(AkunController::class)
    ->group(function () {
        Route::get('/akun', 'index')->name('akun');
        Route::put('/akun/password', 'ubahPassword')->name('akun.password');
        Route::put('/akun/email', 'simpanEmail')->name('akun.email');
        Route::post('/akun/email/kirim', 'kirimEmail')->middleware('throttle:3,1')->name('akun.email.kirim');
    });

// Notifikasi (semua peran)
Route::middleware(['auth', 'role:admin_utama,admin_pusat,admin_cabang'])
    ->controller(NotifikasiController::class)
    ->prefix('notifikasi')
    ->group(function () {
        Route::get('/', 'index')->name('notifikasi');
        Route::patch('/baca-semua', 'bacaSemua')->name('notifikasi.baca-semua');
        Route::get('/{notifikasi}', 'buka')->whereNumber('notifikasi')->name('notifikasi.buka');
    });

// Bantuan (semua peran)
Route::middleware(['auth', 'role:admin_utama,admin_pusat,admin_cabang'])
    ->get('/bantuan', BantuanController::class)
    ->name('bantuan');

// Foto bahan baku dan menu (semua peran)
Route::middleware(['auth', 'role:admin_utama,admin_pusat,admin_cabang'])
    ->get('/foto/{jenis}/{berkas}', FotoController::class)
    ->whereIn('jenis', array_keys(FotoService::FOLDER))
    ->where('berkas', FotoService::POLA_BERKAS)
    ->name('foto');

// Admin Utama (Admin Pusat hanya menu yang dibagikan di Pengaturan > Hak Akses)
Route::middleware(['auth', 'role:admin_utama,admin_pusat', 'akses'])
    ->prefix('admin-utama')
    ->name('admin-utama.')
    ->group(function () {
        Route::get('/dashboard', AdminUtamaDashboardController::class)->name('dashboard');

        Route::controller(LaporanController::class)
            ->prefix('laporan')
            ->group(function () {
                Route::get('/', 'index')->name('laporan');
                Route::get('/stok', 'stok')->name('laporan.stok');
                Route::get('/penjualan', 'penjualan')->name('laporan.penjualan');
                Route::get('/kategori', 'kategori')->name('laporan.kategori');
                Route::get('/selisih', 'selisih')->name('laporan.selisih');
                Route::get('/rusak', 'rusak')->name('laporan.rusak');
                Route::get('/{jenis}/unduh', 'unduh')
                    ->whereIn('jenis', array_keys(LaporanController::JENIS))
                    ->name('laporan.unduh');
            });

        Route::controller(AdminUtamaPenjualanController::class)
            ->prefix('penjualan')
            ->group(function () {
                Route::get('/', 'index')->name('penjualan');
                Route::post('/perkiraan', 'perkiraan')->name('penjualan.perkiraan');
                Route::get('/{penjualan}', 'show')->whereNumber('penjualan')->name('penjualan.lihat');
                Route::get('/{penjualan}/ubah', 'edit')->whereNumber('penjualan')->name('penjualan.ubah');
                Route::put('/{penjualan}', 'update')->whereNumber('penjualan')->name('penjualan.perbarui');
            });

        Route::controller(AdminUtamaStokController::class)->group(function () {
            Route::get('/stok', 'index')->name('stok');
            Route::get('/stok/kosongkan', 'kosongkan')->name('stok.kosongkan');
            Route::post('/stok/kosongkan', 'simpanKosongkan')->name('stok.kosongkan.simpan');
            Route::get('/stok/{bahanBaku}', 'riwayat')->whereNumber('bahanBaku')->name('stok.riwayat');
            Route::get('/stok/{bahanBaku}/intip', 'intip')->whereNumber('bahanBaku')->name('stok.intip');
            Route::get('/stok/riwayat', 'mutasi')->name('stok.mutasi');
            Route::get('/stok/riwayat/unduh', 'unduhMutasi')->name('stok.mutasi.unduh');
            Route::get('/stok/unduh', 'unduh')->name('stok.unduh');
            Route::get('/stok/tambah', 'tambah')->name('stok.tambah');
            Route::post('/stok/tambah', 'simpanTambah')->name('stok.tambah.simpan');
            Route::get('/stok/isi', 'isi')->name('stok.isi');
            Route::post('/stok/isi', 'simpanIsi')->name('stok.isi.simpan');
            Route::get('/stok/{bahanBaku}/ubah', 'ubah')->whereNumber('bahanBaku')->name('stok.ubah');
            Route::post('/stok/{bahanBaku}/ubah', 'simpanUbah')->whereNumber('bahanBaku')->name('stok.ubah.simpan');
            Route::get('/stok/excel', 'excel')->name('stok.excel');
            Route::get('/stok/excel/unduh', 'unduhExcel')->name('stok.excel.unduh');
            Route::post('/stok/excel/pratinjau', 'pratinjauExcel')->name('stok.excel.pratinjau');
            Route::post('/stok/excel', 'simpanExcel')->name('stok.excel.simpan');
            Route::get('/stok/lembar-opname', 'lembarOpname')->name('stok.lembar-opname');
            Route::get('/stok/lembar-opname/unduh', 'unduhLembarOpname')->name('stok.lembar-opname.unduh');
        });

        Route::controller(PersetujuanController::class)
            ->prefix('persetujuan')
            ->group(function () {
                Route::get('/', 'index')->name('persetujuan');
                Route::post('/wajar', [PersetujuanWajarController::class, 'setujui'])->name('persetujuan.wajar.setujui');
                Route::get('/permintaan/{permintaan}', 'permintaan')->whereNumber('permintaan')->name('persetujuan.permintaan');
                Route::post('/permintaan/{permintaan}', 'putuskanPermintaan')->whereNumber('permintaan')->name('persetujuan.permintaan.putuskan');
                Route::get('/barang-masuk/{barangMasuk}', 'barangMasuk')->whereNumber('barangMasuk')->name('persetujuan.barang-masuk');
                Route::get('/barang-masuk/{barangMasuk}/pdf', 'pdfBarangMasuk')->whereNumber('barangMasuk')->name('persetujuan.barang-masuk.pdf');
                Route::post('/barang-masuk/{barangMasuk}', 'putuskanBarangMasuk')->whereNumber('barangMasuk')->name('persetujuan.barang-masuk.putuskan');
                Route::get('/barang-keluar/{barangKeluar}', 'barangKeluar')->whereNumber('barangKeluar')->name('persetujuan.barang-keluar');
                Route::get('/barang-keluar/{barangKeluar}/pdf', 'pdfBarangKeluar')->whereNumber('barangKeluar')->name('persetujuan.barang-keluar.pdf');
                Route::post('/barang-keluar/{barangKeluar}', 'putuskanBarangKeluar')->whereNumber('barangKeluar')->name('persetujuan.barang-keluar.putuskan');
                Route::get('/opname/{opname}', 'opname')->whereNumber('opname')->name('persetujuan.opname');
                Route::get('/opname/{opname}/pdf', 'pdfOpname')->whereNumber('opname')->name('persetujuan.opname.pdf');
                Route::get('/opname/{opname}/excel', 'excelOpname')->whereNumber('opname')->name('persetujuan.opname.excel');
                Route::post('/opname/{opname}', 'putuskanOpname')->whereNumber('opname')->name('persetujuan.opname.putuskan');
            });

        Route::controller(AdminUtamaKirimanController::class)
            ->prefix('kiriman')
            ->group(function () {
                Route::get('/', 'index')->name('kiriman');
                Route::get('/unduh', 'unduh')->name('kiriman.unduh');
                Route::post('/banyak', 'simpanKirimBanyak')->name('kiriman.banyak.simpan');
                Route::get('/permintaan/{permintaan}', 'create')->whereNumber('permintaan')->name('kiriman.buat');
                Route::post('/permintaan/{permintaan}', 'store')->whereNumber('permintaan')->name('kiriman.simpan');
                Route::get('/{kiriman}', 'show')->whereNumber('kiriman')->name('kiriman.lihat');
                Route::get('/permintaan/{permintaan}/pdf', 'pdfPermintaan')->whereNumber('permintaan')->name('kiriman.permintaan-pdf');
                Route::get('/siap-kirim/pdf', 'pdfSiapKirim')->name('kiriman.siap-kirim-pdf');
                Route::get('/{kiriman}/surat-jalan', 'suratJalan')->whereNumber('kiriman')->name('kiriman.surat-jalan');
            });

        // Impor & ekspor Excel data master: /{master}/impor, /{master}/ekspor
        Route::controller(ExcelMasterController::class)
            ->prefix('{master}')
            ->whereIn('master', array_keys(DaftarExcelMaster::KELAS))
            ->group(function () {
                Route::get('/impor', 'unggah')->name('excel.unggah');
                Route::get('/impor/templat', 'templat')->name('excel.templat');
                Route::post('/impor/pratinjau', 'pratinjau')->name('excel.pratinjau');
                Route::post('/impor', 'simpan')->name('excel.simpan');
                Route::get('/ekspor', 'ekspor')->name('excel.ekspor');
            });

        Route::controller(ImporSekaligusController::class)
            ->prefix('impor-sekaligus')
            ->group(function () {
                Route::get('/', 'unggah')->name('impor-sekaligus');
                Route::get('/templat', 'templat')->name('impor-sekaligus.templat');
                Route::post('/cek', 'pratinjau')->name('impor-sekaligus.pratinjau');
                Route::post('/simpan', 'simpan')->name('impor-sekaligus.simpan');
            });

        // Data master (Route::dataMaster didefinisikan di AppServiceProvider)
        Route::dataMaster('lokasi', LokasiController::class);
        Route::get('/lokasi/pindah-pusat', [PindahGudangPusatController::class, 'form'])->name('lokasi.pindah-pusat');
        Route::post('/lokasi/pindah-pusat', [PindahGudangPusatController::class, 'simpan'])->name('lokasi.pindah-pusat.simpan');
        Route::dataMaster('kategori', KategoriController::class);
        Route::dataMaster('satuan', SatuanController::class);
        Route::dataMaster('bahan-baku', BahanBakuController::class);
        Route::dataMaster('kategori-menu', KategoriMenuController::class);
        Route::dataMaster('menu', MenuController::class);
        Route::dataMaster('pengguna', PenggunaController::class);
        Route::patch('/pengguna/{pengguna}/password', [PenggunaController::class, 'aturUlangPassword'])
            ->whereNumber('pengguna')
            ->name('pengguna.password');

        Route::get('/pengaturan', [PengaturanController::class, 'index'])->name('pengaturan');
        Route::get('/riwayat', RiwayatController::class)->name('riwayat');
        Route::put('/pengaturan', [PengaturanController::class, 'simpan'])->name('pengaturan.simpan');
        Route::put('/pengaturan/identitas', IdentitasUsahaController::class)->name('pengaturan.identitas');

        Route::controller(KontrolSistemController::class)
            ->prefix('kontrol-sistem')
            ->group(function () {
                Route::get('/', 'index')->name('kontrol-sistem');
                Route::patch('/akun/{pengguna}', 'ubahAkun')->whereNumber('pengguna')->name('kontrol-sistem.akun');
                Route::patch('/modul/{modul}', 'ubahModul')
                    ->whereIn('modul', array_keys(DaftarKontrol::MODUL))
                    ->name('kontrol-sistem.modul');
                Route::get('/akses/{peran}', 'formAkses')
                    ->whereIn('peran', array_keys(DaftarKontrol::PERAN))
                    ->name('kontrol-sistem.akses');
                Route::put('/akses/{peran}', 'simpanAkses')
                    ->whereIn('peran', array_keys(DaftarKontrol::PERAN))
                    ->name('kontrol-sistem.akses.simpan');
                Route::get('/periode-buku', [PeriodeBukuController::class, 'form'])->name('kontrol-sistem.periode');
                Route::post('/periode-buku', [PeriodeBukuController::class, 'simpan'])->name('kontrol-sistem.periode.simpan');
                Route::get('/periode-buku/{periode}/{jenis}', [PeriodeBukuController::class, 'unduh'])
                    ->whereNumber('periode')
                    ->whereIn('jenis', ['excel', 'cadangan'])
                    ->name('kontrol-sistem.periode.unduh');
            });


        Route::controller(StokMinimumController::class)->group(function () {
            Route::get('/stok-minimum', 'index')->name('stok-minimum');
            Route::post('/stok-minimum', 'simpan')->name('stok-minimum.simpan');
            Route::post('/stok-minimum/salin', 'salin')->name('stok-minimum.salin');
        });

        Route::controller(ResepController::class)->group(function () {
            Route::get('/menu/{menu}/resep', 'index')->whereNumber('menu')->name('menu.resep');
            Route::get('/menu/{menu}/resep/ubah', 'create')->whereNumber('menu')->name('menu.resep.ubah');
            Route::post('/menu/{menu}/resep', 'store')->whereNumber('menu')->name('menu.resep.simpan');
            Route::delete('/menu/{menu}/resep/{tanggal}', 'destroy')
                ->whereNumber('menu')
                ->where('tanggal', '\d{4}-\d{2}-\d{2}')
                ->name('menu.resep.hapus');
        });
    });

// Admin pusat
Route::middleware(['auth', 'role:admin_pusat', 'akses'])
    ->prefix('pusat')
    ->name('pusat.')
    ->group(function () {
        Route::get('/dashboard', PusatDashboardController::class)->name('dashboard');

        Route::controller(PusatStokController::class)->group(function () {
            Route::get('/stok', 'index')->name('stok');
            Route::get('/stok/{bahanBaku}', 'riwayat')->whereNumber('bahanBaku')->name('stok.riwayat');
            Route::get('/stok/{bahanBaku}/intip', 'intip')->whereNumber('bahanBaku')->name('stok.intip');
            Route::get('/stok/riwayat', 'mutasi')->name('stok.mutasi');
            Route::get('/stok/riwayat/unduh', 'unduhMutasi')->name('stok.mutasi.unduh');
            Route::get('/stok/unduh', 'unduh')->name('stok.unduh');
            Route::get('/stok/tambah', 'tambah')->name('stok.tambah');
            Route::post('/stok/tambah', 'simpanTambah')->name('stok.tambah.simpan');
            Route::get('/stok/isi', 'isi')->name('stok.isi');
            Route::post('/stok/isi', 'simpanIsi')->name('stok.isi.simpan');
            Route::get('/stok/lembar-opname', 'lembarOpname')->name('stok.lembar-opname');
            Route::get('/stok/lembar-opname/unduh', 'unduhLembarOpname')->name('stok.lembar-opname.unduh');
        });

        Route::controller(PusatTerimaBarangController::class)
            ->prefix('terima-barang')
            ->group(function () {
                Route::get('/', 'index')->name('terima-barang');
                Route::get('/unduh', 'unduh')->name('terima-barang.unduh');
                Route::post('/banyak', 'simpanTerimaBanyak')->name('terima-barang.banyak.simpan');
                Route::get('/{kiriman}', 'show')->whereNumber('kiriman')->name('terima-barang.lihat');
                Route::post('/{kiriman}', 'terima')->whereNumber('kiriman')->name('terima-barang.simpan');
            });

        Route::controller(BarangMasukExcelController::class)
            ->prefix('barang-masuk')
            ->group(function () {
                Route::get('/impor', 'unggah')->name('barang-masuk.impor');
                Route::get('/impor/templat', 'templat')->name('barang-masuk.impor.templat');
                Route::post('/impor/pratinjau', 'pratinjau')->name('barang-masuk.impor.pratinjau');
                Route::post('/impor', 'simpan')->name('barang-masuk.impor.simpan');
                Route::get('/ekspor', 'ekspor')->name('barang-masuk.ekspor');
            });

        Route::controller(BarangMasukController::class)
            ->prefix('barang-masuk')
            ->group(function () {
                Route::get('/', 'index')->name('barang-masuk');
                Route::get('/catat', 'create')->name('barang-masuk.catat');
                Route::post('/', 'store')->name('barang-masuk.simpan');
                Route::get('/{barangMasuk}', 'show')->whereNumber('barangMasuk')->name('barang-masuk.lihat');
                Route::get('/{barangMasuk}/pdf', 'pdf')->whereNumber('barangMasuk')->name('barang-masuk.pdf');
                Route::get('/{barangMasuk}/ubah', 'edit')->whereNumber('barangMasuk')->name('barang-masuk.ubah');
                Route::put('/{barangMasuk}', 'update')->whereNumber('barangMasuk')->name('barang-masuk.perbarui');
                Route::patch('/{barangMasuk}/batal', 'batalkan')->whereNumber('barangMasuk')->name('barang-masuk.batal');
            });

        Route::controller(PusatKirimanController::class)
            ->prefix('kiriman')
            ->group(function () {
                Route::get('/', 'index')->name('kiriman');
                Route::get('/unduh', 'unduh')->name('kiriman.unduh');
                Route::post('/banyak', 'simpanKirimBanyak')->name('kiriman.banyak.simpan');
                Route::get('/permintaan/{permintaan}', 'create')->whereNumber('permintaan')->name('kiriman.buat');
                Route::post('/permintaan/{permintaan}', 'store')->whereNumber('permintaan')->name('kiriman.simpan');
                Route::get('/{kiriman}', 'show')->whereNumber('kiriman')->name('kiriman.lihat');
                Route::get('/permintaan/{permintaan}/pdf', 'pdfPermintaan')->whereNumber('permintaan')->name('kiriman.permintaan-pdf');
                Route::get('/siap-kirim/pdf', 'pdfSiapKirim')->name('kiriman.siap-kirim-pdf');
                Route::get('/{kiriman}/surat-jalan', 'suratJalan')->whereNumber('kiriman')->name('kiriman.surat-jalan');
            });

        Route::controller(PusatBarangKeluarController::class)
            ->prefix('barang-keluar')
            ->group(function () {
                Route::get('/', 'index')->name('barang-keluar');
                Route::get('/unduh', 'unduh')->name('barang-keluar.unduh');
                Route::get('/catat', 'create')->name('barang-keluar.catat');
                Route::post('/', 'store')->name('barang-keluar.simpan');
                Route::get('/{barangKeluar}', 'show')->whereNumber('barangKeluar')->name('barang-keluar.lihat');
                Route::get('/{barangKeluar}/pdf', 'pdf')->whereNumber('barangKeluar')->name('barang-keluar.pdf');
                Route::get('/{barangKeluar}/ubah', 'edit')->whereNumber('barangKeluar')->name('barang-keluar.ubah');
                Route::put('/{barangKeluar}', 'update')->whereNumber('barangKeluar')->name('barang-keluar.perbarui');
                Route::patch('/{barangKeluar}/batal', 'batalkan')->whereNumber('barangKeluar')->name('barang-keluar.batal');
            });

        Route::controller(PusatOpnameController::class)
            ->prefix('opname')
            ->group(function () {
                Route::get('/', 'index')->name('opname');
                Route::post('/mulai', 'mulai')->name('opname.mulai');
                Route::get('/impor', 'unggahImpor')->name('opname.impor');
                Route::get('/impor/templat', 'templatImpor')->name('opname.impor.templat');
                Route::post('/impor/pratinjau', 'pratinjauImpor')->name('opname.impor.pratinjau');
                Route::post('/impor', 'simpanImporOpname')->name('opname.impor.simpan');
                Route::get('/{opname}', 'show')->whereNumber('opname')->name('opname.lihat');
                Route::get('/{opname}/pdf', 'pdf')->whereNumber('opname')->name('opname.pdf');
                Route::get('/{opname}/excel', 'excel')->whereNumber('opname')->name('opname.excel');
                Route::put('/{opname}', 'simpan')->whereNumber('opname')->name('opname.simpan');
                Route::patch('/{opname}/batal', 'batalkan')->whereNumber('opname')->name('opname.batal');
            });
    });

// Admin cabang
Route::middleware(['auth', 'role:admin_cabang', 'akses'])
    ->prefix('cabang')
    ->name('cabang.')
    ->group(function () {
        Route::get('/dashboard', CabangDashboardController::class)->name('dashboard');

        Route::controller(CabangStokController::class)->group(function () {
            Route::get('/stok', 'index')->name('stok');
            Route::get('/stok/{bahanBaku}', 'riwayat')->whereNumber('bahanBaku')->name('stok.riwayat');
            Route::get('/stok/{bahanBaku}/intip', 'intip')->whereNumber('bahanBaku')->name('stok.intip');
            Route::get('/stok/riwayat', 'mutasi')->name('stok.mutasi');
            Route::get('/stok/riwayat/unduh', 'unduhMutasi')->name('stok.mutasi.unduh');
            Route::get('/stok/unduh', 'unduh')->name('stok.unduh');
            Route::get('/stok/isi', 'isi')->name('stok.isi');
            Route::post('/stok/isi', 'simpanIsi')->name('stok.isi.simpan');
            Route::get('/stok/lembar-opname', 'lembarOpname')->name('stok.lembar-opname');
            Route::get('/stok/lembar-opname/unduh', 'unduhLembarOpname')->name('stok.lembar-opname.unduh');
        });

        Route::controller(PenjualanController::class)
            ->prefix('penjualan')
            ->group(function () {
                Route::get('/', 'index')->name('penjualan');
                Route::get('/unduh', 'unduh')->name('penjualan.unduh');
                Route::get('/catat', 'create')->name('penjualan.catat');
                Route::post('/', 'store')->name('penjualan.simpan');
                Route::post('/perkiraan', 'perkiraan')->name('penjualan.perkiraan');
                Route::get('/{penjualan}', 'show')->whereNumber('penjualan')->name('penjualan.lihat');
                Route::get('/{penjualan}/ubah', 'edit')->whereNumber('penjualan')->name('penjualan.ubah');
                Route::put('/{penjualan}', 'update')->whereNumber('penjualan')->name('penjualan.perbarui');
            });

        Route::controller(PenjualanExcelController::class)
            ->prefix('penjualan/impor')
            ->group(function () {
                Route::get('/', 'unggah')->name('penjualan.impor');
                Route::get('/templat', 'templat')->name('penjualan.impor.templat');
                Route::post('/cek', 'pratinjau')->name('penjualan.impor.pratinjau');
                Route::post('/simpan', 'simpan')->name('penjualan.impor.simpan');
            });

        Route::controller(PermintaanExcelController::class)
            ->prefix('permintaan')
            ->group(function () {
                Route::get('/impor', 'unggah')->name('permintaan.impor');
                Route::get('/impor/templat', 'templat')->name('permintaan.impor.templat');
                Route::post('/impor/pratinjau', 'pratinjau')->name('permintaan.impor.pratinjau');
                Route::post('/impor', 'simpan')->name('permintaan.impor.simpan');
                Route::get('/{permintaan}/excel', 'excel')->whereNumber('permintaan')->name('permintaan.excel');
            });

        Route::controller(PermintaanController::class)
            ->prefix('permintaan')
            ->group(function () {
                Route::get('/', 'index')->name('permintaan');
                Route::get('/unduh', 'unduh')->name('permintaan.unduh');
                Route::get('/buat', 'create')->name('permintaan.buat');
                Route::post('/', 'store')->name('permintaan.simpan');
                Route::get('/{permintaan}', 'show')->whereNumber('permintaan')->name('permintaan.lihat');
                Route::get('/{permintaan}/pdf', 'pdf')->whereNumber('permintaan')->name('permintaan.pdf');
                Route::patch('/{permintaan}/batal', 'batalkan')->whereNumber('permintaan')->name('permintaan.batal');
            });

        Route::controller(TerimaBarangController::class)
            ->prefix('terima-barang')
            ->group(function () {
                Route::get('/', 'index')->name('terima-barang');
                Route::get('/unduh', 'unduh')->name('terima-barang.unduh');
                Route::post('/banyak', 'simpanTerimaBanyak')->name('terima-barang.banyak.simpan');
                Route::get('/{kiriman}', 'show')->whereNumber('kiriman')->name('terima-barang.lihat');
                Route::post('/{kiriman}', 'terima')->whereNumber('kiriman')->name('terima-barang.simpan');
            });

        Route::controller(KirimBarangController::class)
            ->prefix('kirim-barang')
            ->group(function () {
                Route::get('/', 'index')->name('kirim-barang');
                Route::get('/unduh', 'unduh')->name('kirim-barang.unduh');
                Route::get('/buat', 'create')->name('kirim-barang.buat');
                Route::post('/', 'store')->name('kirim-barang.simpan');
                Route::get('/{kiriman}', 'show')->whereNumber('kiriman')->name('kirim-barang.lihat');
                Route::get('/{kiriman}/surat-jalan', 'suratJalan')->whereNumber('kiriman')->name('kirim-barang.surat-jalan');
            });

        Route::controller(CabangBarangKeluarController::class)
            ->prefix('barang-keluar')
            ->group(function () {
                Route::get('/', 'index')->name('barang-keluar');
                Route::get('/unduh', 'unduh')->name('barang-keluar.unduh');
                Route::get('/catat', 'create')->name('barang-keluar.catat');
                Route::post('/', 'store')->name('barang-keluar.simpan');
                Route::get('/{barangKeluar}', 'show')->whereNumber('barangKeluar')->name('barang-keluar.lihat');
                Route::get('/{barangKeluar}/pdf', 'pdf')->whereNumber('barangKeluar')->name('barang-keluar.pdf');
                Route::get('/{barangKeluar}/ubah', 'edit')->whereNumber('barangKeluar')->name('barang-keluar.ubah');
                Route::put('/{barangKeluar}', 'update')->whereNumber('barangKeluar')->name('barang-keluar.perbarui');
                Route::patch('/{barangKeluar}/batal', 'batalkan')->whereNumber('barangKeluar')->name('barang-keluar.batal');
            });

        Route::controller(CabangOpnameController::class)
            ->prefix('opname')
            ->group(function () {
                Route::get('/', 'index')->name('opname');
                Route::post('/mulai', 'mulai')->name('opname.mulai');
                Route::get('/impor', 'unggahImpor')->name('opname.impor');
                Route::get('/impor/templat', 'templatImpor')->name('opname.impor.templat');
                Route::post('/impor/pratinjau', 'pratinjauImpor')->name('opname.impor.pratinjau');
                Route::post('/impor', 'simpanImporOpname')->name('opname.impor.simpan');
                Route::get('/{opname}', 'show')->whereNumber('opname')->name('opname.lihat');
                Route::get('/{opname}/pdf', 'pdf')->whereNumber('opname')->name('opname.pdf');
                Route::get('/{opname}/excel', 'excel')->whereNumber('opname')->name('opname.excel');
                Route::put('/{opname}', 'simpan')->whereNumber('opname')->name('opname.simpan');
                Route::patch('/{opname}/batal', 'batalkan')->whereNumber('opname')->name('opname.batal');
            });
    });
