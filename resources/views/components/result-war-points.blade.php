{{--
    What the war received from this attempt.

    Kept separate from <x-result-back-to-war /> on purpose: that component's whole reason for
    existing is WHY its link is a full page load, and folding a scoreboard into it would make
    its name lie. Two call sites (survival + normal branch), one file.

    Two states, never a zero standing in for both: a run that scored, and a run whose claim was
    already filled by the time it finished (see TypingEngine::attachToWarClaim). Printing "0
    pts" for the second would be a number no scoreboard will ever agree with.
--}}
{{-- `accuracy` is passed in rather than read off the breakdown: the breakdown stores the
     MULTIPLIER (0.5-1.0), and re-deriving the percentage from it would round-trip through a
     rounded number. The result page already has the real figure. --}}
@props(['war', 'accuracy'])

@php
    $score = $war['score'] ?? null;

    // Same label switch as the war grid on /clan-war -- one vocabulary for one slot.
    $labelMode = __('clan.mode.' . $war['mode']);
    $labelConfig = $war['mode'] === 'survival'
        ? __('clan.mode.survival_' . $war['config'])
        : ($war['mode'] === 'time'
            ? __('clan.mode.config_time', ['n' => $war['config']])
            : __('clan.mode.config_words', ['n' => $war['config']]));
@endphp

@if ($score === null)
    {{-- Gold, not `danger`: the same treatment as the AFK banner and for the same reason --
         this is not an accusation, just work that didn't land. --}}
    <div class="rounded-2xl border border-gold/40 bg-gold/10 px-4 py-3 font-mono text-gold">
        <p class="flex items-center gap-2 text-x-small font-bold">
            <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0l-7.1 12.25A2 2 0 004.99 19z" />
            </svg>
            <span>{{ __('result.war.not_counted_title') }}</span>
        </p>
        <p class="mt-2 text-x-small text-gold/80">{{ __('result.war.not_counted_body') }}</p>
    </div>
@else
    <div class="rounded-2xl border border-white/5 bg-surface/70 p-5 text-center">
        <p class="font-mono text-x-small uppercase tracking-widest text-muted">
            {{ __('result.war.heading') }}
        </p>
        <p class="mt-1 font-mono text-x-small text-muted">
            {{ __('clan.war.mode_label', ['mode' => $labelMode, 'config' => $labelConfig]) }}
        </p>

        <p class="mt-3 font-mono text-3xl font-bold text-gold tabular-nums">
            {{ __('result.war.points', [
                'points' => rtrim(rtrim(number_format($score['points'], 1), '0'), '.'),
                'ceiling' => $score['ceiling'],
            ]) }}
        </p>

        {{-- The two factors the ceiling was multiplied by. The pace line follows `basis`
             because survival is scored on how long you lasted, not on WPM -- labelling it
             "pace ... wpm" would state something false about the highest-ceiling mode. --}}
        <div class="mt-4 flex flex-col items-center gap-1 font-mono text-x-small text-muted">
            <p>
                @if ($score['basis'] === 'duration')
                    {{ __('result.war.factor_pace_survival', [
                        'value' => rtrim(rtrim(number_format($score['basis_value'], 1), '0'), '.'),
                        'ratio' => number_format($score['performance_ratio'], 2),
                        'scale' => $score['basis_scale'],
                    ]) }}
                @else
                    {{ __('result.war.factor_pace_wpm', [
                        'value' => rtrim(rtrim(number_format($score['basis_value'], 1), '0'), '.'),
                        'ratio' => number_format($score['performance_ratio'], 2),
                        'scale' => $score['basis_scale'],
                    ]) }}
                @endif
            </p>
            <p>
                {{ __('result.war.factor_accuracy', [
                    'value' => rtrim(rtrim(number_format($accuracy, 1), '0'), '.'),
                    'multiplier' => number_format($score['accuracy_multiplier'], 2),
                ]) }}
            </p>
        </div>
    </div>
@endif
