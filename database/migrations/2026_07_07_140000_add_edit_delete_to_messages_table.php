<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Filled when a message is edited -> the UI shows an "(edited)" label.
            $table->timestamp('edited_at')->nullable()->after('body');
            // Filled on "delete for everyone" -> the body is replaced with a placeholder for
            // EVERYONE, but the row stays (a trace that the message once existed).
            $table->timestamp('deleted_for_everyone_at')->nullable()->after('edited_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['edited_at', 'deleted_for_everyone_at']);
        });
    }
};
