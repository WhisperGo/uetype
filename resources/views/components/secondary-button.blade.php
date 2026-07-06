<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-surface border border-white/10 rounded-lg font-mono font-semibold text-xs text-foreground uppercase tracking-widest hover:bg-elevated focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 focus:ring-offset-background disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
