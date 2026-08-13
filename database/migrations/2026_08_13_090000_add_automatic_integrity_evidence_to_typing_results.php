<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('typing_results', function (Blueprint $table) {
            $table->char('session_fingerprint', 64)->nullable()->unique()->after('review_reason');
            $table->json('integrity_meta')->nullable()->after('session_fingerprint');
            $table->timestamp('review_resolved_at')->nullable()->after('integrity_meta');

            $table->index(
                ['user_id', 'mode', 'mode_config', 'language', 'review_status', 'id'],
                'typing_results_integrity_baseline_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('typing_results', function (Blueprint $table) {
            $table->dropIndex('typing_results_integrity_baseline_index');
            $table->dropUnique(['session_fingerprint']);
            $table->dropColumn(['session_fingerprint', 'integrity_meta', 'review_resolved_at']);
        });
    }
};
