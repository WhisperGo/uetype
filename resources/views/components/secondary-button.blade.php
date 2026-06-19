<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-typing-surface border border-white/10 rounded-lg font-sans font-semibold text-xs text-typing-text uppercase tracking-widest hover:bg-typing-elevated focus:outline-none focus:ring-2 focus:ring-typing-accent focus:ring-offset-2 focus:ring-offset-typing-bg disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
