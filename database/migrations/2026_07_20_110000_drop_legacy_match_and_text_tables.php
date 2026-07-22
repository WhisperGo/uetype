<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the dead islands: `matches`, `match_participants`, `texts`, `languages`,
     * `paragraphs`.
     *
     * All five are leftovers from the initial design, replaced by rooms/room_members
     * (racing), multiplayer_match_history (history), and TextGeneratorService (text from a
     * JSON wordlist, not from a table). No Livewire component or controller touches them --
     * they only reference each other, plus two seeders that fill them to never be read.
     *
     * THE DROP ORDER MUST NOT BE SHUFFLED: the foreign-key chain is
     *     typing_results.text_id -> texts.language_id -> languages
     *     match_participants.match_id -> matches.text_id -> texts
     * so the child must detach before its parent.
     */
    public function up(): void
    {
        // typing_results is ALIVE and stays; only its bridge to `texts` is dropped. This
        // column is always null (text is assembled from the wordlist, not from a table), so
        // no data is lost -- but while it exists, `texts` can't be dropped.
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
     * Restores the STRUCTURE, not its contents.
     *
     * Stated plainly because the rollback here does NOT restore the original state: the
     * `texts`/`languages` rows the seeder once filled are gone permanently, and
     * `typing_results.text_id` comes back as an empty column. For data nobody reads anymore
     * this is acceptable -- what isn't acceptable is a `down()` that quietly pretends to be
     * reversible.
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
