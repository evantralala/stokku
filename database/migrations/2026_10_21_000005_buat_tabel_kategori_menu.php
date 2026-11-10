<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kategori menu, mis. ramen, minuman. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kategori_menu', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 60)->unique();
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kategori_menu');
    }
};
