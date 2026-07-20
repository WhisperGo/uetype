<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Pulau mati: `matches`, `match_participants`, `texts`, `languages`, `paragraphs`
 * beserta modelnya.
 *
 * Kelimanya tak pernah disentuh satu pun komponen Livewire maupun controller --
 * mereka hanya saling mereferensi satu sama lain, ditambah dua seeder yang
 * mengisinya untuk tak dibaca siapa pun. `typing_results.text_id` yang menjadi
 * satu-satunya jembatan ke `texts` SELALU null, dan kode yang menulisnya pun
 * mengakuinya di komentar.
 *
 * Test ini bukan sekadar mencatat penghapusan -- ia mencegahnya kembali diam-diam
 * lewat merge dari branch lama yang masih memuat migrasi/model tersebut.
 */
it('no longer ships the legacy matches and text tables', function () {
    foreach (['matches', 'match_participants', 'texts', 'languages', 'paragraphs'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("Tabel legacy `{$table}` muncul lagi");
    }
});

it('no longer carries the always-null text_id bridge on typing_results', function () {
    // Kolom ini FK ke `texts`; selama ia ada, tabel itu tak bisa di-drop.
    expect(Schema::hasColumn('typing_results', 'text_id'))->toBeFalse();
});

it('no longer defines the legacy models', function () {
    // Sengaja string, bukan ::class -- nama-nama ini memang TIDAK boleh ada lagi,
    // dan menuliskannya sebagai ::class hanya mengundang import yang menunjuk ke
    // kelas yang sudah dihapus.
    foreach ([
        'App\Models\Matches',
        'App\Models\MatchParticipant',
        'App\Models\Text',
        'App\Models\Language',
        'App\Models\Paragraph',
    ] as $model) {
        expect(class_exists($model))->toBeFalse("Model legacy `{$model}` muncul lagi");
    }
});

it('no longer exposes the legacy relations on the user model', function () {
    foreach (['matchParticipants', 'hostedMatches'] as $relation) {
        expect(method_exists(User::class, $relation))->toBeFalse(
            "Relasi legacy `User::{$relation}()` muncul lagi"
        );
    }
});
