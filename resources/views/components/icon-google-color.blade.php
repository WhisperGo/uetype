{{-- Official 4-color Google logo (blue/green/yellow/red). Simple Icons only ships
     a monochrome mark (single path, currentColor), so it can't be multi-color.
     This SVG matches the "Continue with Google" button in auth/_google-panel.blade.php. --}}
@props(['class' => 'w-4 h-4'])
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" {{ $attributes->merge(['class' => $class]) }}>
    <path fill="#4285F4"
        d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.53-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.66-5.17 3.66-8.17z" />
    <path fill="#34A853"
        d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.11 0-5.74-2.11-6.68-4.96H1.32v3.15C3.31 20.36 7.38 24 12 24z" />
    <path fill="#FBBC05"
        d="M5.32 14.24A7.16 7.16 0 0 1 5 12c0-.79.13-1.57.32-2.34V6.51H1.32A11.94 11.94 0 0 0 0 12c0 1.92.45 3.74 1.32 5.39l4-3.15z" />
    <path fill="#EA4335"
        d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.38 0 3.31 3.64 1.32 7.51l4 3.15c.94-2.85 3.57-4.91 6.68-4.91z" />
</svg>
