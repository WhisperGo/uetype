{{-- Preview bar shown above the composer for the message being replied to. --}}
@props([
    'message',
    'size' => 'lg',
])

@php
    $t = $size === 'sm'
        ? ['pad' => 'gap-2 px-3 pt-2', 'bar' => 'pl-2', 'name' => 'text-[0.6rem]', 'body' => 'text-[0.65rem]', 'limit' => 60, 'icon' => 'w-3.5 h-3.5']
        : ['pad' => 'gap-3 px-4 pt-3', 'bar' => 'pl-3', 'name' => 'text-[0.65rem]', 'body' => 'text-xs', 'limit' => 80, 'icon' => 'w-4 h-4'];
@endphp

<div class="flex items-center {{ $t['pad'] }} shrink-0">
    <div class="flex-1 min-w-0 {{ $t['bar'] }} border-l-2 border-gold">
        <p class="font-mono {{ $t['name'] }} font-bold text-gold">
            {{ __('chat.replying_to') }}
            {{ $message->sender_id === auth()->id() ? __('chat.you') : $message->sender->username }}
        </p>
        <p class="font-mono {{ $t['body'] }} text-muted truncate">
            {{ $message->isDeletedForEveryone() ? __('chat.deleted_placeholder') : Str::limit($message->body, $t['limit']) }}
        </p>
    </div>
    <button wire:click="cancelReply" class="text-muted hover:text-foreground shrink-0 p-1" aria-label="{{ __('chat.cancel_reply') }}" title="{{ __('chat.cancel_reply') }}">
        <svg class="{{ $t['icon'] }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
    </button>
</div>
