<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pisahkan "tidak menyelesaikan balapan" dari "durasi tempuh".
     *
     * Dulu keduanya ditumpuk di satu kolom: pemain DNF disimpan dengan
     * finished_time_seconds = 999 (nilai sentinel dari RoomMember). Angka itu
     * lalu ikut tertulis ke riwayat permanen ini sebagai kalau-kalau durasi
     * sungguhan, sehingga statistik apa pun yang merata-ratakan waktu finish
     * akan tercemar -- dan sorting-nya cuma "kebetulan" benar selama tak ada
     * balapan yang berlangsung lebih dari 999 detik.
     *
     * Sekarang: dnf = true, finished_time_seconds = null (SQL AVG/MAX otomatis
     * mengabaikan NULL, jadi statistik ikut bersih tanpa filter tambahan).
     */
    public function up(): void
    {
        Schema::table('multiplayer_match_history', function (Blueprint $table) {
            $table->boolean('dnf')->default(false)->after('finished_time_seconds');
        });

        // Bersihkan baris lama yang sudah terlanjur menyimpan sentinel sebagai durasi.
        DB::table('multiplayer_match_history')
            ->where('finished_time_seconds', 999)
            ->update(['dnf' => true, 'finished_time_seconds' => null]);
    }

    public function down(): void
    {
        // Kembalikan sentinel supaya kolomnya konsisten dengan skema lama.
        DB::table('multiplayer_match_history')
            ->where('dnf', true)
            ->update(['finished_time_seconds' => 999]);

        Schema::table('multiplayer_match_history', function (Blueprint $table) {
            $table->dropColumn('dnf');
        });
    }
};
