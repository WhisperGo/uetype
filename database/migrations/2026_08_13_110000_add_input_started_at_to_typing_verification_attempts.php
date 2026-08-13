<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('typing_verification_attempts', function (Blueprint $table) {
            $table->timestamp('input_started_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('typing_verification_attempts', function (Blueprint $table) {
            $table->dropColumn('input_started_at');
        });
    }
};
