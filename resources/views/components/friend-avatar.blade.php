@props(['user', 'online' => null])

<div class="relative shrink-0">
    <div class="w-10 h-10 rounded-lg overflow-hidden bg-white/5 border border-white/10 flex items-center justify-center">
        @if ($user->avatar)
            <img src="{{ $user->avatar }}" alt="{{ $user->username }}" referrerpolicy="no-referrer"
                class="w-full h-full object-cover">
        @else
            <img src="/icon/uetype_mascot.png" alt="{{ $user->username }}" class="w-7 h-7 object-contain">
        @endif
    </div>

    {{-- Titik status: hanya dirender kalau prop $online di-set (bukan null),
         supaya avatar di konteks lain tetap tanpa indikator. --}}
    @if (! is_null($online))
        <span @class([
            'absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-surface',
            'bg-green-400' => $online,
            'bg-white/25' => ! $online,
        ]) title="{{ $online ? __('friends.online') : __('friends.offline') }}"></span>
    @endif
</div>
