{{-- Confirmation modal for clearing a chat (all messages, or older than N days). --}}
@props([
    // The full page sits at z-50; the overlay must sit above its own drawer (z-[55]).
    'z' => 'z-50',
])

<div class="fixed inset-0 {{ $z }} flex items-center justify-center p-4 bg-black/60" wire:click.self="$set('showClearModal', false)">
    <div class="w-full max-w-sm p-6 bg-surface border border-white/10 rounded-2xl">
        <h2 class="font-display text-lg text-foreground mb-1">{{ __('chat.clear_modal_title') }}</h2>
        <p class="font-mono text-xs text-muted mb-5">{{ __('chat.clear_modal_body') }}</p>

        <div class="space-y-3 mb-5">
            <label class="flex items-center gap-3 font-mono text-sm text-foreground cursor-pointer">
                <input type="radio" wire:model="clearScope" value="all" class="accent-gold">
                {{ __('chat.clear_scope_all') }}
            </label>
            <label class="flex items-center gap-3 font-mono text-sm text-foreground cursor-pointer">
                <input type="radio" wire:model="clearScope" value="days" class="accent-gold">
                <span>{{ __('chat.clear_scope_days') }}</span>
                <input type="number" wire:model="clearDays" min="1" max="3650"
                    class="w-16 px-2 py-1 bg-surface/60 border border-white/10 rounded-lg font-mono text-sm text-foreground focus:border-gold/50 focus:ring-0">
                <span>{{ __('chat.clear_scope_days_suffix') }}</span>
            </label>
        </div>

        <div class="flex justify-end gap-3">
            <x-btn-ghost size="wide" wire:click="$set('showClearModal', false)">
                {{ __('chat.clear_cancel') }}
            </x-btn-ghost>
            <button wire:click="confirmClear"
                class="px-4 py-2 font-mono text-xs font-bold text-background bg-red-400 hover:bg-red-400/90 rounded-lg transition">
                {{ __('chat.clear_confirm') }}
            </button>
        </div>
    </div>
</div>
