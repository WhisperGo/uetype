<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 text-foreground font-mono">
    <h1 class="font-display text-fluid-title tracking-wide text-foreground mb-2">{{ __('review.title') }}</h1>
    <p class="text-sm text-muted mb-8">{{ __('review.subtitle') }}</p>

    @if ($this->pending->isEmpty())
        <div class="rounded-2xl border border-border/40 bg-surface/40 px-6 py-12 text-center">
            <p class="text-sm text-muted">{{ __('review.empty') }}</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($this->pending as $r)
                <div wire:key="review-{{ $r->id }}"
                    class="flex flex-wrap items-center gap-4 rounded-2xl border border-gold/30 bg-surface/50 px-5 py-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold text-foreground truncate">{{ $r->user?->username ?? '(deleted)' }}</span>
                            <span class="px-2 py-0.5 rounded-md bg-gold/15 text-gold text-[10px] font-bold uppercase tracking-wider">
                                {{ __('review.reason.' . $r->review_reason) }}
                            </span>
                        </div>
                        <div class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-muted">
                            <span><span class="text-foreground font-bold">{{ (int) $r->net_wpm }}</span> WPM</span>
                            <span>{{ $r->accuracy }}% acc</span>
                            <span>{{ $r->mode->value }} {{ $r->mode_config }}</span>
                            <span>{{ $r->correct_chars }} chars</span>
                            <span>{{ round((float) $r->duration_seconds, 1) }}s</span>
                            <span>{{ $r->created_at?->format('Y-m-d H:i') }}</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <button type="button" wire:click="reject({{ $r->id }})"
                            class="px-4 py-2 rounded-lg border border-danger/40 text-danger text-xs font-bold uppercase tracking-wider transition hover:bg-danger/10">
                            {{ __('review.reject') }}
                        </button>
                        <button type="button" wire:click="approve({{ $r->id }})"
                            class="px-4 py-2 rounded-lg bg-gold text-background text-xs font-bold uppercase tracking-wider transition hover:bg-secondary-7">
                            {{ __('review.approve') }}
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
