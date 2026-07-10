@props(['user', 'online' => null, 'size' => 'w-10 h-10', 'ring' => 'border-white/10', 'fallbackSize' => 'w-7 h-7'])

<div class="relative shrink-0">
    <div {{ $attributes->class(['rounded-lg overflow-hidden bg-white/5 border flex items-center justify-center', $size, $ring]) }}>
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
