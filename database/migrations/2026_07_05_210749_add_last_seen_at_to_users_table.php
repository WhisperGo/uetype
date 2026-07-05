<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom presence untuk deteksi online/offline. Klien mengirim heartbeat
     * ringan secara berkala yang meng-update kolom ini; user dianggap "online"
     * jika last_seen_at masih dalam ambang (User::ONLINE_THRESHOLD_SECONDS).
     * Model timestamp-based ini tahan crash/tutup-tab: status kedaluwarsa
     * sendiri tanpa perlu event "offline" eksplisit.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('preferences');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
