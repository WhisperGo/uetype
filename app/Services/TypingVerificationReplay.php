<?php

namespace App\Services;

/** Replays a bounded key-event stream against the server-issued challenge text. */
class TypingVerificationReplay
{
    public const DURATION_SECONDS = 10.0;

    private const MAX_EVENTS = 2000;

    /** Client timestamps are clamped to the timer; 250 ms only absorbs scheduling jitter. */
    private const EVENT_END_SLACK_MS = 250.0;

    /**
     * @param  array<int, mixed>  $events
     * @return array{valid:bool,reason:?string,net_wpm:float,accuracy:float,correct_chars:int,total_keystrokes:int,intervals:list<float>}
     */
    public function replay(string $target, array $events): array
    {
        if (count($events) < 20 || count($events) > self::MAX_EVENTS) {
            return $this->failure('event_count_invalid');
        }

        $typed = [];
        $targetChars = mb_str_split($target);
        $inserted = 0;
        $timestamps = [];
        $previousAt = -1.0;

        foreach ($events as $event) {
            if (! is_array($event) || ! isset($event['key'], $event['at_ms']) || ! is_numeric($event['at_ms'])) {
                return $this->failure('event_shape_invalid');
            }

            $key = (string) $event['key'];
            $at = (float) $event['at_ms'];

            $latestAllowedAt = self::DURATION_SECONDS * 1000 + self::EVENT_END_SLACK_MS;

            if ($at < 0 || $at < $previousAt || $at > $latestAllowedAt) {
                return $this->failure('event_timing_invalid');
            }

            $previousAt = $at;

            if ($key === 'Backspace') {
                array_pop($typed);
                $timestamps[] = $at;

                continue;
            }

            if ($key === 'BackspaceWord') {
                while ($typed !== [] && end($typed) === ' ') {
                    array_pop($typed);
                }

                while ($typed !== [] && end($typed) !== ' ') {
                    array_pop($typed);
                }

                $timestamps[] = $at;

                continue;
            }

            if ($key === 'Enter') {
                $key = ' ';
            }

            if (mb_strlen($key) !== 1 || count($typed) >= count($targetChars)) {
                return $this->failure('event_key_invalid');
            }

            $typed[] = mb_strtolower($key);
            $inserted++;
            $timestamps[] = $at;
        }

        $correct = 0;

        foreach ($typed as $index => $char) {
            if (($targetChars[$index] ?? null) === $char) {
                $correct++;
            }
        }

        $intervals = [];
        for ($index = 1; $index < count($timestamps); $index++) {
            $gap = $timestamps[$index] - $timestamps[$index - 1];
            if ($gap > 0) {
                $intervals[] = $gap;
            }
        }

        // A complete stream should cover almost every reported keystroke. This prevents a
        // client from submitting a small, hand-picked timing sample beside a large text claim.
        $minimumIntervals = max(20, (int) floor($inserted * 0.80));
        if (count($intervals) < $minimumIntervals) {
            return $this->failure('event_coverage_invalid');
        }

        $netWpm = round(($correct / 5) / (self::DURATION_SECONDS / 60), 2);
        $accuracy = $inserted > 0 ? round(($correct / $inserted) * 100, 2) : 0.0;

        return [
            'valid' => true,
            'reason' => null,
            'net_wpm' => $netWpm,
            'accuracy' => $accuracy,
            'correct_chars' => $correct,
            'total_keystrokes' => $inserted,
            'intervals' => $intervals,
        ];
    }

    private function failure(string $reason): array
    {
        return [
            'valid' => false,
            'reason' => $reason,
            'net_wpm' => 0.0,
            'accuracy' => 0.0,
            'correct_chars' => 0,
            'total_keystrokes' => 0,
            'intervals' => [],
        ];
    }
}
