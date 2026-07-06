<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clans', function (Blueprint $table) {
            $table->string('emblem', 32)->nullable()->after('tag');
            $table->string('emblem_color', 16)->nullable()->after('emblem');
            $table->string('description', 160)->nullable()->after('emblem_color');
        });
    }

    public function down(): void
    {
        Schema::table('clans', function (Blueprint $table) {
            $table->dropColumn(['emblem', 'emblem_color', 'description']);
        });
    }
};
