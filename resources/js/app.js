import './bootstrap';

import Chart from 'chart.js/auto';
import toastStack from './toasts';
import navBadges from './nav-badges';
import typingGame from './typing-game';
import chatOverlayDock from './chat-dock';
import createChatRuntime from './chat-runtime';
import { registerMultiplayerNav } from './multiplayer-nav';
import './race-arena';
import './race-echo';

// Multiplayer room nav guards (leave beacon + ready-confirm). Inert off /multiplayer.
registerMultiplayerNav();

window.Chart = Chart;

// Global, not Alpine.data(): typing-engine SPREADS this component into its x-data so it
// shares scope with @entangle('mainMode'/'subMode'), which the mode-picker buttons write
// two-way. Alpine.data isn't built to be spread, so the call contract is kept as-is.
window.typingGame = typingGame;

// The chat runtime is invoked from each chat component's @script (full page & overlay)
// because it needs $wire and 4 values from Blade: auth()->id(), route('chat.send'), and
// two translated labels. The module only provides the factory; Blade injects the values.
window.createChatRuntime = createChatRuntime;

// Alpine comes from @livewireScripts (not started manually here), so components are
// registered via the alpine:init hook -- the same pattern used across every view here.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('toastStack', toastStack);
    window.Alpine.data('navBadges', navBadges);
    window.Alpine.data('chatOverlayDock', chatOverlayDock);
});
