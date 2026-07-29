{{--
    The only action offered after a Clan War attempt: return to the war. A war run is
    one-shot (its claim is now filled), so the solo Next Test / Retry buttons don't apply.

    Plain full page load (NO wire:navigate): leaving /result into /clan-war via the SPA would
    let the Back button restore a broken snapshot -- the same reason /typing's own war exit is
    a hard link. A full load lands cleanly and re-runs ClanWar::mount (lazy war resolution).
--}}
<a href="{{ route('clan-war.index') }}"
    class="w-full inline-flex items-center justify-center gap-2 h-12 rounded-2xl bg-gold text-background font-mono font-semibold text-sm hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold/50 transition">
    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
    </svg>
    <span>{{ __('result.back_to_war') }}</span>
</a>
