{{-- A single link item styled for use inside the dropdown menu.
     `group` is load-bearing: the icon inside dims to muted/70 at rest and lifts to gold on
     hover via `group-hover:`, matching the Figma account menu where the glyph -- not just
     the label -- responds to the pointer. --}}
<a {{ $attributes->merge(['class' => 'group flex w-full items-center gap-2 px-4 py-2 text-start text-sm leading-5 text-muted hover:bg-elevated hover:text-foreground focus:outline-none focus:bg-elevated focus:text-foreground transition duration-150 ease-in-out']) }}>{{ $slot }}</a>
