<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabel Utama Ruangan (Lobby)
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // Kode unik seperti 'A3F9K2'
            $table->foreignId('host_id')->constrained('users')->onDelete('cascade');
            $table->string('status')->default('waiting'); // waiting, racing, finished
            $table->text('text_to_type'); // Teks paragraf balapan yang sama untuk semua pemain
            $table->timestamp('countdown_started_at')->nullable(); // Untuk mencatat kapan sudden death 15s dimulai
            $table->timestamps();
        });

        // 2. Tabel Pivot Anggota di Dalam Ruangan
        Schema::create('room_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->boolean('is_ready')->default(false); // Status ready untuk participant
            $table->integer('progress_percent')->default(0); // Progress ketikan 0-100% secara live
            $table->integer('wpm')->default(0);
            $table->decimal('accuracy', 5, 2)->default(0.00);
            $table->integer('finished_time_seconds')->nullable(); // Waktu tempuh jika berhasil finish
            $table->integer('place')->nullable(); // Juara 1, 2, 3, dst.
            $table->timestamps();

            $table->unique(['room_id', 'user_id']); // Memastikan 1 user tidak masuk ganda di room yang sama
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_members');
        Schema::dropIfExists('rooms');
    }
};