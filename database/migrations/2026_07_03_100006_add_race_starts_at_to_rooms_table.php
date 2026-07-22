<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            // Waktu absolut (server) kapan race resmi MULAI (setelah countdown 3 detik).
            // Set in startRace() = now()+3s and broadcast to all clients, so the 3-2-1
            // countdown on every screen is computed from the SAME point (counting down to
            // this time), not from whenever each client happens to render the race. This
            // removes the ~1-second delay between screens at the start of a race.
            $table->timestamp('race_starts_at')->nullable()->after('countdown_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('race_starts_at');
        });
    }
};
