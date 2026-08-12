@props([
    'user',
    'online' => null,
    'size' => 'w-10 h-10',
    'ring' => 'border-white/10',
    'fallbackSize' => 'w-7 h-7',
    'shape' => 'rounded-lg',
    'bg' => 'bg-white/5',
    'bordered' => true,
])

{{-- User avatar with mascot fallback. The ONLY place avatars are rendered;
     do not copy this markup.

     This isn't a cosmetic rule: the two missing `alt` attributes that had to be
     fixed by hand were exactly in the spots that wrote their own avatar markup
     instead of using this component. Duplication here produces bugs.

     All default values keep the 15 existing usages identical; the shape/bg/bordered
     props were added to accommodate the multiplayer lobby variants
     (rounded-md without border, rounded-xl bg-foreground/5). --}}
<div class="relative shrink-0">
    <div {{ $attributes->class([
        'overflow-hidden flex items-center justify-center',
        $shape,
        $bg,
        $size,
        'border '.$ring => $bordered,
    ]) }}>
        @if ($user->avatar)
            <img src="{{ $user->avatar }}" alt="{{ $user->username }}" referrerpolicy="no-referrer"
                class="w-full h-full object-cover">
        @else
            <img src="/icon/uetype_mascot.png" alt="{{ $user->username }}" class="{{ $fallbackSize }} object-contain">
        @endif
    </div>

    @if (! is_null($online))
        <span @class([
            'absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-surface',
            'bg-active' => $online,
            'bg-white/25' => ! $online,
        ]) title="{{ $online ? __('friends.online') : __('friends.offline') }}"></span>
    @endif
</div>
