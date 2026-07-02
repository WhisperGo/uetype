<div class="text-muted font-mono">
    <div class="max-w-5xl mx-auto px-4 pt-10 pb-16">

        {{-- Header --}}
        <div class="mb-6">
            <h1 class="text-3xl font-mono font-bold text-foreground mb-1">Terms & Conditions</h1>
            <p class="font-sans text-x-small text-muted">Last updated July 2026</p>
        </div>

        {{-- Sections --}}
        <div class="rounded-xl border border-border bg-surface/60 px-6 py-6 divide-y divide-border">
            @foreach ($sections as $section)
                <div class="py-4 first:pt-0 last:pb-0">
                    <h2 class="font-sans text-small font-bold text-foreground mb-1.5">
                        {{ $section['title'] }}
                    </h2>
                    <p class="font-sans text-small text-muted leading-relaxed">
                        {{ $section['body'] }}
                    </p>
                </div>
            @endforeach
        </div>
    </div>
</div>
