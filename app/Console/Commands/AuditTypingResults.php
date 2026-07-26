<?php

namespace App\Console\Commands;

use App\Models\TypingResult;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Report solo results whose numbers look fabricated, so historical data recorded before
 * the server-side session guard existed can be reviewed. Read-only by design: it prints
 * findings and changes nothing, leaving the decision to a human.
 */
class AuditTypingResults extends Command
{
    protected $signature = 'typing:audit {--wpm=150 : Flag results at or above this net WPM} {--limit=50 : Maximum rows to list}';

    protected $description = 'List solo typing results with implausible numbers (read-only; nothing is modified).';

    public function handle(): int
    {
        $threshold = (float) $this->option('wpm');
        $limit = (int) $this->option('limit');

        $suspicious = TypingResult::with('user')
            ->where('net_wpm', '>=', $threshold)
            ->orderByDesc('net_wpm')
            ->limit($limit)
            ->get();

        if ($suspicious->isEmpty()) {
            $this->info("No results at or above {$threshold} WPM. Nothing to review.");

            return self::SUCCESS;
        }

        $this->warn("Results at or above {$threshold} WPM (showing up to {$limit}):");
        $this->newLine();

        $this->table(
            ['ID', 'User', 'Mode', 'Net WPM', 'Accuracy', 'Chars', 'Duration', 'Recorded'],
            $suspicious->map(fn (TypingResult $r) => [
                $r->id,
                $r->user?->username ?? '(deleted)',
                // `mode` is cast to the TypingMode enum, which has no __toString -- use its
                // backing value so the concatenation doesn't throw.
                $r->mode->value.' '.$r->mode_config,
                $r->net_wpm,
                $r->accuracy.'%',
                $r->correct_chars,
                round((float) $r->duration_seconds, 1).'s',
                $r->created_at?->format('Y-m-d H:i'),
            ])->all()
        );

        $this->newLine();
        $this->line('Users whose highest_wpm comes from a flagged result:');

        $affected = User::whereIn('id', $suspicious->pluck('user_id')->filter()->unique())
            ->where('highest_wpm', '>=', $threshold)
            ->get(['id', 'username', 'highest_wpm']);

        if ($affected->isEmpty()) {
            $this->line('  (none)');
        } else {
            foreach ($affected as $user) {
                $this->line("  #{$user->id} {$user->username} -- highest_wpm {$user->highest_wpm}");
            }
        }

        $this->newLine();
        $this->comment('Read-only: nothing was changed. Review these before deciding what to do.');

        return self::SUCCESS;
    }
}
