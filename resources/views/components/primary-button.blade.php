<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-typing-accent border border-transparent rounded-lg font-sans font-semibold text-xs text-typing-bg uppercase tracking-widest hover:shadow-glow focus:outline-none focus:ring-2 focus:ring-typing-accent focus:ring-offset-2 focus:ring-offset-typing-bg transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
