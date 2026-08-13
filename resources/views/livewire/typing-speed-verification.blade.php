<x-page-container width="max-w-4xl">
    <div class="py-12 font-mono" x-data="speedVerification($wire, 30)" x-on:pagehide.window="destroy()">
        <div class="rounded-3xl border border-border bg-surface/70 p-6 sm:p-8">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-[0.25em] text-brand-bright">{{ __('verification.eyebrow') }}</p>
                    <h1 class="mt-2 font-display text-3xl text-foreground">{{ __('verification.title') }}</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted">{{ __('verification.intro') }}</p>
                </div>
                <div class="rounded-xl border border-gold/30 bg-gold/10 px-4 py-3 text-center text-gold">
                    <p class="text-3xl font-bold tabular-nums" x-text="remaining">30</p>
                    <p class="text-[0.65rem] uppercase tracking-widest">{{ __('verification.seconds') }}</p>
                </div>
            </div>

            @if ($state === 'ready')
                <div class="mt-8 rounded-2xl border border-white/5 bg-background/40 p-5 text-sm text-muted">
                    <ul class="space-y-2">
                        <li>• {{ __('verification.rule_time') }}</li>
                        <li>• {{ __('verification.rule_locked') }}</li>
                        <li>• {{ __('verification.rule_no_rewards') }}</li>
                    </ul>
                </div>
                <button type="button" class="mt-6 min-h-11 rounded-xl bg-brand px-6 py-3 font-bold text-background transition hover:bg-brand-bright"
                    x-bind:disabled="starting" x-bind:class="starting ? 'cursor-wait opacity-60' : ''"
                    x-on:click="start()">
                    {{ __('verification.start') }}
                </button>
            @endif

            <div x-show="active || submitting" x-cloak class="mt-8">
                <textarea x-ref="input" aria-label="{{ __('verification.input_label') }}"
                    class="fixed h-px w-px opacity-0"
                    autocapitalize="none" autocomplete="off" autocorrect="off" spellcheck="false"
                    inputmode="text"
                    x-on:beforeinput="beforeInput($event)"
                    x-on:paste="preventTransfer($event)"
                    x-on:drop="preventTransfer($event)"></textarea>

                <button type="button" class="w-full rounded-2xl border border-white/5 bg-background/50 p-5 text-left leading-8"
                    x-on:click="$refs.input.focus({ preventScroll: true })">
                    <template x-for="(character, index) in [...text]" :key="index">
                        <span :class="classFor(index, character)" x-text="character"></span>
                    </template>
                </button>
                <p class="mt-3 text-xs text-muted" x-show="active">{{ __('verification.typing_hint') }}</p>
                <p class="mt-3 text-xs text-gold" x-show="submitting">{{ __('verification.checking') }}</p>
            </div>

            @if (in_array($state, ['failed', 'expired', 'cooldown', 'rate_limited'], true))
                <div role="alert" class="mt-8 rounded-2xl border border-gold/40 bg-gold/10 p-5 text-gold">
                    <p class="font-bold">{{ $state === 'expired' ? __('verification.expired_title') : __('verification.failed_title') }}</p>
                    <p class="mt-2 text-sm text-gold/80">
                        {{ in_array($state, ['cooldown', 'rate_limited'], true)
                            ? __('verification.retry_after', ['seconds' => $retryAfter])
                            : ($state === 'expired' ? __('verification.expired_body') : __('verification.failed_body')) }}
                    </p>
                </div>
                <button type="button" class="mt-5 min-h-11 rounded-xl border border-border px-5 py-3 text-foreground hover:border-brand/60"
                    x-bind:disabled="starting" x-bind:class="starting ? 'cursor-wait opacity-60' : ''"
                    x-on:click="start()">
                    {{ __('verification.try_again') }}
                </button>
            @endif

            @if ($state === 'passed')
                <div role="status" class="mt-8 rounded-2xl border border-brand/40 bg-brand/10 p-5 text-brand-bright">
                    <p class="font-bold">{{ __('verification.success_title') }}</p>
                    <p class="mt-2 text-sm text-brand-bright/80">
                        {{ __('verification.success_body', ['wpm' => number_format($verifiedWpm, 1), 'count' => $promotedCount]) }}
                    </p>
                </div>
                <a href="{{ route('profile.me') }}" class="mt-5 inline-flex min-h-11 items-center rounded-xl bg-brand px-5 py-3 font-bold text-background hover:bg-brand-bright">
                    {{ __('verification.view_profile') }}
                </a>
            @endif

            <a href="{{ route('typing') }}" class="mt-6 inline-flex min-h-11 items-center px-1 text-sm text-muted hover:text-foreground">
                {{ __('verification.later') }}
            </a>
        </div>
    </div>
</x-page-container>
