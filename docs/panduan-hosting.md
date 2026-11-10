# Panduan pasang di Hostinger (hPanel)

Untuk shared hosting Hostinger: hPanel, File Manager, phpMyAdmin, dan Cron Jobs (tanpa SSH pun bisa).
Semua menu di bawah ada di hPanel → **Websites** → **Manage** (dashboard situs); cari nama menunya di kolom pencarian samping.
Semua langkah dikerjakan sekali; pembaruan berikutnya cukup bagian **Memperbarui aplikasi**.

## 0. Syarat hosting

- PHP **8.2** atau lebih baru (hPanel → **PHP Configuration**).
- Ekstensi PHP aktif: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `zip`, `gd`, `xml`, `ctype`, `bcmath`.
- MySQL / MariaDB dan menu **Cron Jobs**.
- **SSL / HTTPS** aktif untuk domain (hPanel → **SSL**, sertifikat gratis). Wajib supaya aplikasi bisa dipasang
  di HP (PWA). Tidak perlu VPS: pembaruan otomatis halaman cukup dengan shared hosting biasa.

## 1. Buat paket di laptop (Windows)

```cmd
cd C:\xampp\htdocs\Stokku
deploy\buat-paket.bat
```

Hasilnya `deploy\stokku-rilis.zip`, sudah berisi aset hasil `npm run build` dan folder `vendor`
(paket PHP produksi), jadi server tidak perlu Node.js maupun Composer.

## 2. Buat database

hPanel → **Databases** → **Management**:
1. Isi nama database dan user, mis. `stokku`. Hostinger otomatis menambah awalan akun, jadi namanya menjadi
   `u123456789_stokku` (catat nama lengkap ini untuk `.env`).
2. Isi sandi kuat untuk user database, lalu **Create**. User itu langsung punya akses penuh ke databasenya.

## 3. Unggah dan ekstrak

Struktur yang dipakai (folder aplikasi di luar `public_html`, jadi `.env` dan data tidak bisa dibuka dari web):

```
/home/u123456789/domains/domain-anda.com/
├── stokku/          ← isi stokku-rilis.zip
└── public_html/       ← isi folder stokku/public
```

1. hPanel → **File Manager** → buka folder `domains/domain-anda.com`, buat folder `stokku`, unggah `stokku-rilis.zip` ke situ, lalu **Extract**.
2. Salin **seluruh isi** `stokku/public/` (termasuk `.htaccess` dan folder `build`) ke `public_html/`.
3. Timpa `public_html/index.php` dengan `stokku/deploy/public_html/index.php`.
   Bila nama folder aplikasi bukan `stokku`, ubah baris `$aplikasi` di file itu.
4. Salin `stokku/deploy/htaccess-folder-aplikasi` menjadi `stokku/.htaccess` (pengaman tambahan).


## 4. Isi `.env`

1. Salin `stokku/.env.production.example` menjadi `stokku/.env`.
2. Isi `APP_URL`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
3. Isi `APP_KEY`: jalankan `php artisan key:generate --show` di laptop, salin hasilnya (`base64:...`).
4. Biarkan `APP_DEBUG=false`. `SESSION_SECURE_COOKIE=true` dan `PAKSA_HTTPS=true` hanya untuk alamat **https**; ubah
   keduanya ke `false` bila belum ada SSL. Nyalakan juga hPanel → **SSL** → **Force HTTPS**.
5. Isi `ADMIN_UTAMA_EMAIL` dan `ADMIN_UTAMA_PASSWORD` (sandi kuat, minimal 8 karakter) untuk akun Admin Utama pertama.
   Tidak ada sandi bawaan.

## 5. Isi database

Pilih salah satu:

- **Dari nol:** lewat SSH (hPanel → **SSH Access**, tersedia di paket tertentu) di folder `stokku`:
  ```bash
  php artisan migrate --force
  php artisan db:seed --force
  ```
  Isi `ADMIN_UTAMA_*` di `.env` sebelum `db:seed`. Bila `ADMIN_UTAMA_PASSWORD` kosong, sandi acak dibuat dan
  ditampilkan sekali di terminal. Segera ganti password setelah login pertama.
- **Tanpa terminal:** phpMyAdmin → pilih database → **Import** → `database_stok.sql`
  (atau file hasil **Export** phpMyAdmin dari server lama). Lalu buka halaman masuk: bila belum ada Admin Utama,
  akun dibuat otomatis dari `ADMIN_UTAMA_EMAIL` dan `ADMIN_UTAMA_PASSWORD` di `.env`.

## 6. Pasang cron (wajib)

hPanel → **Cron Jobs** → **Type: Custom**, jadwal **setiap menit** (`* * * * *`), perintah:

```
/usr/bin/php /home/u123456789/domains/domain-anda.com/stokku/artisan schedule:run >> /dev/null 2>&1
```

Ganti `u123456789` dan `domain-anda.com` sesuai akun (terlihat di jalur File Manager). Jam cron Hostinger memakai
UTC, tetapi tidak berpengaruh karena aplikasi memeriksa setiap menit dan memakai jam Asia/Jakarta sendiri.
Cron ini menjalankan Permintaan Otomatis, Tolak Otomatis, dan email laporan harian (23.59, susulan 00.10). Tanpa
cron ketiganya tidak berjalan; fitur lain tetap normal. Untuk mengecek: `php artisan permintaan:buat-otomatis --paksa` lalu lihat menu Persetujuan.

## Email laporan harian

Butuh satu mailbox pengirim, mis. hPanel → **Email** → domain → **Tambah mailbox** `laporan@domain-anda.com`.
Isi di `.env` server (password mailbox diapit kutip satu), lalu `php artisan config:clear` dan `php artisan optimize`:

```
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_USERNAME=laporan@domain-anda.com
MAIL_PASSWORD='password-mailbox'
MAIL_FROM_ADDRESS="laporan@domain-anda.com"
```

Tiap pengguna menyalakan dan mengatur tujuan emailnya sendiri di menu **Akun**; tombol **Kirim Sekarang** di sana
untuk mencoba. Bila gagal, lihat `storage/logs`. Kirim manual semua akun: `php artisan laporan:kirim-harian --ulang`.

## 7. Cek akhir

- Buka alamat aplikasi, masuk sebagai Admin Utama.
- phpMyAdmin → database → **Export** → pastikan file `.sql` terunduh (cadangan pertama).
- Catat barang rusak dengan foto, pastikan foto tampil di halaman detail.
  Foto dibaca lewat aplikasi, jadi **tidak perlu** `php artisan storage:link`.
- Optimasi (bila ada SSH): `php artisan optimize`.

## Isi data awal lewat Excel

Untuk database dari nol (`db:seed` hanya membuat akun Admin Utama):

1. Isi data master yang sedikit lewat menu biasa: **Lokasi** (Gudang Pusat + cabang).
2. **Bahan baku → Impor**: unduh templat, isi satu baris satu bahan, unggah, cek pratinjau, simpan.
   Kategori dan satuan yang belum ada dibuat otomatis.
3. **Stok awal**: buka **Stok**, pilih lokasi, tekan **Unduh Excel** (berisi semua bahan), isi kolom **Stok** dalam
   satuan beli, lalu **Perbarui Lewat Excel** → pilih **Ganti** → unggah, cek, simpan. Tabel sendiri juga bisa,
   asal ada kolom nama bahan dan stok.
4. Atur **Stok minimum** dan **Menu & resep**.

## Cadangan

- Tidak ada cadangan otomatis di aplikasi. Pakai fitur backup hosting (hPanel → **Backups**) atau
  phpMyAdmin → **Export** secara rutin, simpan di luar server (Google Drive / laptop).
- Kode aplikasi tersimpan di ZIP rilis / git.
- Memulihkan: phpMyAdmin → database kosong → **Import** file `.sql`.

## Memperbarui aplikasi

1. Di laptop: `deploy\buat-paket.bat`.
2. Di server: beri tahu pengguna agar berhenti mencatat sebentar, lalu Export database lewat phpMyAdmin.
3. Unggah `stokku-rilis.zip` ke folder `stokku/` lewat File Manager (timpa file lama).
4. SSH (folder aplikasi `stokku/`):

   ```bash
   php artisan down
   unzip -o stokku-rilis.zip -x "storage/*" ".env"
   rm -rf ../public_html/build
   cp -r public/build ../public_html/
   php artisan migrate --force
   php artisan optimize
   php artisan up
   rm stokku-rilis.zip
   ```

   `-x "storage/*" ".env"` menjaga `.env` dan data unggahan di server. File yang dihapus dari aplikasi tidak ikut
   terhapus oleh `unzip`; hapus manual sesuai catatan rilis (`rm path/file.php`). Salin juga `index.php` dari
   `deploy/public_html` bila catatan rilis menyebutnya berubah.
   Tanpa terminal: impor file SQL perubahan yang disertakan di catatan rilis.
5. Beri tahu pengguna bahwa aplikasi bisa dipakai lagi.

## Mengosongkan semua data (mulai dari nol)

Menghapus **semua** isi database aplikasi (transaksi, bahan, menu, lokasi, akun, pengaturan) beserta foto, logo,
arsip tutup buku, dan cadangan. Yang tersisa hanya akun Admin Utama dari `ADMIN_UTAMA_*` di `.env`.
Hanya untuk database yang khusus dipakai aplikasi ini. Export database lewat phpMyAdmin dulu.

```bash
php artisan down
grep DB_DATABASE .env
php artisan migrate:fresh --seed --force
rm -rf storage/app/private/cadangan/* storage/app/private/arsip-periode storage/app/public/identitas storage/app/public/barang-keluar
rm -f storage/logs/*.log
php artisan optimize
php artisan up
```

Bila `ADMIN_UTAMA_PASSWORD` di `.env` kosong, `db:seed` menampilkan sandi acak: catat, masuk, lalu ganti di menu
**Akun**. Akun lain (termasuk Admin Utama lain) dibuat lagi lewat menu **Pengguna**, lalu isi data awal lewat Excel.
