<?php

use App\Support\DbCheckConstraint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Penjagaan DB-level "tepat satu target terisi" untuk `messages` dan
     * `message_clears`: DM mengisi recipient_id/other_user_id, clan chat mengisi
     * clan_id, tak boleh keduanya dan tak boleh kosong dua-duanya.
     *
     * Kenapa berdiri sendiri di akhir, bukan di migrasi pembuat tabelnya:
     *
     * 1. Sintaksnya beda per driver. `ALTER TABLE ... ADD CONSTRAINT ... CHECK`
     *    hanya dipahami MySQL; di sqlite migrasinya meledak dan mematikan SELURUH
     *    suite, padahal .env.example justru men-default sqlite. DbCheckConstraint
     *    yang menangani percabangannya (CHECK di MySQL, trigger di sqlite).
     *
     * 2. Di sqlite, menambah FOREIGN KEY ke tabel yang sudah ada memaksa Laravel
     *    MEMBANGUN ULANG tabel itu, dan pembangunan ulang MENGHAPUS trigger yang
     *    menempel padanya. `messages` masih ditambahi `reply_to_id` (berikut FK-nya)
     *    tiga migrasi setelah dibuat -- jadi trigger yang dipasang di migrasi
     *    pembuat tabel akan lenyap tanpa suara. MySQL tak terpengaruh, sehingga
     *    celah ini HANYA muncul di sqlite dan mudah luput.
     *
     * Menempatkannya setelah semua perubahan struktur menutup keduanya sekaligus.
     */
    public function up(): void
    {
        // drop dulu: DB yang sudah terlanjur punya constraint dari versi lama
        // migrasi ini tak menabrak "duplicate constraint name".
        DbCheckConstraint::drop('messages', 'messages_exactly_one_target');
        DbCheckConstraint::enforce(
            'messages',
            'messages_exactly_one_target',
            '(recipient_id IS NOT NULL AND clan_id IS NULL) OR (recipient_id IS NULL AND clan_id IS NOT NULL)',
            ['recipient_id', 'clan_id'],
        );

        DbCheckConstraint::drop('message_clears', 'message_clears_exactly_one_target');
        DbCheckConstraint::enforce(
            'message_clears',
            'message_clears_exactly_one_target',
            '(other_user_id IS NOT NULL AND clan_id IS NULL) OR (other_user_id IS NULL AND clan_id IS NOT NULL)',
            ['other_user_id', 'clan_id'],
        );
    }

    public function down(): void
    {
        DbCheckConstraint::drop('messages', 'messages_exactly_one_target');
        DbCheckConstraint::drop('message_clears', 'message_clears_exactly_one_target');
    }
};
