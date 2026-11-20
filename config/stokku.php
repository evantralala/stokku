<?php

return [

    /*
    | Akun Admin Utama pertama yang dibuat oleh AdminUtamaAwalSeeder.
    | Isi di .env; nama kosong dipakai Admin Utama. Username kosong diambil dari bagian depan ADMIN_UTAMA_EMAIL (isian lama),
    | lalu admin. Password kosong (atau kurang dari 8 karakter) dibuat acak dan ditampilkan sekali saat seeder dijalankan.
    */
    'admin_utama_awal' => [
        'nama' => env('ADMIN_UTAMA_NAMA') ?: 'Admin Utama',
        'username' => env('ADMIN_UTAMA_USERNAME') ?: Illuminate\Support\Str::before((string) env('ADMIN_UTAMA_EMAIL'), '@'),
        'password' => env('ADMIN_UTAMA_PASSWORD') ?: null,
    ],

    /*
    | Pembaruan otomatis halaman (resources/js/segar.js): setiap sekian detik halaman menanyakan penanda perubahan
    | data dan memuat ulang isinya bila ada yang berubah. 0 = mati.
    */
    'jeda_segar_detik' => (int) env('JEDA_SEGAR_DETIK', 10),

    /*
    | Alamat http:// dialihkan ke https:// dan semua tautan dibuat https. Nyalakan di hosting yang sudah ber-SSL.
    */
    'paksa_https' => (bool) env('PAKSA_HTTPS', false),

    /*
    | Notifikasi HP (Web Push). Kunci dibuat sekali dengan: php artisan push:buat-kunci (langsung ditulis ke .env).
    | Kosong = notifikasi HP mati, notifikasi di lonceng tetap jalan. Subjek: email atau alamat situs pengelola.
    */
    'push' => [
        'publik' => env('VAPID_PUBLIC_KEY'),
        'privat' => env('VAPID_PRIVATE_KEY'),
        'subjek' => env('VAPID_SUBJECT') ?: 'mailto:admin@stokku.test',
    ],

];
