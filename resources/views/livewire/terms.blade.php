<div class="text-muted font-mono">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pt-10 pb-16">

        {{-- Header --}}
        <div class="mb-6">
            <h1 class="text-fluid-title font-mono font-bold text-foreground mb-1">{{ __('terms.title') }}</h1>
            <p class="font-mono text-x-small text-muted">{{ __('terms.last_updated') }}</p>
        </div>

        {{-- Sections --}}
        <div class="rounded-xl border border-border bg-surface/60 px-6 py-6 divide-y divide-border">
            @foreach ($sections as $section)
                <div class="py-4 first:pt-0 last:pb-0">
                    <h2 class="font-mono text-small font-bold text-foreground mb-1.5">
                        {{ $section['title'] }}
                    </h2>
                    <p class="font-mono text-small text-muted leading-relaxed">
                        {{ $section['body'] }}
                    </p>
                </div>
            @endforeach
        </div>
    </div>
</div>
