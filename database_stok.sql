-- Struktur database Stokku (MySQL / MariaDB), sama dengan hasil `php artisan migrate`.
-- Database bersih: hanya struktur tabel, daftar migrations, dan pengaturan bawaan. Tidak ada data lokasi, bahan, atau akun.
-- Setelah impor lewat phpMyAdmin: isi ADMIN_UTAMA_NAMA, ADMIN_UTAMA_EMAIL, ADMIN_UTAMA_PASSWORD di .env, lalu jalankan
--   php artisan config:clear
--   php artisan db:seed --class=AdminUtamaAwalSeeder
-- Data Stokku diisi lewat aplikasi atau paket templat Excel (lihat BACA-DULU.txt di paket).
-- Tabel lama yang sudah tidak dipakai (supplier, Web Order) ikut dihapus bila masih ada.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------
-- Hapus tabel lama
-- --------------------------------------------------------

DROP TABLE IF EXISTS `penjualan_masuk_detail`;
DROP TABLE IF EXISTS `penjualan_masuk`;
DROP TABLE IF EXISTS `klien_api`;
DROP TABLE IF EXISTS `menu_kode_luar`;
DROP TABLE IF EXISTS `supplier`;
DROP TABLE IF EXISTS `migrations`;
DROP TABLE IF EXISTS `email_laporan`;
DROP TABLE IF EXISTS `periode_buku`;
DROP TABLE IF EXISTS `penyesuaian_stok`;
DROP TABLE IF EXISTS `tambah_stok`;
DROP TABLE IF EXISTS `impor_stok`;
DROP TABLE IF EXISTS `log_perubahan`;
DROP TABLE IF EXISTS `kontrol_sistem`;
DROP TABLE IF EXISTS `pengaturan`;
DROP TABLE IF EXISTS `notifikasi`;
DROP TABLE IF EXISTS `persetujuan`;
DROP TABLE IF EXISTS `opname_detail`;
DROP TABLE IF EXISTS `opname`;
DROP TABLE IF EXISTS `penjualan_detail`;
DROP TABLE IF EXISTS `penjualan_harian`;
DROP TABLE IF EXISTS `barang_keluar_detail`;
DROP TABLE IF EXISTS `barang_keluar`;
DROP TABLE IF EXISTS `barang_masuk_detail`;
DROP TABLE IF EXISTS `barang_masuk`;
DROP TABLE IF EXISTS `kiriman_detail`;
DROP TABLE IF EXISTS `kiriman`;
DROP TABLE IF EXISTS `permintaan_detail`;
DROP TABLE IF EXISTS `permintaan`;
DROP TABLE IF EXISTS `stok_mutasi_batch`;
DROP TABLE IF EXISTS `stok_batch`;
DROP TABLE IF EXISTS `stok_saldo`;
DROP TABLE IF EXISTS `stok_mutasi`;
DROP TABLE IF EXISTS `resep`;
DROP TABLE IF EXISTS `menu`;
DROP TABLE IF EXISTS `bahan_baku_minimum`;
DROP TABLE IF EXISTS `bahan_baku`;
DROP TABLE IF EXISTS `kategori_menu`;
DROP TABLE IF EXISTS `kategori`;
DROP TABLE IF EXISTS `satuan`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `password_reset_tokens`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `cabang`;
DROP TABLE IF EXISTS `failed_jobs`;
DROP TABLE IF EXISTS `job_batches`;
DROP TABLE IF EXISTS `jobs`;
DROP TABLE IF EXISTS `cache_locks`;
DROP TABLE IF EXISTS `cache`;

-- --------------------------------------------------------
-- Daftar migrations
-- --------------------------------------------------------

CREATE TABLE `migrations` (`id` int unsigned not null auto_increment primary key, `migration` varchar(255) not null, `batch` int not null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '0001_01_01_000001_create_cache_table', 1),
(2, '0001_01_01_000002_create_jobs_table', 1),
(3, '2026_10_21_000001_buat_tabel_cabang', 1),
(4, '2026_10_21_000002_buat_tabel_pengguna', 1),
(5, '2026_10_21_000003_buat_tabel_satuan', 1),
(6, '2026_10_21_000004_buat_tabel_kategori', 1),
(7, '2026_10_21_000005_buat_tabel_kategori_menu', 1),
(8, '2026_10_21_000006_buat_tabel_bahan_baku', 1),
(9, '2026_10_21_000007_buat_tabel_menu', 1),
(10, '2026_10_21_000008_buat_tabel_stok', 1),
(11, '2026_10_21_000009_buat_tabel_permintaan', 1),
(12, '2026_10_21_000010_buat_tabel_kiriman', 1),
(13, '2026_10_21_000011_buat_tabel_barang_masuk', 1),
(14, '2026_10_21_000012_buat_tabel_barang_keluar', 1),
(15, '2026_10_21_000013_buat_tabel_penjualan', 1),
(16, '2026_10_21_000014_buat_tabel_opname', 1),
(17, '2026_10_21_000015_buat_tabel_persetujuan', 1),
(18, '2026_10_21_000016_buat_tabel_notifikasi', 1),
(19, '2026_10_21_000017_buat_tabel_pengaturan', 1),
(20, '2026_10_21_000018_buat_tabel_kontrol_sistem', 1),
(21, '2026_10_21_000019_buat_tabel_log_perubahan', 1),
(22, '2026_10_21_000021_buat_tabel_tambah_stok', 1),
(23, '2026_10_23_000001_buat_tabel_penyesuaian_stok', 1),
(24, '2026_10_27_000001_hapus_fitur_kedaluwarsa', 1),
(25, '2026_10_29_000001_tambah_sebab_kedaluwarsa', 1),
(26, '2026_10_29_000002_hapus_impor_stok_awal', 1),
(27, '2026_10_30_000001_hapus_standar_stok_lokasi', 1),
(28, '2026_10_31_000001_hapus_prioritas_permintaan', 1),
(29, '2026_10_31_000002_hapus_tanggal_dibutuhkan_permintaan', 1),
(30, '2026_10_31_000003_buat_tabel_periode_buku', 1),
(31, '2026_11_01_000001_tambah_dihapus_pada_data_master', 1),
(32, '2026_11_01_000002_tambah_pengaturan_jam_ganti_hari_penjualan', 1),
(33, '2026_11_05_000001_tambah_nama_lain_bahan_baku', 1),
(34, '2026_11_06_000001_ganti_sebab_rusak_jadi_alasan', 1),
(35, '2026_11_09_000001_buat_tabel_email_laporan', 1),
(36, '2026_11_10_000001_tambah_foto_pengguna', 1);

-- --------------------------------------------------------
-- Tabel `cache`
-- --------------------------------------------------------

create table `cache` (`key` varchar(255) not null, `value` mediumtext not null, `expiration` int not null, primary key (`key`)) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `cache` add index `cache_expiration_index`(`expiration`);

-- --------------------------------------------------------
-- Tabel `cache_locks`
-- --------------------------------------------------------

create table `cache_locks` (`key` varchar(255) not null, `owner` varchar(255) not null, `expiration` int not null, primary key (`key`)) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `cache_locks` add index `cache_locks_expiration_index`(`expiration`);

-- --------------------------------------------------------
-- Tabel `jobs`
-- --------------------------------------------------------

create table `jobs` (`id` bigint unsigned not null auto_increment primary key, `queue` varchar(255) not null, `payload` longtext not null, `attempts` tinyint unsigned not null, `reserved_at` int unsigned null, `available_at` int unsigned not null, `created_at` int unsigned not null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `jobs` add index `jobs_queue_index`(`queue`);

-- --------------------------------------------------------
-- Tabel `job_batches`
-- --------------------------------------------------------

create table `job_batches` (`id` varchar(255) not null, `name` varchar(255) not null, `total_jobs` int not null, `pending_jobs` int not null, `failed_jobs` int not null, `failed_job_ids` longtext not null, `options` mediumtext null, `cancelled_at` int null, `created_at` int not null, `finished_at` int null, primary key (`id`)) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';

-- --------------------------------------------------------
-- Tabel `failed_jobs`
-- --------------------------------------------------------

create table `failed_jobs` (`id` bigint unsigned not null auto_increment primary key, `uuid` varchar(255) not null, `connection` text not null, `queue` text not null, `payload` longtext not null, `exception` longtext not null, `failed_at` timestamp not null default CURRENT_TIMESTAMP) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `failed_jobs` add unique `failed_jobs_uuid_unique`(`uuid`);

-- --------------------------------------------------------
-- Tabel `cabang`
-- --------------------------------------------------------

create table `cabang` (`id` bigint unsigned not null auto_increment primary key, `kode` varchar(10) not null, `nama` varchar(100) not null, `jenis` enum('pusat', 'cabang') not null, `alamat` varchar(160) null, `opname_aktif` tinyint(1) not null default '0', `aktif` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `cabang` add `dihapus_pada` datetime null;
alter table `cabang` add index `cabang_dihapus_pada_index`(`dihapus_pada`);
alter table `cabang` add unique `cabang_kode_unique`(`kode`);
alter table `cabang` add unique `cabang_nama_unique`(`nama`);

-- --------------------------------------------------------
-- Tabel `users`
-- --------------------------------------------------------

create table `users` (`id` bigint unsigned not null auto_increment primary key, `nama` varchar(255) not null, `email` varchar(255) not null, `password` varchar(255) not null, `role` enum('admin_utama', 'admin_pusat', 'admin_cabang') not null, `cabang_id` bigint unsigned null, `aktif` tinyint(1) not null default '1', `remember_token` varchar(100) null, `created_at` timestamp null, `updated_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `users` add `dihapus_pada` datetime null;
alter table `users` add index `users_dihapus_pada_index`(`dihapus_pada`);
alter table `users` add constraint `users_cabang_id_foreign` foreign key (`cabang_id`) references `cabang` (`id`) on delete restrict;
alter table `users` add unique `users_email_unique`(`email`);
alter table `users` add `foto` varchar(255) null after `nama`;

-- --------------------------------------------------------
-- Tabel `password_reset_tokens`
-- --------------------------------------------------------

create table `password_reset_tokens` (`email` varchar(255) not null, `token` varchar(255) not null, `created_at` timestamp null, primary key (`email`)) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';

-- --------------------------------------------------------
-- Tabel `sessions`
-- --------------------------------------------------------

create table `sessions` (`id` varchar(255) not null, `user_id` bigint unsigned null, `ip_address` varchar(45) null, `user_agent` text null, `payload` longtext not null, `last_activity` int not null, primary key (`id`)) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `sessions` add index `sessions_user_id_index`(`user_id`);
alter table `sessions` add index `sessions_last_activity_index`(`last_activity`);

-- --------------------------------------------------------
-- Tabel `satuan`
-- --------------------------------------------------------

create table `satuan` (`id` bigint unsigned not null auto_increment primary key, `nama` varchar(30) not null, `aktif` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `satuan` add `dihapus_pada` datetime null;
alter table `satuan` add index `satuan_dihapus_pada_index`(`dihapus_pada`);
alter table `satuan` add unique `satuan_nama_unique`(`nama`);

-- --------------------------------------------------------
-- Tabel `kategori`
-- --------------------------------------------------------

create table `kategori` (`id` bigint unsigned not null auto_increment primary key, `nama` varchar(60) not null, `urutan` smallint unsigned not null default '0', `aktif` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `kategori` add `dihapus_pada` datetime null;
alter table `kategori` add index `kategori_dihapus_pada_index`(`dihapus_pada`);
alter table `kategori` add unique `kategori_nama_unique`(`nama`);

-- --------------------------------------------------------
-- Tabel `kategori_menu`
-- --------------------------------------------------------

create table `kategori_menu` (`id` bigint unsigned not null auto_increment primary key, `nama` varchar(60) not null, `urutan` smallint unsigned not null default '0', `aktif` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `kategori_menu` add `dihapus_pada` datetime null;
alter table `kategori_menu` add index `kategori_menu_dihapus_pada_index`(`dihapus_pada`);
alter table `kategori_menu` add unique `kategori_menu_nama_unique`(`nama`);

-- --------------------------------------------------------
-- Tabel `bahan_baku`
-- --------------------------------------------------------

create table `bahan_baku` (`id` bigint unsigned not null auto_increment primary key, `nama` varchar(100) not null, `nama_lain` varchar(500) null, `foto` varchar(255) null, `kategori_id` bigint unsigned not null, `satuan_dasar_id` bigint unsigned not null, `satuan_beli_id` bigint unsigned not null, `isi_per_satuan_beli` decimal(12, 3) not null, `toleransi_persen` decimal(5, 2) not null default '0', `aktif` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `bahan_baku` add `dihapus_pada` datetime null;
alter table `bahan_baku` add index `bahan_baku_dihapus_pada_index`(`dihapus_pada`);
alter table `bahan_baku` add constraint `bahan_baku_kategori_id_foreign` foreign key (`kategori_id`) references `kategori` (`id`) on delete restrict;
alter table `bahan_baku` add constraint `bahan_baku_satuan_dasar_id_foreign` foreign key (`satuan_dasar_id`) references `satuan` (`id`) on delete restrict;
alter table `bahan_baku` add constraint `bahan_baku_satuan_beli_id_foreign` foreign key (`satuan_beli_id`) references `satuan` (`id`) on delete restrict;
alter table `bahan_baku` add unique `bahan_baku_nama_unique`(`nama`);

-- --------------------------------------------------------
-- Tabel `bahan_baku_minimum`
-- --------------------------------------------------------

create table `bahan_baku_minimum` (`id` bigint unsigned not null auto_increment primary key, `bahan_baku_id` bigint unsigned not null, `cabang_id` bigint unsigned not null, `minimum` decimal(14, 3) not null default '0', `created_at` timestamp null, `updated_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `bahan_baku_minimum` add constraint `bahan_baku_minimum_bahan_baku_id_foreign` foreign key (`bahan_baku_id`) references `bahan_baku` (`id`) on delete restrict;
alter table `bahan_baku_minimum` add constraint `bahan_baku_minimum_cabang_id_foreign` foreign key (`cabang_id`) references `cabang` (`id`) on delete restrict;
alter table `bahan_baku_minimum` add unique `bahan_baku_minimum_bahan_baku_id_cabang_id_unique`(`bahan_baku_id`, `cabang_id`);

-- --------------------------------------------------------
-- Tabel `menu`
-- --------------------------------------------------------

create table `menu` (`id` bigint unsigned not null auto_increment primary key, `nama` varchar(100) not null, `foto` varchar(255) null, `kategori_menu_id` bigint unsigned not null, `aktif` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `menu` add `dihapus_pada` datetime null;
alter table `menu` add index `menu_dihapus_pada_index`(`dihapus_pada`);
alter table `menu` add constraint `menu_kategori_menu_id_foreign` foreign key (`kategori_menu_id`) references `kategori_menu` (`id`) on delete restrict;
alter table `menu` add unique `menu_nama_unique`(`nama`);

-- --------------------------------------------------------
-- Tabel `resep`
-- --------------------------------------------------------

create table `resep` (`id` bigint unsigned not null auto_increment primary key, `menu_id` bigint unsigned not null, `bahan_baku_id` bigint unsigned not null, `jumlah` decimal(14, 3) not null, `catatan` varchar(160) null, `berlaku_sejak` date not null, `created_at` timestamp null, `updated_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `resep` add constraint `resep_menu_id_foreign` foreign key (`menu_id`) references `menu` (`id`) on delete restrict;
alter table `resep` add constraint `resep_bahan_baku_id_foreign` foreign key (`bahan_baku_id`) references `bahan_baku` (`id`) on delete restrict;
alter table `resep` add index `resep_menu_id_berlaku_sejak_index`(`menu_id`, `berlaku_sejak`);

-- --------------------------------------------------------
-- Tabel `stok_mutasi`
-- --------------------------------------------------------

create table `stok_mutasi` (`id` bigint unsigned not null auto_increment primary key, `cabang_id` bigint unsigned not null, `bahan_baku_id` bigint unsigned not null, `jumlah` decimal(14, 3) not null, `jenis` enum('masuk_supplier', 'kirim_keluar', 'kirim_masuk', 'pakai_dapur', 'rusak', 'koreksi_opname', 'stok_awal', 'tambah_langsung', 'penyesuaian') not null, `status` enum('menunggu', 'sah', 'batal') not null default 'menunggu', `dokumen_tipe` varchar(30) not null, `dokumen_id` bigint unsigned not null, `dokumen_detail_id` bigint unsigned null, `dibuat_oleh` bigint unsigned null, `dibuat_pada` timestamp not null default CURRENT_TIMESTAMP) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `stok_mutasi` add constraint `stok_mutasi_cabang_id_foreign` foreign key (`cabang_id`) references `cabang` (`id`) on delete restrict;
alter table `stok_mutasi` add constraint `stok_mutasi_bahan_baku_id_foreign` foreign key (`bahan_baku_id`) references `bahan_baku` (`id`) on delete restrict;
alter table `stok_mutasi` add constraint `stok_mutasi_dibuat_oleh_foreign` foreign key (`dibuat_oleh`) references `users` (`id`) on delete restrict;
alter table `stok_mutasi` add index `stok_mutasi_cabang_id_bahan_baku_id_status_index`(`cabang_id`, `bahan_baku_id`, `status`);
alter table `stok_mutasi` add index `stok_mutasi_dokumen_tipe_dokumen_id_index`(`dokumen_tipe`, `dokumen_id`);

-- --------------------------------------------------------
-- Tabel `stok_saldo`
-- --------------------------------------------------------

create table `stok_saldo` (`cabang_id` bigint unsigned not null, `bahan_baku_id` bigint unsigned not null, `saldo` decimal(14, 3) not null default '0', `diperbarui_pada` timestamp not null default CURRENT_TIMESTAMP, primary key (`cabang_id`, `bahan_baku_id`)) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `stok_saldo` add constraint `stok_saldo_cabang_id_foreign` foreign key (`cabang_id`) references `cabang` (`id`) on delete restrict;
alter table `stok_saldo` add constraint `stok_saldo_bahan_baku_id_foreign` foreign key (`bahan_baku_id`) references `bahan_baku` (`id`) on delete restrict;

-- --------------------------------------------------------
-- Tabel `permintaan`
-- --------------------------------------------------------

create table `permintaan` (`id` bigint unsigned not null auto_increment primary key, `nomor` varchar(30) not null, `cabang_id` bigint unsigned not null, `diajukan_oleh` bigint unsigned null, `diajukan_pada` datetime null, `batas_keputusan` datetime null, `sumber` enum('manual', 'otomatis') not null default 'manual', `status` enum('draf', 'menunggu', 'selesai', 'batal') not null default 'draf', `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `permintaan` add constraint `permintaan_cabang_id_foreign` foreign key (`cabang_id`) references `cabang` (`id`) on delete restrict;
alter table `permintaan` add constraint `permintaan_diajukan_oleh_foreign` foreign key (`diajukan_oleh`) references `users` (`id`) on delete restrict;
alter table `permintaan` add index `permintaan_cabang_id_status_batas_keputusan_index`(`cabang_id`, `status`, `batas_keputusan`);
alter table `permintaan` add unique `permintaan_nomor_unique`(`nomor`);

-- --------------------------------------------------------
-- Tabel `permintaan_detail`
-- --------------------------------------------------------

create table `permintaan_detail` (`id` bigint unsigned not null auto_increment primary key, `permintaan_id` bigint unsigned not null, `bahan_baku_id` bigint unsigned not null, `qty_diminta` decimal(14, 3) not null, `qty_disetujui` decimal(14, 3) null, `qty_dikirim` decimal(14, 3) not null default '0', `sisa_saat_itu` decimal(14, 3) null, `minimum_saat_itu` decimal(14, 3) null, `status` enum('menunggu', 'disetujui', 'ditolak', 'otomatis_ditolak') not null default 'menunggu', `alasan` varchar(160) null, `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `permintaan_detail` add constraint `permintaan_detail_permintaan_id_foreign` foreign key (`permintaan_id`) references `permintaan` (`id`) on delete cascade;
alter table `permintaan_detail` add constraint `permintaan_detail_bahan_baku_id_foreign` foreign key (`bahan_baku_id`) references `bahan_baku` (`id`) on delete restrict;
alter table `permintaan_detail` add index `permintaan_detail_permintaan_id_status_index`(`permintaan_id`, `status`);

-- --------------------------------------------------------
-- Tabel `kiriman`
-- --------------------------------------------------------

create table `kiriman` (`id` bigint unsigned not null auto_increment primary key, `nomor` varchar(30) not null, `dari_cabang_id` bigint unsigned not null, `ke_cabang_id` bigint unsigned not null, `permintaan_id` bigint unsigned null, `dikirim_oleh` bigint unsigned null, `dikirim_pada` datetime null, `sampai_pada` datetime null, `catatan_pusat` text null, `status` enum('disiapkan', 'dikirim', 'dichecklist', 'selesai') not null default 'disiapkan', `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `kiriman` add constraint `kiriman_dari_cabang_id_foreign` foreign key (`dari_cabang_id`) references `cabang` (`id`) on delete restrict;
alter table `kiriman` add constraint `kiriman_ke_cabang_id_foreign` foreign key (`ke_cabang_id`) references `cabang` (`id`) on delete restrict;
alter table `kiriman` add constraint `kiriman_permintaan_id_foreign` foreign key (`permintaan_id`) references `permintaan` (`id`) on delete restrict;
alter table `kiriman` add constraint `kiriman_dikirim_oleh_foreign` foreign key (`dikirim_oleh`) references `users` (`id`) on delete restrict;
alter table `kiriman` add unique `kiriman_nomor_unique`(`nomor`);

-- --------------------------------------------------------
-- Tabel `kiriman_detail`
-- --------------------------------------------------------

create table `kiriman_detail` (`id` bigint unsigned not null auto_increment primary key, `kiriman_id` bigint unsigned not null, `bahan_baku_id` bigint unsigned not null, `qty_kirim` decimal(14, 3) not null, `qty_terima` decimal(14, 3) null, `kondisi` enum('baik', 'rusak', 'tidak_ada') null, `catatan_penerima` varchar(160) null, `dichecklist_oleh` bigint unsigned null, `dichecklist_pada` datetime null, `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `kiriman_detail` add constraint `kiriman_detail_kiriman_id_foreign` foreign key (`kiriman_id`) references `kiriman` (`id`) on delete cascade;
alter table `kiriman_detail` add constraint `kiriman_detail_bahan_baku_id_foreign` foreign key (`bahan_baku_id`) references `bahan_baku` (`id`) on delete restrict;
alter table `kiriman_detail` add constraint `kiriman_detail_dichecklist_oleh_foreign` foreign key (`dichecklist_oleh`) references `users` (`id`) on delete restrict;

-- --------------------------------------------------------
-- Tabel `barang_masuk`
-- --------------------------------------------------------

create table `barang_masuk` (`id` bigint unsigned not null auto_increment primary key, `nomor` varchar(30) not null, `cabang_id` bigint unsigned not null, `tanggal` date not null, `catatan` text null, `dicatat_oleh` bigint unsigned not null, `status` enum('draf', 'menunggu', 'disetujui', 'revisi', 'batal') not null default 'menunggu', `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `barang_masuk` add constraint `barang_masuk_cabang_id_foreign` foreign key (`cabang_id`) references `cabang` (`id`) on delete restrict;
alter table `barang_masuk` add constraint `barang_masuk_dicatat_oleh_foreign` foreign key (`dicatat_oleh`) references `users` (`id`) on delete restrict;
alter table `barang_masuk` add unique `barang_masuk_nomor_unique`(`nomor`);

-- --------------------------------------------------------
-- Tabel `barang_masuk_detail`
-- --------------------------------------------------------

create table `barang_masuk_detail` (`id` bigint unsigned not null auto_increment primary key, `barang_masuk_id` bigint unsigned not null, `bahan_baku_id` bigint unsigned not null, `qty` decimal(14, 3) not null, `keterangan` varchar(160) null, `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `barang_masuk_detail` add constraint `barang_masuk_detail_barang_masuk_id_foreign` foreign key (`barang_masuk_id`) references `barang_masuk` (`id`) on delete cascade;
alter table `barang_masuk_detail` add constraint `barang_masuk_detail_bahan_baku_id_foreign` foreign key (`bahan_baku_id`) references `bahan_baku` (`id`) on delete restrict;

-- --------------------------------------------------------
-- Tabel `barang_keluar`
-- --------------------------------------------------------

create table `barang_keluar` (`id` bigint unsigned not null auto_increment primary key, `nomor` varchar(30) not null, `cabang_id` bigint unsigned not null, `tanggal` date not null, `periode` enum('sehari', 'siang', 'malam') not null default 'sehari', `jenis` enum('pemakaian', 'rusak') not null, `dicatat_oleh` bigint unsigned null, `status` enum('draf', 'menunggu', 'disetujui', 'revisi', 'batal') not null default 'menunggu', `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `barang_keluar` add constraint `barang_keluar_cabang_id_foreign` foreign key (`cabang_id`) references `cabang` (`id`) on delete restrict;
alter table `barang_keluar` add constraint `barang_keluar_dicatat_oleh_foreign` foreign key (`dicatat_oleh`) references `users` (`id`) on delete restrict;
alter table `barang_keluar` add unique `barang_keluar_nomor_unique`(`nomor`);

-- --------------------------------------------------------
-- Tabel `barang_keluar_detail`
-- --------------------------------------------------------

create table `barang_keluar_detail` (`id` bigint unsigned not null auto_increment primary key, `barang_keluar_id` bigint unsigned not null, `bahan_baku_id` bigint unsigned not null, `qty` decimal(14, 3) not null, `sebab` enum('dipakai', 'rusak', 'kedaluwarsa', 'tumpah', 'retur') not null, `penjelasan` varchar(160) null, `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `barang_keluar_detail` add constraint `barang_keluar_detail_barang_keluar_id_foreign` foreign key (`barang_keluar_id`) references `barang_keluar` (`id`) on delete cascade;
alter table `barang_keluar_detail` add constraint `barang_keluar_detail_bahan_baku_id_foreign` foreign key (`bahan_baku_id`) references `bahan_baku` (`id`) on delete restrict;

-- --------------------------------------------------------
-- Tabel `penjualan_harian`
-- --------------------------------------------------------

create table `penjualan_harian` (`id` bigint unsigned not null auto_increment primary key, `cabang_id` bigint unsigned not null, `tanggal` date not null, `barang_keluar_id` bigint unsigned null, `dicatat_oleh` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `penjualan_harian` add constraint `penjualan_harian_cabang_id_foreign` foreign key (`cabang_id`) references `cabang` (`id`) on delete restrict;
alter table `penjualan_harian` add constraint `penjualan_harian_barang_keluar_id_foreign` foreign key (`barang_keluar_id`) references `barang_keluar` (`id`) on delete restrict;
alter table `penjualan_harian` add constraint `penjualan_harian_dicatat_oleh_foreign` foreign key (`dicatat_oleh`) references `users` (`id`) on delete restrict;
alter table `penjualan_harian` add index `penjualan_harian_cabang_id_tanggal_index`(`cabang_id`, `tanggal`);

-- --------------------------------------------------------
-- Tabel `penjualan_detail`
-- --------------------------------------------------------

create table `penjualan_detail` (`id` bigint unsigned not null auto_increment primary key, `penjualan_harian_id` bigint unsigned not null, `menu_id` bigint unsigned not null, `porsi` int unsigned not null, `takaran_resep_json` json not null, `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `penjualan_detail` add constraint `penjualan_detail_penjualan_harian_id_foreign` foreign key (`penjualan_harian_id`) references `penjualan_harian` (`id`) on delete cascade;
alter table `penjualan_detail` add constraint `penjualan_detail_menu_id_foreign` foreign key (`menu_id`) references `menu` (`id`) on delete restrict;
alter table `penjualan_detail` add unique `penjualan_detail_penjualan_harian_id_menu_id_unique`(`penjualan_harian_id`, `menu_id`);

-- --------------------------------------------------------
-- Tabel `opname`
-- --------------------------------------------------------

create table `opname` (`id` bigint unsigned not null auto_increment primary key, `nomor` varchar(30) not null, `cabang_id` bigint unsigned not null, `tanggal` date not null, `petugas_id` bigint unsigned not null, `status` enum('berjalan', 'dikirim', 'disetujui', 'batal') not null default 'berjalan', `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `opname` add constraint `opname_cabang_id_foreign` foreign key (`cabang_id`) references `cabang` (`id`) on delete restrict;
alter table `opname` add constraint `opname_petugas_id_foreign` foreign key (`petugas_id`) references `users` (`id`) on delete restrict;
alter table `opname` add unique `opname_nomor_unique`(`nomor`);

-- --------------------------------------------------------
-- Tabel `opname_detail`
-- --------------------------------------------------------

create table `opname_detail` (`id` bigint unsigned not null auto_increment primary key, `opname_id` bigint unsigned not null, `bahan_baku_id` bigint unsigned not null, `qty_fisik` decimal(14, 3) null, `qty_sistem` decimal(14, 3) null, `selisih` decimal(14, 3) as (qty_fisik - qty_sistem) stored, `catatan` varchar(160) null, `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `opname_detail` add constraint `opname_detail_opname_id_foreign` foreign key (`opname_id`) references `opname` (`id`) on delete cascade;
alter table `opname_detail` add constraint `opname_detail_bahan_baku_id_foreign` foreign key (`bahan_baku_id`) references `bahan_baku` (`id`) on delete restrict;
alter table `opname_detail` add unique `opname_detail_opname_id_bahan_baku_id_unique`(`opname_id`, `bahan_baku_id`);

-- --------------------------------------------------------
-- Tabel `persetujuan`
-- --------------------------------------------------------

create table `persetujuan` (`id` bigint unsigned not null auto_increment primary key, `dokumen_tipe` enum('permintaan', 'barang_masuk', 'barang_keluar', 'kiriman', 'opname') not null, `dokumen_id` bigint unsigned not null, `dokumen_detail_id` bigint unsigned null, `keputusan` enum('disetujui', 'ditolak', 'otomatis_ditolak', 'revisi') not null, `qty_disetujui` decimal(14, 3) null, `catatan` varchar(160) null, `oleh_user_id` bigint unsigned null, `pada` timestamp not null default CURRENT_TIMESTAMP) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `persetujuan` add constraint `persetujuan_oleh_user_id_foreign` foreign key (`oleh_user_id`) references `users` (`id`) on delete restrict;
alter table `persetujuan` add index `persetujuan_dokumen_tipe_dokumen_id_index`(`dokumen_tipe`, `dokumen_id`);

-- --------------------------------------------------------
-- Tabel `notifikasi`
-- --------------------------------------------------------

create table `notifikasi` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned not null, `judul` varchar(100) not null, `keterangan` varchar(255) null, `tautan` varchar(255) null, `dibaca_pada` timestamp null, `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `notifikasi` add constraint `notifikasi_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade;
alter table `notifikasi` add index `notifikasi_user_id_dibaca_pada_index`(`user_id`, `dibaca_pada`);

-- --------------------------------------------------------
-- Tabel `pengaturan`
-- --------------------------------------------------------

create table `pengaturan` (`id` bigint unsigned not null auto_increment primary key, `kunci` varchar(50) not null, `label` varchar(100) null, `nilai` varchar(255) not null, `tipe` varchar(20) not null default 'teks', `satuan` varchar(20) null, `nilai_min` decimal(12, 2) null, `nilai_maks` decimal(12, 2) null, `keterangan` varchar(160) null, `urutan` smallint unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `pengaturan` add unique `pengaturan_kunci_unique`(`kunci`);

-- --------------------------------------------------------
-- Tabel `kontrol_sistem`
-- --------------------------------------------------------

create table `kontrol_sistem` (`id` bigint unsigned not null auto_increment primary key, `kunci` varchar(60) not null, `nilai` text null, `diubah_oleh` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `kontrol_sistem` add constraint `kontrol_sistem_diubah_oleh_foreign` foreign key (`diubah_oleh`) references `users` (`id`);
alter table `kontrol_sistem` add unique `kontrol_sistem_kunci_unique`(`kunci`);

-- --------------------------------------------------------
-- Tabel `log_perubahan`
-- --------------------------------------------------------

create table `log_perubahan` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned null, `kategori` varchar(30) not null, `aksi` varchar(60) not null, `ringkasan` varchar(255) not null, `rincian` json null, `objek_tipe` varchar(60) null, `objek_id` bigint unsigned null, `ip` varchar(45) null, `created_at` timestamp not null default CURRENT_TIMESTAMP) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `log_perubahan` add constraint `log_perubahan_user_id_foreign` foreign key (`user_id`) references `users` (`id`);
alter table `log_perubahan` add index `log_perubahan_kategori_created_at_index`(`kategori`, `created_at`);
alter table `log_perubahan` add index `log_perubahan_created_at_index`(`created_at`);

-- --------------------------------------------------------
-- Tabel `tambah_stok`
-- --------------------------------------------------------

create table `tambah_stok` (`id` bigint unsigned not null auto_increment primary key, `nomor` varchar(30) not null, `cabang_id` bigint unsigned not null, `bahan_baku_id` bigint unsigned not null, `jumlah` decimal(14, 3) not null, `keterangan` varchar(160) null, `dibuat_oleh` bigint unsigned not null, `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `tambah_stok` add constraint `tambah_stok_cabang_id_foreign` foreign key (`cabang_id`) references `cabang` (`id`) on delete restrict;
alter table `tambah_stok` add constraint `tambah_stok_bahan_baku_id_foreign` foreign key (`bahan_baku_id`) references `bahan_baku` (`id`) on delete restrict;
alter table `tambah_stok` add constraint `tambah_stok_dibuat_oleh_foreign` foreign key (`dibuat_oleh`) references `users` (`id`) on delete restrict;
alter table `tambah_stok` add index `tambah_stok_cabang_id_created_at_index`(`cabang_id`, `created_at`);
alter table `tambah_stok` add unique `tambah_stok_nomor_unique`(`nomor`);

-- --------------------------------------------------------
-- Tabel `penyesuaian_stok`
-- --------------------------------------------------------

create table `penyesuaian_stok` (`id` bigint unsigned not null auto_increment primary key, `nomor` varchar(30) not null, `cabang_id` bigint unsigned not null, `bahan_baku_id` bigint unsigned not null, `stok_lama` decimal(14, 3) not null, `stok_baru` decimal(14, 3) not null, `selisih` decimal(14, 3) not null, `keterangan` varchar(160) null, `dibuat_oleh` bigint unsigned not null, `created_at` timestamp null, `updated_at` timestamp null, `deleted_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `penyesuaian_stok` add constraint `penyesuaian_stok_cabang_id_foreign` foreign key (`cabang_id`) references `cabang` (`id`) on delete restrict;
alter table `penyesuaian_stok` add constraint `penyesuaian_stok_bahan_baku_id_foreign` foreign key (`bahan_baku_id`) references `bahan_baku` (`id`) on delete restrict;
alter table `penyesuaian_stok` add constraint `penyesuaian_stok_dibuat_oleh_foreign` foreign key (`dibuat_oleh`) references `users` (`id`) on delete restrict;
alter table `penyesuaian_stok` add index `penyesuaian_stok_cabang_id_created_at_index`(`cabang_id`, `created_at`);
alter table `penyesuaian_stok` add unique `penyesuaian_stok_nomor_unique`(`nomor`);

-- --------------------------------------------------------
-- Tabel `periode_buku`
-- --------------------------------------------------------

create table `periode_buku` (`id` bigint unsigned not null auto_increment primary key, `nama` varchar(60) not null, `mulai` datetime null, `selesai` datetime not null, `berkas_excel` varchar(120) null, `berkas_cadangan` varchar(120) null, `ringkasan` json null, `penjualan_terakhir` json null, `ditutup_oleh` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `periode_buku` add constraint `periode_buku_ditutup_oleh_foreign` foreign key (`ditutup_oleh`) references `users` (`id`) on delete set null;

-- --------------------------------------------------------
-- Tabel `email_laporan`
-- --------------------------------------------------------

create table `email_laporan` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned not null, `aktif` tinyint(1) not null default '0', `alamat` json not null, `laporan` json not null, `terakhir_dikirim` datetime null, `created_at` timestamp null, `updated_at` timestamp null) ENGINE=InnoDB default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `email_laporan` add constraint `email_laporan_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade;
alter table `email_laporan` add unique `email_laporan_user_id_unique`(`user_id`);

-- --------------------------------------------------------
-- Pengaturan bawaan (sama dengan PengaturanSeeder)
-- --------------------------------------------------------

INSERT INTO `pengaturan` (`id`, `kunci`, `label`, `nilai`, `tipe`, `satuan`, `nilai_min`, `nilai_maks`, `keterangan`, `urutan`, `created_at`, `updated_at`) VALUES
(1, 'batas_jam_persetujuan', 'Batas waktu persetujuan', '24', 'bilangan', 'jam', 1, 168, 'Permintaan yang belum diputuskan Admin Utama setelah waktu ini otomatis ditolak.', 1, '2026-09-01 08:00:00', '2026-09-01 08:00:00'),
(2, 'jam_permintaan_otomatis', 'Jam permintaan otomatis', '06:00', 'jam', NULL, NULL, NULL, 'Setiap hari pada jam ini sistem membuat permintaan untuk bahan yang stoknya di bawah minimum.', 2, '2026-09-01 08:00:00', '2026-09-01 08:00:00'),
(3, 'toleransi_default', 'Toleransi selisih bawaan', '5', 'persen', '%', 0, 100, 'Nilai awal toleransi selisih saat menambah bahan baku baru.', 3, '2026-09-01 08:00:00', '2026-09-01 08:00:00'),
(4, 'ambang_stok_menipis', 'Ambang stok menipis', '20', 'persen', '%', 0, 1000, 'Stok dianggap menipis bila sisanya tidak lebih dari minimum ditambah persen ini.', 4, '2026-09-01 08:00:00', '2026-09-01 08:00:00'),
(5, 'persen_cadangan_minta', 'Cadangan isi stok', '20', 'persen', '%', 0, 500, 'Isi stok dari halaman Stok dan permintaan otomatis cabang memakai stok minimum ditambah persen ini.', 5, '2026-10-18 08:00:00', '2026-10-18 08:00:00'),
(6, 'jam_ganti_hari_penjualan', 'Jam Ganti Hari Penjualan', '06:00', 'jam', NULL, NULL, NULL, 'Sebelum jam ini tanggal penjualan masih dianggap kemarin, karena penjualan dicatat setelah closing tengah malam.', 3, '2026-11-01 08:00:00', '2026-11-01 08:00:00');

SET FOREIGN_KEY_CHECKS = 1;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
