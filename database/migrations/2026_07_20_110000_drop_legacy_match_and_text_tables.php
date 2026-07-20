<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buang pulau mati: `matches`, `match_participants`, `texts`, `languages`,
     * `paragraphs`.
     *
     * Kelimanya peninggalan rancangan awal yang digantikan rooms/room_members
     * (balapan), multiplayer_match_history (riwayat), dan TextGeneratorService
     * (teks dari wordlist JSON, bukan dari tabel). Tak satu pun komponen Livewire
     * atau controller menyentuhnya -- mereka cuma saling mereferensi, ditambah dua
     * seeder yang mengisinya untuk tak pernah dibaca.
     *
     * URUTAN DROP TAK BOLEH DIACAK: rantai foreign key-nya
     *     typing_results.text_id -> texts.language_id -> languages
     *     match_participants.match_id -> matches.text_id -> texts
     * jadi anak harus lepas sebelum induknya.
     */
    public function up(): void
    {
        // typing_results HIDUP dan tetap tinggal; hanya jembatannya ke `texts` yang
        // dibuang. Kolom ini selalu null (teks dirakit dari wordlist, bukan dari
        // tabel), jadi tak ada data yang ikut hilang -- tapi selama ia ada, `texts`
        // tak bisa di-drop.
        Schema::table('typing_results', function (Blueprint $table) {
            $table->dropForeign(['text_id']);
            $table->dropColumn('text_id');
        });

        Schema::dropIfExists('match_participants');
        Schema::dropIfExists('matches');
        Schema::dropIfExists('texts');
        Schema::dropIfExists('languages');
        Schema::dropIfExists('paragraphs');
    }

    /**
     * Mengembalikan STRUKTUR, bukan isinya.
     *
     * Ini disebut terang-terangan karena rollback di sini TIDAK memulihkan keadaan
     * semula: baris `texts`/`languages` yang dulu diisi seeder hilang permanen, dan
     * `typing_results.text_id` kembali sebagai kolom kosong. Untuk data yang memang
     * sudah tak dibaca siapa pun ini dapat diterima -- yang tidak dapat diterima
     * adalah `down()` yang diam-diam berpura-pura reversibel.
     */
    public function down(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('texts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained('languages')->onDelete('cascade');
            $table->text('content');
            $table->string('mode', 20);
            $table->string('difficulty', 20);
            $table->string('author')->nullable();
            $table->timestamps();
        });

        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->string('room_code', 10)->unique();
            $table->foreignId('host_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('text_id')->nullable()->constrained('texts')->nullOnDelete();
            $table->text('generated_text')->nullable();
            $table->string('match_type', 10);
            $table->string('status', 15)->default('waiting');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('match_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->decimal('wpm', 6, 2)->nullable();
            $table->decimal('accuracy', 5, 2)->nullable();
            $table->integer('placement')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('paragraphs', function (Blueprint $table) {
            $table->id();
            $table->text('content');
            $table->timestamps();
        });

        Schema::table('typing_results', function (Blueprint $table) {
            $table->foreignId('text_id')->nullable()->after('user_id')->constrained('texts')->nullOnDelete();
        });
    }
};
