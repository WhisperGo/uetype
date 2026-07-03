<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            // EXP yang diperoleh pemain dari match ini. Null selama race berlangsung;
            // diisi SEKALI saat finalizeRace() sebagai penanda "sudah diberi EXP"
            // (mencegah double-award) sekaligus nilai untuk ditampilkan di panel hasil.
            $table->integer('xp_earned')->nullable()->after('place');
        });
    }

    public function down(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->dropColumn('xp_earned');
        });
    }
};
