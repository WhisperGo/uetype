<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-brand border border-transparent rounded-lg font-sans font-semibold text-xs text-foreground uppercase tracking-widest hover:shadow-glow focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2 focus:ring-offset-background transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
