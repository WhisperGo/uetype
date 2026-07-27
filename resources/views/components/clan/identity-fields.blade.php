{{-- The clan identity form, shared by the Create tab and the leader's Edit form: both ask
     for the same five values, so one copy keeps them from drifting.

     Field names are props because the callers bind to different Livewire properties
     (new* vs edit*); error keys follow those names, so @error works unchanged. --}}
@props([
    'name',
    'tag',
    'emblem',
    'color',
    'description',
    'nameValue' => '',
    'tagValue' => '',
    'emblemValue' => '',
    'colorValue' => '',
    'descriptionValue' => '',
])

@php
    // text-base (16px), NOT text-sm: iOS Safari zooms the whole page when a focused field is
    // under 16px and never restores it. Applies to both <input>s and the <textarea> below,
    // since they all share this string. See ViewportUnitTest / docs/mobile-test-checklist.md.
    $field = 'w-full mt-1.5 px-4 py-3 bg-surface/40 border border-white/10 rounded-xl font-mono text-base text-foreground placeholder-muted focus:border-gold/50 focus:ring-0 transition';
    $label = 'font-mono text-xs uppercase tracking-widest text-muted';
@endphp

<div class="space-y-5">
    {{-- Live preview: shows the emblem, name and tag exactly as the roster will render
         them, so a colour or icon choice can be judged before saving. --}}
    <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl">
        <x-clan-emblem :clan="(object) ['name' => $nameValue ?: '?', 'emblem' => $emblemValue, 'emblem_color' => $colorValue]" size="lg" />
        <div class="min-w-0">
            <p class="font-mono text-sm font-bold text-foreground truncate">{{ $nameValue ?: __('clan.create.preview_name') }}
                @if (trim($tagValue) !== '')<span class="text-muted font-normal">[{{ $tagValue }}]</span>@endif
            </p>
            <p class="font-mono text-xs text-muted mt-0.5 truncate">{{ $descriptionValue ?: __('clan.create.preview_desc') }}</p>
        </div>
    </div>

    <div>
        <label for="{{ $name }}" class="{{ $label }}">{{ __('clan.create.name_label') }}</label>
        <input id="{{ $name }}" type="text" wire:model.live="{{ $name }}" maxlength="40"
            placeholder="{{ __('clan.create.name_placeholder') }}" class="{{ $field }}">
        @error($name)<p class="font-mono text-xs text-danger mt-1.5">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="{{ $tag }}" class="{{ $label }}">{{ __('clan.create.tag_label') }}</label>
        <input id="{{ $tag }}" type="text" wire:model.live="{{ $tag }}" maxlength="6"
            placeholder="{{ __('clan.create.tag_placeholder') }}" class="{{ $field }}">
        @error($tag)<p class="font-mono text-xs text-danger mt-1.5">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="{{ $description }}" class="{{ $label }}">{{ __('clan.create.desc_label') }}</label>
        <textarea id="{{ $description }}" wire:model.live="{{ $description }}" maxlength="160" rows="2"
            placeholder="{{ __('clan.create.desc_placeholder') }}" class="{{ $field }} resize-none"></textarea>
        @error($description)<p class="font-mono text-xs text-danger mt-1.5">{{ $message }}</p>@enderror
    </div>

    {{-- Emblem picker --}}
    <div>
        <span class="{{ $label }}">{{ __('clan.create.emblem_label') }}</span>
        <div class="mt-2 grid grid-cols-6 sm:grid-cols-8 gap-2">
            @foreach (\App\Support\ClanEmblem::icons() as $key => $path)
                <button type="button" wire:click="$set('{{ $emblem }}', '{{ $key }}')"
                    aria-label="{{ __('clan.aria.emblem', ['key' => $key]) }}"
                    @if ($emblemValue === $key) aria-pressed="true" @endif
                    @class([
                        'aspect-square flex items-center justify-center rounded-lg border transition',
                        'border-gold bg-gold/15 text-gold' => $emblemValue === $key,
                        'border-white/10 text-muted hover:text-foreground hover:border-white/20' => $emblemValue !== $key,
                    ])>
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $path }}" /></svg>
                </button>
            @endforeach
        </div>
        @error($emblem)<p class="font-mono text-xs text-danger mt-1.5">{{ $message }}</p>@enderror
    </div>

    {{-- Colour picker --}}
    <div>
        <span class="{{ $label }}">{{ __('clan.create.color_label') }}</span>
        <div class="mt-2 flex flex-wrap gap-2.5">
            @foreach (\App\Support\ClanEmblem::colors() as $key => $hex)
                <button type="button" wire:click="$set('{{ $color }}', '{{ $key }}')"
                    aria-label="{{ __('clan.aria.color', ['key' => $key]) }}"
                    @if ($colorValue === $key) aria-pressed="true" @endif
                    class="w-8 h-8 rounded-full border-2 transition {{ $colorValue === $key ? 'ring-2 ring-offset-2 ring-offset-background ring-white/60 border-white/60' : 'border-white/10 hover:border-white/30' }}"
                    style="background-color: {{ $hex }};"></button>
            @endforeach
        </div>
        @error($color)<p class="font-mono text-xs text-danger mt-1.5">{{ $message }}</p>@enderror
    </div>
</div>
