<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Delete for me" per-PESAN. Satu baris = satu user menyembunyikan satu
     * pesan HANYA dari dirinya sendiri; baris pesan aslinya tetap utuh di DB
     * untuk semua orang lain. Pola sama dengan message_clears, tapi granular
     * per-pesan (bukan per-percakapan).
     */
    public function up(): void
    {
        Schema::create('message_deletes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('message_id')->constrained('messages')->onDelete('cascade');
            $table->timestamps();
            // Satu user tak bisa "delete for me" pesan yang sama dua kali.
            $table->unique(['user_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_deletes');
    }
};
