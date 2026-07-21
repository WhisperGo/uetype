{{-- A single link item styled for use inside the dropdown menu. --}}
<a {{ $attributes->merge(['class' => 'block w-full px-4 py-2 text-start text-sm leading-5 text-muted hover:bg-elevated hover:text-foreground focus:outline-none focus:bg-elevated focus:text-foreground transition duration-150 ease-in-out']) }}>{{ $slot }}</a>
