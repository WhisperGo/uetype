@props(['user'])

<div class="w-10 h-10 rounded-lg overflow-hidden bg-white/5 border border-white/10 flex items-center justify-center shrink-0">
    @if ($user->avatar)
        <img src="{{ $user->avatar }}" alt="{{ $user->username }}" referrerpolicy="no-referrer"
            class="w-full h-full object-cover">
    @else
        <img src="/icon/uetype_mascot.png" alt="{{ $user->username }}" class="w-7 h-7 object-contain">
    @endif
</div>
