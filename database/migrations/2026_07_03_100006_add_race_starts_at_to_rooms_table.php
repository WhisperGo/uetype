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
            // Di-set saat startRace() = now()+3s dan disiarkan ke semua klien, supaya
            // countdown 3-2-1 di setiap layar dihitung dari titik yang SAMA (mundur ke
            // waktu ini), bukan dari saat masing-masing klien kebetulan me-render race.
            // Ini menghilangkan delay ~1 detik antar-layar pada awal balapan.
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
