<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('typing_speed_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('language', 5);
            $table->decimal('verified_wpm', 8, 2);
            $table->decimal('verified_accuracy', 5, 2);
            $table->timestamp('verified_at');
            $table->unsignedSmallInteger('rule_version')->default(1);
            $table->json('evidence_meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'language']);
        });

        Schema::create('typing_verification_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_result_id')->constrained('typing_results')->cascadeOnDelete();
            $table->string('language', 5);
            $table->char('token_hash', 64)->unique();
            $table->longText('challenge_text');
            $table->char('text_hash', 64);
            $table->string('status', 16)->default('active');
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->json('result_meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('typing_verification_attempts');
        Schema::dropIfExists('typing_speed_capabilities');
    }
};
