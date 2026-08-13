import './bootstrap';

import toastStack from './toasts';
import navBadges from './nav-badges';
import typingGame from './typing-game';
import speedVerification from './speed-verification';
import chatOverlayDock from './chat-dock';
import createChatRuntime from './chat-runtime';
import { registerMultiplayerNav } from './multiplayer-nav';
import { registerFocusMode } from './focus-mode';
import './race-arena';
import './race-echo';

// Chart.js is the single biggest dependency and is used on only TWO pages (stats, result).
// Load it ON DEMAND instead of shipping it in the bundle that EVERY page -- including /typing,
// which must stay responsive -- has to download, parse and compile. The dynamic import() makes
// Vite emit Chart.js as its own chunk fetched only when a chart is actually drawn; it is still
// bundled (not a runtime CDN), so there is no supply-chain surface, IP leak, or offline break
// (see tests/Feature/NoExternalCdnTest.php). Consumers call `await window.ensureChart()` from
// their @script blocks (resources/views/livewire/stats.blade.php & typing-result.blade.php).
// See docs/review-performance-2026-07-27.md (Temuan 4 / F-2).
let chartPromise = null;
window.ensureChart = () => {
    if (! chartPromise) {
        chartPromise = import('chart.js/auto').then((module) => {
            window.Chart = module.default; // kept available globally for any late reader
            return module.default;
        });
    }

    return chartPromise;
};

// Expose the globals that Alpine x-data expressions depend on BEFORE running any feature
// setup that might throw. typing-engine's x-data spreads `...typingGame(...)`; if that
// symbol is missing when Alpine evaluates x-data, the whole component initialises with an
// empty scope and every binding (currentIndex, handleInput, ...) fails -- the typing area
// goes dead. Assigning first means a later error can't strand these.
//
// Global, not Alpine.data(): typing-engine SPREADS this component into its x-data so it
// shares scope with @entangle('mainMode'/'subMode'), which the mode-picker buttons write
// two-way. Alpine.data isn't built to be spread, so the call contract is kept as-is.
window.typingGame = typingGame;
window.speedVerification = speedVerification;

// The chat runtime is invoked from each chat component's @script (full page & overlay)
// because it needs $wire and 4 values from Blade: auth()->id(), route('chat.send'), and
// two translated labels. The module only provides the factory; Blade injects the values.
window.createChatRuntime = createChatRuntime;

// Multiplayer room nav guards (leave beacon + ready-confirm). Inert off /multiplayer.
// Guarded: a failure here must never take down the rest of the bundle (e.g. the typing
// engine's globals above), which would blank unrelated pages.
try {
    registerMultiplayerNav();
} catch (e) {
    console.error('registerMultiplayerNav failed:', e);
}

// Fades the page frame while a typing session or race runs. Guarded for the same reason as
// the call above -- and with an extra one specific to this feature: it fails CLOSED. If the
// listener never attaches, the class is never added and the frame simply stays visible, which
// is the state the page renders in anyway. A focus mode that breaks costs a little polish;
// there is no arrangement of this failing that can hide the navigation permanently.
try {
    registerFocusMode();
} catch (e) {
    console.error('registerFocusMode failed:', e);
}

// Alpine comes from @livewireScripts (not started manually here), so components are
// registered via the alpine:init hook -- the same pattern used across every view here.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('toastStack', toastStack);
    window.Alpine.data('navBadges', navBadges);
    window.Alpine.data('chatOverlayDock', chatOverlayDock);
});
