{{-- Styled text input with an optional disabled state.

     `text-base` (16px) is a PLATFORM THRESHOLD, not an aesthetic choice: iOS Safari zooms
     the whole page in when a field with a smaller font-size is focused, and it does not zoom
     back out afterwards -- the user is left on a magnified page until they pinch it back
     themselves. The same rule is already locked on the typing input (see typing-engine.md).

     Platform attributes (autocapitalize/autocorrect/spellcheck) are deliberately NOT defaults
     here. A clan name, a chat message and a room code want different behaviour, and a wrong
     default fails silently -- word-lock rejects an auto-capitalised letter with no visible
     reason. Set them per field. --}}
@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'bg-background/60 border-white/10 text-foreground placeholder-muted focus:border-brand focus:ring-brand rounded-lg shadow-sm text-base']) }}>
