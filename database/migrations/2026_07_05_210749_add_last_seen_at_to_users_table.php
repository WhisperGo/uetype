<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Presence column for online/offline detection. The client periodically sends a light
     * heartbeat that updates this column; a user is considered "online" if last_seen_at is
     * still within the threshold (User::ONLINE_THRESHOLD_SECONDS). This timestamp-based
     * model is crash/tab-close proof: the status expires on its own, no explicit "offline"
     * event needed.
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
