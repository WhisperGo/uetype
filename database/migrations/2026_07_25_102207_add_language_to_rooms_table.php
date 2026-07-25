<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            // Content language of the race text (en|id). Stored on the room so joiners see
            // the host's choice and it survives a host refresh; default 'en' matches
            // TypingLanguage::DEFAULT.
            $table->string('language', 8)->default('en')->after('text_to_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
