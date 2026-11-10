<?php

namespace App\Support;

use App\Models\User;

/**
 * Isi halaman Bantuan per peran. Ini teks panduan aplikasi (bukan master data).
 *
 * Kunci topik:
 *   judul    judul topik
 *   ikon     nama ikon (components/ikon)
 *   ringkas  satu kalimat, tampil di bawah judul
 *   rute     halaman yang dijelaskan; topik disembunyikan bila peran itu tidak boleh membukanya,
 *            dan tombol "?" di kepala halaman itu (termasuk sub-halamannya) membuka topik ini
 *   catat    true = topik hanya tampil bila peran itu boleh mencatat di halaman tersebut;
 *            false = hanya tampil bila TIDAK boleh mencatat (versi lain topik yang sama)
 *   modul    modul yang harus menyala (DaftarKontrol::MODUL)
 *   langkah  urutan kerja; tiap butir berupa teks, atau ['teks', 'modul' => ..., 'catat' => rute]
 *            (butir dengan 'catat' hanya tampil bila peran itu boleh mencatat di rute tersebut)
 *   tips     catatan singkat; formatnya sama dengan langkah
 *
 * Teks ditulis singkat; nama tombol ditulis persis seperti di layar.
 */
final class DaftarBantuan
{
    /** Topik untuk semua peran, tampil setelah topik peran. */
    public const UMUM = [
        'lampu-stok' => [
            'judul'   => 'Arti Lampu Stok',
            'ikon'    => 'kotak',
            'ringkas' => 'Tanda Habis, Menipis, dan Aman di halaman Stok dan dashboard.',
            'langkah' => [
                'Habis (merah): stok nol atau minus. Minus berarti catatan sistem dan barang di rak tidak cocok, cek dengan stock opname.',
                'Menipis (kuning): stok di bawah atau hampir menyentuh stok minimum.',
                'Aman (hijau): stok di atas batas menipis.',
                'Tekan nama bahan untuk melihat stok, pemakaian per hari, dan mutasi terakhir.',
            ],
            'tips' => [
                'Tekan "/" di laptop untuk langsung mencari bahan.',
            ],
        ],
        'notifikasi' => [
            'judul'   => 'Notifikasi',
            'ikon'    => 'lonceng',
            'ringkas' => 'Kabar dokumen yang perlu ditindaklanjuti.',
            'rute'    => 'notifikasi',
            'langkah' => [
                'Angka merah di lonceng = notifikasi yang belum dibaca.',
                'Tekan satu notifikasi untuk langsung membuka dokumennya.',
                'Tekan "Tandai Semua Dibaca" bila semuanya sudah dicek.',
            ],
        ],
        'akun' => [
            'judul'   => 'Akun',
            'ikon'    => 'kunci',
            'ringkas' => 'Ganti foto profil, password, dan atur email laporan harian.',
            'rute'    => 'akun',
            'langkah' => [
                'Foto Profil: tekan Pilih Foto, pilih gambar, lalu Simpan Foto. Centang Hapus Foto untuk kembali ke inisial.',
                'Buka Akun, isi password lama dan password baru, lalu simpan.',
                'Lupa password? Minta Admin Utama mengatur ulang.',
                'Email Laporan Harian: centang Kirim otomatis, isi email tujuan (satu per baris), pilih laporan, lalu Simpan. Laporan terkirim setiap hari pukul 23.59.',
            ],
            'tips' => [
                'Keluar dari aplikasi bila memakai HP atau komputer bersama.',
            ],
        ],
    ];

    public const TOPIK = [
        User::ROLE_ADMIN_UTAMA => [
            'mulai' => [
                'judul'   => 'Mulai dari Nol',
                'ikon'    => 'rumah',
                'ringkas' => 'Urutan mengisi data sebelum aplikasi dipakai harian.',
                'langkah' => [
                    'Tambah Lokasi: satu Gudang Pusat dan semua cabang.',
                    'Tambah Pengguna untuk Admin Pusat dan Admin Cabang.',
                    'Isi Bahan Baku dan Menu lewat tombol "Impor" di daftarnya: unduh templat, isi di Excel, unggah, cek, lalu simpan.',
                    'Isi stok awal tiap lokasi di Stok: pilih lokasi, tekan "Perbarui Lewat Excel", unduh file stok, ubah angka di kolom Stok, unggah, cek, lalu simpan.',
                    'Atau semuanya dalam satu file: di Dashboard tekan "Impor Sekaligus", unduh templat, isi lembar Lokasi, Bahan, Menu, Resep, Stok Minimum, dan Stok yang diperlukan saja, lalu unggah. Semua tabel juga boleh ditumpuk dalam satu lembar. Lokasi bisa ditulis Semua atau Semua Cabang.',
                    'Atur Stok Minimum per cabang, lalu Menu & Resep supaya penjualan memotong stok.',
                ],
                'tips' => [
                    'Cara yang sama dipakai bila stok perlu dibetulkan nanti; setiap perubahan tercatat sebagai penyesuaian stok.',
                ],
            ],
            'persetujuan' => [
                'judul'   => 'Persetujuan',
                'ikon'    => 'ceklis',
                'ringkas' => 'Barang Masuk, permintaan, barang rusak, dan stock opname menunggu keputusan Anda.',
                'rute'    => 'admin-utama.persetujuan',
                'langkah' => [
                    'Centang kotak di kanan dokumen yang bertanda Wajar, lalu tekan "Setujui yang Dicentang" di bilah bawah untuk menyetujui sekaligus.',
                    'Dokumen bertanda "Cek: …" dibuka satu per satu. Alasannya tertulis di baris itu.',
                    'Di detail dokumen: Setujui, atau buka "Tolak atau Kembalikan" dan tulis alasannya.',
                    'Barang Rusak dan stock opname selalu dicek satu per satu.',
                    'Setelah permintaan disetujui, form kirim langsung terbuka. Tekan "Kirim Sekarang", atau kirim nanti lewat menu Kiriman.',
                    'Tekan "Unduh PDF" di permintaan yang sudah disetujui untuk mencetak daftar barangnya.',
                ],
                'tips' => [
                    'Wajar: permintaan tidak melebihi kebutuhan cabang dan stok pusat cukup; barang masuk tidak lebih banyak dari biasanya.',
                    ['teks' => 'Permintaan yang lewat batas waktu ditolak otomatis.', 'modul' => DaftarKontrol::MODUL_TOLAK_OTOMATIS],
                ],
            ],
            'kiriman' => [
                'judul'   => 'Kiriman',
                'ikon'    => 'truk',
                'ringkas' => 'Mengirim barang dari Gudang Pusat untuk permintaan yang sudah disetujui.',
                'rute'    => 'admin-utama.kiriman',
                'langkah' => [
                    'Angka di menu Kiriman = permintaan disetujui yang belum dikirim.',
                    'Tekan permintaan, cek daftar barangnya, lalu "Kirim Sekarang". Jumlah mengikuti persetujuan; bila stok gudang kurang, dikirim sebanyak yang ada dan sisanya tetap di Siap Dikirim. Stok Gudang langsung berkurang.',
                    'Cabang mencocokkan barang yang tiba. Stok Cabang bertambah setelah cabang menyimpan penerimaan.',
                    '"Unduh PDF" di bagian Siap Dikirim mencetak permintaan yang belum terkirim penuh, lengkap dengan status tiap barang; "Surat Jalan" di detail kiriman dicetak untuk dibawa bersama barang.',
                ],
            ],
            'dashboard' => [
                'judul'   => 'Dashboard',
                'ikon'    => 'dashboard',
                'ringkas' => 'Ringkasan semua lokasi dalam satu layar.',
                'rute'    => 'admin-utama.dashboard',
                'langkah' => [
                    'Kartu atas: yang menunggu keputusan, stok habis/menipis, dan kiriman di jalan. Tekan kartu untuk membuka daftarnya.',
                    ['teks' => 'Peringatan: pola yang patut dicek, misalnya stok minus atau penjualan belum dicatat.', 'modul' => DaftarKontrol::MODUL_PERINGATAN_DINI],
                    'Ringkasan per Lokasi: tekan satu lokasi untuk membuka stoknya.',
                ],
            ],
            'stok' => [
                'judul'   => 'Stok',
                'ikon'    => 'kotak',
                'ringkas' => 'Pantau stok per lokasi.',
                'rute'    => 'admin-utama.stok',
                'langkah' => [
                    'Pilih lokasi di atas daftar.',
                    'Tekan chip Habis / Menipis untuk menyaring, atau ketik nama bahan.',
                    'Tekan nama bahan untuk melihat ringkasannya, lalu "Riwayat Lengkap" untuk semua mutasi.',
                    'Centang kotak di kanan bahan yang habis atau menipis, lalu tekan "Isi Terpilih" (tanpa centang: "Isi Semua"). Jumlah terisi minimum + cadangan (bawaan 20%) dan bisa diubah dulu. Stok langsung bertambah.',
                    'Angka stok tidak sesuai rak? Tekan ikon pensil di baris bahan, pilih Kurangi (isi jumlah yang berkurang) atau Ganti (isi jumlah yang benar-benar ada), lalu simpan. Selisihnya tercatat di riwayat.',
                    'Banyak bahan sekaligus: tekan "Perbarui Lewat Excel", unggah file Unduh Excel, templat, atau tabel sendiri (kolom nama bahan dan stok), pilih Tambah atau Ganti, cek, lalu simpan.',
                    'Tekan "Riwayat" untuk melihat stok per hari (awal, masuk, keluar, akhir) hari ini; geser panah untuk hari sebelumnya. Tab lain: "Masuk & Keluar" dan "Pemakaian Harian".',
                    'Tekan "Lembar Opname" untuk mengunduh PDF hitung fisik per kategori.',
                ],
            ],
            'laporan' => [
                'judul'   => 'Laporan',
                'ikon'    => 'grafik',
                'ringkas' => 'Rekap Stok, penjualan, selisih, dan barang rusak.',
                'rute'    => 'admin-utama.laporan',
                'langkah' => [
                    'Pilih jenis laporan, lalu pilih periode lewat chip (7 hari, Bulan ini, Bulan lalu) atau tanggal sendiri.',
                    'Tekan "Unduh Excel" untuk menyimpan laporan.',
                    'Rekap Stok dan pemakaian bahan dikelompokkan per kategori; pilih "Kategori Bahan" untuk melihat satu kategori saja.',
                    'Laporan "Per Kategori" merangkum porsi per kategori menu (bagian dari total dan menu terlaris) serta bahan dapur per kategori bahan.',
                    'Di laporan penjualan, tekan "Impor Penjualan" untuk memasukkan penjualan harian semua cabang dari Excel (kolom tanggal, lokasi, menu, porsi). Tanggal yang sudah dicatat cabang dilewati (catatan cabang tidak ditimpa); tanggal hasil impor sebelumnya diganti isinya. Stok bahan dipotong sesuai resep.',
                ],
            ],
            'data-master' => [
                'judul'   => 'Data Master',
                'ikon'    => 'label',
                'ringkas' => 'Lokasi, kategori, satuan, bahan baku, menu, dan pengguna.',
                'rute'    => 'admin-utama.bahan-baku',
                'langkah' => [
                    'Tekan "Tambah" di kepala halaman untuk satu data, atau "Impor" untuk banyak data sekaligus dari Excel.',
                    'Tekan "Ekspor" untuk mengunduh isi daftar ke Excel. File ekspor bisa diubah lalu diimpor di sistem lain dengan kolom yang sama.',
                    'Tekan nama data untuk mengubahnya.',
                    'Data yang sudah dipakai tidak bisa dihapus: nonaktifkan saja supaya tidak muncul di pilihan.',
                    'Tekan "Riwayat" untuk melihat siapa menambah, mengubah, atau menonaktifkan data, berikut nilai lama dan barunya. Impor Excel tercatat sebagai satu baris.',
                ],
                'tips' => [
                    'Jumlah stok diisi dalam satuan bahan (mis. kg atau liter). Resep memakai gram dan ml; sistem yang menghitungnya.',
                    'Jumlah boleh ditulis 2x10, 10 liter, atau 500 ml (di form dan Excel); sistem menghitungnya ke satuan bahan. Bahan yang sama di beberapa baris dijumlahkan.',
                ],
            ],
            'resep' => [
                'judul'   => 'Menu & Resep',
                'ikon'    => 'mangkuk',
                'ringkas' => 'Takaran bahan per porsi, dipakai untuk memotong stok saat penjualan.',
                'rute'    => 'admin-utama.menu',
                'langkah' => [
                    'Di daftar menu, tekan tombol resep pada menu itu, lalu "Isi Resep" atau "Ubah Resep".',
                    'Isi takaran tiap bahan per satu porsi dan tanggal mulai berlaku, lalu simpan.',
                    'Resep lama tetap dipakai untuk penjualan sebelum tanggal itu.',
                    'Banyak resep sekaligus: tekan "Impor Resep", satu baris per bahan (menu, bahan, takaran per porsi). Menu yang belum ada ikut dibuat; kosongkan bahan untuk menu tanpa resep.',
                    'Tekan "Ekspor Resep" untuk mengunduh resep yang berlaku, ubah di Excel, lalu impor lagi. Resep dari Excel berlaku mulai hari diimpor; untuk tanggal lain pakai Ubah Resep.',
                ],
            ],
            'stok-minimum' => [
                'judul'   => 'Stok Minimum',
                'ikon'    => 'grafik',
                'ringkas' => 'Batas bawah stok tiap cabang; dasar lampu kuning dan permintaan.',
                'rute'    => 'admin-utama.stok-minimum',
                'langkah' => [
                    'Pilih cabang, isi minimum tiap bahan dengan tombol − / +, lalu simpan.',
                    'Pakai "Salin dari Lokasi Lain" bila cabang baru mirip cabang lama.',
                    'Lewat Excel: tekan "Ekspor" untuk mengunduh semua lokasi dan bahan, ubah kolom minimum, lalu "Impor". Kosong atau 0 berarti tanpa batas minimum Kolom Lokasi boleh diisi Semua atau Semua Cabang; baris yang menulis lokasinya langsung tetap diutamakan.',
                    ['teks' => 'Bahan di bawah minimum dibuatkan permintaan otomatis setiap hari.', 'modul' => DaftarKontrol::MODUL_PERMINTAAN_OTOMATIS],
                ],
            ],
            'pengaturan' => [
                'judul'   => 'Pengaturan',
                'ikon'    => 'gear',
                'ringkas' => 'Angka aturan, identitas usaha, fitur nyala/mati, akun, hak akses, dan periode buku.',
                'rute'    => 'admin-utama.pengaturan',
                'langkah' => [
                    'Umum: ubah angka aturan seperti batas waktu persetujuan dengan tombol − / +, lalu simpan.',
                    'Fitur: nyalakan atau matikan fitur. Akibatnya ditampilkan sebelum Anda memastikan.',
                    'Akun: nyalakan atau matikan akun. Akun yang dimatikan langsung keluar dan tidak bisa masuk.',
                    'Hak Akses: atur menu yang boleh dilihat atau dicatat Admin Pusat dan Admin Cabang.',
                ],
                'tips' => [
                    'Permintaan Otomatis dan Tolak Otomatis hanya berjalan bila jadwal (cron) di hosting sudah dipasang pengelola sistem.',
                ],
            ],
        ],

        User::ROLE_ADMIN_PUSAT => [
            'barang-masuk' => [
                'judul'   => 'Barang Masuk',
                'ikon'    => 'masuk',
                'ringkas' => 'Barang baru yang tiba di Gudang Pusat.',
                'rute'    => 'pusat.barang-masuk',
                'catat'   => true,
                'langkah' => [
                    'Tekan "Catat", pilih tanggal, lalu tambah baris: bahan dan jumlah (satuan bahan).',
                    'Simpan. Stok bertambah setelah Admin Utama menyetujui.',
                    'Bila dikembalikan, buka dokumennya, tekan "Ubah", betulkan, lalu simpan lagi.',
                    'Banyak barang sekaligus: tekan "Impor", isi templat Excel (tanggal, bahan, jumlah). Satu tanggal menjadi satu dokumen yang menunggu persetujuan.',
                ],
            ],
            'kiriman' => [
                'judul'   => 'Kiriman',
                'ikon'    => 'truk',
                'ringkas' => 'Mengirim barang untuk permintaan cabang yang sudah disetujui.',
                'rute'    => 'pusat.kiriman',
                'catat'   => true,
                'langkah' => [
                    'Angka di menu Kiriman = permintaan disetujui yang belum dikirim.',
                    'Tekan permintaan, cek daftar barangnya, lalu "Kirim Sekarang". Jumlah mengikuti persetujuan Admin Utama; bila stok gudang kurang, dikirim sebanyak yang ada dan sisanya menyusul.',
                    'Banyak permintaan sekaligus: centang kotak di kanan permintaan di Siap Dikirim, lalu tekan "Kirim yang Dicentang" di bilah bawah. Barang yang stoknya kurang dikirim sebagian.',
                    'Stok Gudang langsung berkurang.',
                    'Cabang mencocokkan barang yang tiba; selisihnya tercatat di laporan.',
                    '"Unduh PDF" di bagian Siap Dikirim mencetak permintaan yang belum terkirim penuh untuk disiapkan; "PDF Permintaan" (semua barang dan statusnya) ada di form kirim.',
                    'Setelah kirim, tekan "Surat Jalan" di detail kiriman dan cetak untuk dibawa bersama barang.',
                ],
            ],
            'terima-retur' => [
                'judul'   => 'Terima Retur',
                'ikon'    => 'masuk',
                'ringkas' => 'Mencocokkan barang retur dari cabang sebelum masuk stok gudang.',
                'rute'    => 'pusat.terima-barang',
                'catat'   => true,
                'langkah' => [
                    'Angka di menu Terima Retur = kiriman cabang yang belum diterima.',
                    'Buka kiriman, cocokkan barang yang tiba, lalu pilih "Sesuai Semua" atau "Ada yang Beda".',
                    'Beberapa retur sekaligus: centang kiriman yang cocok semua, lalu tekan "Terima yang Dicentang" di bilah bawah.',
                    'Stok Gudang bertambah sebanyak yang diterima.',
                ],
            ],
            'stok-gudang' => [
                'judul'   => 'Stok',
                'ikon'    => 'kotak',
                'ringkas' => 'Stok Gudang Pusat saat ini.',
                'rute'    => 'pusat.stok',
                'langkah' => [
                    'Tekan chip Habis / Menipis untuk menyaring, atau ketik nama bahan.',
                    'Tekan nama bahan untuk melihat ringkasan dan riwayatnya.',
                    ['teks' => 'Centang kotak di kanan bahan, lalu tekan "Isi Terpilih" (tanpa centang: "Isi Semua" untuk yang habis atau menipis). Jumlah terisi minimum + cadangan (bawaan 20%) dan bisa diubah dulu. Stok langsung bertambah.', 'catat' => 'pusat.stok.isi'],
                    'Angka stok tidak sesuai rak? Laporkan ke Admin Utama; hanya Admin Utama yang bisa mengubah, mengosongkan, dan menghapus.',
                    'Tekan "Riwayat" untuk stok per hari (hari ini, kemarin, atau tanggal lain), barang masuk & keluar, dan pemakaian harian. "Lembar Opname" untuk PDF hitung fisik per kategori.',
                ],
            ],
            'barang-rusak' => [
                'judul'   => 'Barang Rusak',
                'ikon'    => 'keluar',
                'ringkas' => 'Barang rusak, kedaluwarsa, atau tumpah.',
                'rute'    => 'pusat.barang-keluar',
                'catat'   => true,
                'langkah' => [
                    'Tekan "Catat", lalu tambah baris: bahan, jumlah, dan alasannya.',
                    'Simpan. Stok berkurang setelah Admin Utama menyetujui.',
                ],
            ],
            'opname' => [
                'judul'   => 'Opname',
                'ikon'    => 'ceklis',
                'ringkas' => 'Hitung Fisik stok untuk mencocokkan dengan sistem.',
                'rute'    => 'pusat.opname',
                'catat'   => true,
                'langkah' => [
                    'Tekan "Mulai Opname". Stok Gudang dikunci sampai hasilnya diputuskan.',
                    'Hitung barang di rak dan isi jumlahnya. Tekan "Simpan Dulu" untuk mencicil.',
                    'Tekan "Kirim ke Admin Utama" bila semua sudah dihitung. Admin Utama memutuskan selisihnya.',
                ],
                'tips' => [
                    'Selama opname, kiriman dan persetujuan untuk gudang ditahan. Selesaikan di hari yang sama.',
                    'Lewat Excel: tekan "Impor Opname", unduh templat, isi kolom Hitung Fisik, lalu unggah. Opname langsung dikirim ke Admin Utama; bahan yang tidak diisi dianggap 0.',
                ],
            ],
        ],

        User::ROLE_ADMIN_CABANG => [
            'penjualan' => [
                'judul'   => 'Penjualan',
                'ikon'    => 'grafik',
                'ringkas' => 'Porsi Terjual hari ini; stok bahan dipotong sesuai resep.',
                'rute'    => 'cabang.penjualan',
                'langkah' => [
                    'Tekan "Catat Penjualan", lalu tekan + / − di tiap menu sesuai porsi terjual.',
                    'Lihat "Perkiraan Stok Setelah Disimpan" di bawah menu: sisa tiap bahan dihitung otomatis dari resep, yang akan habis atau minus ditandai merah.',
                    'Simpan. Stok bahan langsung berkurang tanpa menunggu persetujuan.',
                    'Salah isi? Buka penjualan hari itu dan tekan "Ubah".',
                ],
                'tips' => [
                    'Catat setiap hari setelah tutup. Penjualan yang belum dicatat muncul sebagai peringatan untuk Admin Utama.',
                    'Punya rekap dari kasir? Tekan "Impor Excel", unduh templat (semua menu sudah terisi), isi kolom Porsi, lalu unggah. Cek hasilnya, jawab bila ada "Maksudnya …?", lalu Simpan.',
                    'Impor Excel tanpa kolom Tanggal dicatat untuk hari ini. Bila hari itu sudah dicatat, porsi menu di file mengganti yang lama dan menu lain tetap.',
                ],
            ],
            'terima-barang' => [
                'judul'   => 'Terima Barang',
                'ikon'    => 'masuk',
                'ringkas' => 'Mencocokkan kiriman dari Gudang Pusat atau cabang lain sebelum masuk stok.',
                'rute'    => 'cabang.terima-barang',
                'catat'   => true,
                'langkah' => [
                    'Angka di menu Terima = kiriman yang belum diterima.',
                    'Buka kiriman dan cocokkan barang yang tiba dengan daftar.',
                    'Semua cocok? Pilih "Sesuai Semua" lalu simpan.',
                    'Banyak kiriman datang sekaligus? Centang kiriman yang barangnya cocok semua, lalu tekan "Terima yang Dicentang" di bilah bawah.',
                    'Ada yang kurang atau rusak? Pilih "Ada yang Beda", ubah jumlah layak pakai dan kondisinya, lalu simpan.',
                    'Stok Cabang bertambah sebanyak yang diterima. Selisih dengan kiriman tercatat otomatis.',
                    'Surat Jalan dibawa pengirim bersama barang; tanda tangani sebagai penerima.',
                ],
            ],
            'kirim-barang' => [
                'judul'   => 'Kirim Barang',
                'ikon'    => 'truk',
                'ringkas' => 'Mengirim barang ke cabang lain atau retur ke Gudang Pusat, tanpa persetujuan.',
                'rute'    => 'cabang.kirim-barang',
                'catat'   => true,
                'langkah' => [
                    'Tekan "Kirim", pilih tujuan (cabang lain, atau Gudang Pusat untuk retur), lalu isi bahan dan jumlahnya.',
                    'Simpan. Stok Cabang langsung berkurang dan Admin Utama diberi tahu.',
                    'Buka kiriman itu, tekan "Surat Jalan", lalu cetak untuk dibawa bersama barang.',
                    'Tujuan mencocokkan barang saat tiba; hasilnya tampil di detail kiriman.',
                ],
            ],
            'stok-cabang' => [
                'judul'   => 'Stok',
                'ikon'    => 'kotak',
                'ringkas' => 'Stok Cabang saat ini.',
                'rute'    => 'cabang.stok',
                'langkah' => [
                    'Tekan chip Habis / Menipis untuk menyaring, atau ketik nama bahan.',
                    'Tekan nama bahan untuk melihat ringkasannya.',
                    ['teks' => 'Centang kotak di kanan bahan yang habis atau menipis, lalu tekan "Minta Terpilih" (tanpa centang: "Minta Semua"). Jumlah terisi minimum + cadangan (bawaan 20%) dan bisa diubah dulu. Semua bahan menjadi satu permintaan ke Admin Utama.', 'catat' => 'cabang.permintaan'],
                    ['teks' => 'Dari ringkasan bahan, tekan "Minta Barang" untuk membuat permintaan berisi beberapa bahan.', 'catat' => 'cabang.permintaan'],
                    'Stok cabang tidak bisa ditambah atau diubah langsung. Bila angkanya tidak sesuai rak, lakukan Stock Opname atau hubungi Admin Utama.',
                    'Tekan "Riwayat" untuk stok per hari (hari ini, kemarin, atau tanggal lain), barang masuk & keluar, dan pemakaian harian. "Lembar Opname" untuk PDF hitung fisik per kategori.',
                ],
            ],
            'permintaan' => [
                'judul'   => 'Permintaan',
                'ikon'    => 'panah-kiri',
                'ringkas' => 'Meminta bahan ke Gudang Pusat.',
                'rute'    => 'cabang.permintaan',
                'catat'   => true,
                'langkah' => [
                    'Tekan "Buat", lalu "Isi Otomatis" untuk mengisi bahan di bawah minimum, atau tambah baris sendiri.',
                    'Simpan. Admin Utama menyetujui lalu mengirim barangnya.',
                    'Permintaan yang belum diputuskan bisa dibatalkan dari halaman detailnya.',
                    'Permintaan yang sudah disetujui punya tombol "Unduh PDF" berisi barang yang disetujui.',
                ],
                'tips' => [
                    ['teks' => 'Bahan di bawah minimum juga dimintakan otomatis setiap hari.', 'modul' => DaftarKontrol::MODUL_PERMINTAAN_OTOMATIS],
                    'Lewat Excel: tekan "Impor Permintaan", unduh templat (usulan sudah terisi), ubah kolom Jumlah, lalu unggah. Jumlah kosong atau 0 tidak diminta.',
                ],
            ],
            'permintaan-otomatis' => [
                'judul'   => 'Permintaan',
                'ikon'    => 'panah-kiri',
                'ringkas' => 'Permintaan dibuat otomatis untuk bahan di bawah minimum.',
                'rute'    => 'cabang.permintaan',
                'catat'   => false,
                'modul'   => DaftarKontrol::MODUL_PERMINTAAN_OTOMATIS,
                'langkah' => [
                    'Setiap hari sistem membuat permintaan untuk bahan di bawah minimum.',
                    'Pantau statusnya di menu Permintaan.',
                ],
            ],
            'barang-rusak' => [
                'judul'   => 'Barang Rusak',
                'ikon'    => 'keluar',
                'ringkas' => 'Barang rusak, kedaluwarsa, atau tumpah.',
                'rute'    => 'cabang.barang-keluar',
                'catat'   => true,
                'langkah' => [
                    'Tekan "Catat", lalu tambah baris: bahan, jumlah, dan alasannya.',
                    'Simpan. Stok berkurang setelah Admin Utama menyetujui.',
                ],
            ],
            'opname' => [
                'judul'   => 'Opname',
                'ikon'    => 'ceklis',
                'ringkas' => 'Hitung Fisik stok untuk mencocokkan dengan sistem.',
                'rute'    => 'cabang.opname',
                'catat'   => true,
                'langkah' => [
                    'Tekan "Mulai Opname". Stok Cabang dikunci sampai hasilnya diputuskan.',
                    'Hitung barang dan isi jumlahnya. Tekan "Simpan Dulu" untuk mencicil.',
                    'Tekan "Kirim ke Admin Utama" bila semua sudah dihitung. Admin Utama memutuskan selisihnya.',
                ],
                'tips' => [
                    'Selama opname, penjualan dan kiriman untuk cabang ditahan. Selesaikan sebelum buka.',
                    'Lewat Excel: tekan "Impor Opname", unduh templat, isi kolom Hitung Fisik, lalu unggah. Opname langsung dikirim ke Admin Utama; bahan yang tidak diisi dianggap 0.',
                ],
            ],
        ],
    ];
}
