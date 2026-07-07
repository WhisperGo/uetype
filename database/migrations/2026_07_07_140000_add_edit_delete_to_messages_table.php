<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Diisi saat pesan diedit -> UI menampilkan label "(edited)".
            $table->timestamp('edited_at')->nullable()->after('body');
            // Diisi saat "delete for everyone" -> body diganti placeholder untuk
            // SEMUA orang, tapi baris tetap ada (jejak bahwa pesan pernah ada).
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
