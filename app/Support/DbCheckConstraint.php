<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Menegakkan sebuah CHECK constraint di level DB, dengan mekanisme yang bercabang
 * per driver tapi invarian yang sama.
 *
 * Kenapa ini ada: Laravel tak punya API check-constraint sama sekali, jadi satu-
 * satunya jalan adalah SQL mentah -- dan SQL mentahnya berbeda per driver. Dulu
 * dua migrasi (`messages`, `message_clears`) sama-sama menulis
 * `ALTER TABLE ... ADD CONSTRAINT ... CHECK` telanjang, sintaks yang HANYA
 * dipahami MySQL. Di sqlite keduanya meledak dan mematikan SELURUH suite,
 * padahal `.env.example` justru men-default sqlite: tiap developer baru
 * menabraknya di langkah pertama setup.
 *
 * Menyeragamkan sintaksnya tidak mungkin -- sqlite hanya menerima CHECK saat
 * CREATE TABLE, tak ada ADD CONSTRAINT. Jadi sqlite memakai trigger, yang
 * menjaga hal yang sama dengan cara berbeda.
 *
 * PENTING -- URUTAN MIGRASI: di sqlite, menambah FOREIGN KEY ke tabel yang sudah
 * ada memaksa Laravel MEMBANGUN ULANG tabelnya, dan pembangunan ulang itu
 * MENGHAPUS trigger yang menempel padanya. Jadi enforce() harus dipanggil dari
 * migrasi yang jalan SETELAH perubahan struktur terakhir tabel tersebut, bukan
 * dari migrasi yang membuatnya. Ini pernah kejadian: trigger `messages` dibuat di
 * migrasi pembuat tabel, lalu lenyap tanpa suara saat `reply_to_id` (berikut
 * FK-nya) ditambahkan tiga migrasi kemudian -- MySQL tak terpengaruh, jadi
 * celahnya hanya muncul di sqlite.
 */
class DbCheckConstraint
{
    /**
     * @param  string  $table  Tabel yang dijaga.
     * @param  string  $name  Nama constraint/trigger; dipakai juga sebagai pesan abort.
     * @param  string  $invariant  Ekspresi boolean yang HARUS benar, ditulis dengan
     *                             nama kolom telanjang (mis. "a IS NULL OR b IS NULL").
     * @param  string[]  $columns  Kolom yang disebut di $invariant. Wajib untuk sqlite:
     *                             trigger merujuk kolom lewat prefix NEW.
     */
    public static function enforce(string $table, string $name, string $invariant, array $columns): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$invariant})");

            return;
        }

        if ($driver === 'sqlite') {
            // Terpanjang dulu: mencegah "clan_id" tergantikan lebih dulu di dalam
            // nama kolom lain yang memuatnya sebagai substring.
            usort($columns, fn ($a, $b) => strlen($b) <=> strlen($a));

            $scoped = str_replace(
                $columns,
                array_map(fn ($c) => "NEW.{$c}", $columns),
                $invariant
            );

            // INSERT dan UPDATE butuh trigger TERPISAH: trigger insert saja
            // membiarkan baris sah di-update jadi melanggar.
            foreach (['insert', 'update'] as $event) {
                DB::statement(
                    "CREATE TRIGGER {$name}_{$event}
                     BEFORE ".strtoupper($event)." ON {$table}
                     FOR EACH ROW WHEN NOT ({$scoped})
                     BEGIN SELECT RAISE(ABORT, '{$name}'); END"
                );
            }

            return;
        }

        // Driver lain: lewati diam-diam. Lebih baik tabelnya tetap terbuat daripada
        // migrasi gagal total -- invariannya tetap dijaga aplikasi.
    }

    /**
     * Buang penjagaan bernama $name kalau ada. Aman dipanggil saat belum ada --
     * dipakai enforce ulang di migrasi lanjutan supaya DB yang sudah terlanjur
     * punya constraint versi lama tak menabrak error "duplicate constraint name".
     */
    public static function drop(string $table, string $name): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // `DROP CONSTRAINT IF EXISTS` itu sintaks MariaDB; MySQL tak menerimanya
            // (sampai 8.4 sekalipun). Jadi keberadaannya ditanyakan dulu ke
            // information_schema, baru di-drop -- cara yang sah di kedua-duanya.
            $exists = DB::selectOne(
                'SELECT 1 AS ok FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                [$table, $name]
            );

            if ($exists) {
                DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
            }

            return;
        }

        if ($driver === 'sqlite') {
            foreach (['insert', 'update'] as $event) {
                DB::statement("DROP TRIGGER IF EXISTS {$name}_{$event}");
            }
        }
    }
}
