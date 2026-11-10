<?php

return [

    /*
    | Akun Admin Utama pertama yang dibuat oleh AdminUtamaAwalSeeder.
    | Isi di .env; nama/email kosong dipakai Admin Utama / admin@stokku.test. Password kosong (atau kurang dari 8 karakter)
    | dibuat acak dan ditampilkan sekali saat seeder dijalankan. Ganti password setelah login pertama.
    */
    'admin_utama_awal' => [
        'nama' => env('ADMIN_UTAMA_NAMA') ?: 'Admin Utama',
        'email' => env('ADMIN_UTAMA_EMAIL') ?: 'admin@stokku.test',
        'password' => env('ADMIN_UTAMA_PASSWORD') ?: null,
    ],

    /*
    | Pembaruan otomatis halaman (resources/js/segar.js): setiap sekian detik halaman menanyakan penanda perubahan
    | data dan memuat ulang isinya bila ada yang berubah. 0 = mati.
    */
    'jeda_segar_detik' => (int) env('JEDA_SEGAR_DETIK', 3),

    /*
    | Alamat http:// dialihkan ke https:// dan semua tautan dibuat https. Nyalakan di hosting yang sudah ber-SSL.
    */
    'paksa_https' => (bool) env('PAKSA_HTTPS', false),

];
