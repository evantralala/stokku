<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Login memakai username, bukan email. Akun lama mendapat username dari bagian depan emailnya
 * (huruf kecil, angka, titik, garis bawah; ditambah nomor bila sama). Email hanya dipakai di pengaturan laporan harian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->after('nama');
        });

        $terpakai = [];

        foreach (DB::table('users')->orderBy('id')->get(['id', 'email']) as $u) {
            $dasar = substr(preg_replace('/[^a-z0-9._]/', '', strtolower(strstr($u->email, '@', true) ?: $u->email)), 0, 24);
            $dasar = strlen($dasar) >= 3 ? $dasar : 'akun'.$u->id;
            $nama = $dasar;

            for ($i = 2; isset($terpakai[$nama]); $i++) {
                $nama = $dasar.$i;
            }

            $terpakai[$nama] = true;
            DB::table('users')->where('id', $u->id)->update(['username' => $nama]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable(false)->change();
            $table->unique('username');
            $table->dropUnique(['email']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->after('nama');
        });

        foreach (DB::table('users')->get(['id', 'username']) as $u) {
            DB::table('users')->where('id', $u->id)->update(['email' => $u->username.'@stokku.test']);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->unique('email');
            $table->dropUnique(['username']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
        });
    }
};
