@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'bg-background/60 border-white/10 text-foreground placeholder-muted focus:border-brand focus:ring-brand rounded-lg shadow-sm']) }}>
