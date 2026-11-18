<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Langganan notifikasi HP (Web Push): satu baris per browser/perangkat yang mengizinkan notifikasi. */
    public function up(): void
    {
        Schema::create('langganan_push', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->string('p256dh', 200);
            $table->string('auth', 100);
            $table->string('perangkat', 150)->nullable();
            $table->dateTime('terakhir_dikirim')->nullable();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('langganan_push');
    }
};
