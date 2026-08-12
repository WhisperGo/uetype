<?php

namespace App\Services;

/**
 * Turns the client's raw error stream into the result page's error-marker view model.
 * Pure functions, no state/DB.
 *
 * Two jobs, one wire format:
 * - sanitize(): trust boundary for the client payload (runs in TypingEngine::saveResult).
 * - inspect():  maps each error onto a chart second and reconstructs the word it happened
 *               in, from textToType (runs at TypingResult render).
 */
class TypingErrorInspector
{
    /**
     * Cap on accepted events. 500 errors in one test ≈ below 50% accuracy in time 120
     * -- that is mashing, not practice. Above the cap, chart points & panel are
     * truncated while the heatmap is not (see docs: three-figure divergence).
     */
    public const MAX_EVENTS = 500;

    /**
     * Validate shape + cast the client payload. A presentation tier (session-only,
     * never touches score/XP/PB/leaderboard), so it has no business with
     * AntiCheatService -- but it is still sanitized, following the ghost precedent.
     *
     * Unlike missedChars, which is raw but safe because it is only read via known-key
     * lookups: here `actual` is genuinely RENDERED, so no arbitrary client string may
     * get through.
     *
     * @return list<array{second:int,index:int,actual:?string}>
     */
    public static function sanitize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clean = [];

        foreach ($raw as $event) {
            if (count($clean) >= self::MAX_EVENTS) {
                break;
            }

            if (! is_array($event)
                || ! isset($event['second'], $event['index'])
                || ! is_numeric($event['second'])
                || ! is_numeric($event['index'])) {
                continue;
            }

            $second = (int) $event['second'];
            $index = (int) $event['index'];

            // Negative coordinates are impossible from the normal path.
            if ($second < 0 || $index < 0) {
                continue;
            }

            // Truncated to 1 character: that is all handleInput can produce
            // (e.key.length is kept === 1 there). null = character SKIPPED, a valid state.
            $actual = $event['actual'] ?? null;
            $actual = is_string($actual) && $actual !== '' ? mb_substr($actual, 0, 1) : null;

            $clean[] = ['second' => $second, 'index' => $index, 'actual' => $actual];
        }

        return $clean;
    }

    /**
     * Build the chart/panel view model: per-second error counts plus, for each error,
     * the word it landed in and what was typed instead.
     *
     * @param  list<array{second:int,index:int,actual:?string}>  $events  already passed through sanitize()
     * @param  string|null  $textToType  null -> graceful degradation (word/expected become null)
     * @param  int  $sampleCount  count($wpmHistory) -- length of the chart's x-axis
     * @return array{counts: list<int>, events: list<array{second:int,label:int,word:?string,offset:?int,expected:?string,actual:?string}>}
     */
    public static function inspect(array $events, ?string $textToType, int $sampleCount): array
    {
        $sampleCount = max(0, $sampleCount);
        $counts = array_fill(0, $sampleCount, 0);

        // Without wpmHistory samples there is no x-axis to attach to -- the chart is
        // simply empty. The heatmap stays intact (sourced from missedChars, a separate path).
        if ($sampleCount === 0 || $events === []) {
            return ['counts' => $counts, 'events' => []];
        }

        // mb_str_split: the server indexes per CODEPOINT, the client (targetArray =
        // split('')) per UTF-16 code unit. Identical up to U+FFFF; the en/id wordlists
        // are pure a-z, so indexes always match. Astral characters (emoji) in the text
        // would make them diverge.
        $chars = ($textToType !== null && $textToType !== '') ? mb_str_split($textToType) : [];
        $bounds = self::wordBounds($chars);

        $enriched = [];

        foreach ($events as $event) {
            // The last second of a `time` test never enters wpmHistory: the tick that
            // triggers finish() sets isFinished before its push line runs. An error in
            // that second is CLAMPED to the last sample, NOT dropped -- dropping it would
            // break the "point count === missedChars count" invariant this page relies on
            // to align the chart with the heatmap right below it.
            $second = min($event['second'], $sampleCount - 1);
            $counts[$second]++;

            $word = self::wordAt($bounds, $event['index']);

            $enriched[] = [
                'second' => $second,
                // label = what is written on the x-axis. Emitted explicitly so no
                // template does its own arithmetic -- off-by-one is this feature's main risk.
                'label' => $second + 1,
                'word' => $word['text'] ?? null,
                'offset' => $word !== null ? $event['index'] - $word['start'] : null,
                'expected' => $chars[$event['index']] ?? null,
                'actual' => $event['actual'],
            ];
        }

        return ['counts' => $counts, 'events' => $enriched];
    }

    /**
     * Word bounds (absolute indices) of the target text. Mirrors the wordBounds
     * assembly in typingGame(): a word = a run of characters between spaces. One
     * deliberate difference -- empty words (double spaces) are skipped, so malformed
     * text can't produce a word with end < start.
     *
     * @param  list<string>  $chars
     * @return list<array{start:int,end:int,text:string}>
     */
    private static function wordBounds(array $chars): array
    {
        $bounds = [];
        $start = 0;
        $len = count($chars);

        for ($i = 0; $i < $len; $i++) {
            if ($chars[$i] !== ' ') {
                continue;
            }

            if ($i > $start) {
                $bounds[] = [
                    'start' => $start,
                    'end' => $i - 1,
                    'text' => implode('', array_slice($chars, $start, $i - $start)),
                ];
            }

            $start = $i + 1;
        }

        // The last word has no trailing space -- this branch handles "an error in the
        // last word", which on the client never goes through completeWord().
        if ($len > $start) {
            $bounds[] = [
                'start' => $start,
                'end' => $len - 1,
                'text' => implode('', array_slice($chars, $start)),
            ];
        }

        return $bounds;
    }

    /**
     * @param  list<array{start:int,end:int,text:string}>  $bounds
     * @return array{start:int,end:int,text:string}|null
     */
    private static function wordAt(array $bounds, int $index): ?array
    {
        foreach ($bounds as $word) {
            if ($index < $word['start']) {
                return null;    // falls in a space gap
            }

            if ($index <= $word['end']) {
                return $word;
            }
        }

        return null;            // outside the text
    }
}
