@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'bg-typing-bg/60 border-white/10 text-typing-text placeholder-typing-muted focus:border-typing-accent focus:ring-typing-accent rounded-lg shadow-sm']) }}>
