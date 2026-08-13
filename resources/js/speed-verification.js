export default function speedVerification(wire, durationSeconds = 30) {
    return {
        wire,
        durationSeconds,
        text: '',
        typed: '',
        events: [],
        active: false,
        starting: false,
        submitting: false,
        remaining: durationSeconds,
        startedAt: 0,
        clockStarted: false,
        ticker: null,
        tamperedInput: false,

        async start() {
            if (this.starting || this.active || this.submitting) return;

            // This runs inside the click gesture, before the network request. Keeping the
            // hidden textarea focused avoids losing the first character on strict browsers.
            this.focusInput();
            this.starting = true;
            let started = false;

            try {
                started = await this.wire.startChallenge();
            } finally {
                this.starting = false;
            }

            if (! started) return;

            this.text = this.wire.challengeText;
            this.typed = '';
            this.events = [];
            this.remaining = this.durationSeconds;
            this.clockStarted = false;
            this.tamperedInput = false;
            this.active = true;
            this.$nextTick(() => this.focusInput());
        },

        focusInput() {
            const input = this.$refs.input;
            if (! input) return;

            input.focus({ preventScroll: true });
            input.setSelectionRange(input.value.length, input.value.length);
        },

        beginClock() {
            if (this.clockStarted) return;

            this.clockStarted = true;
            this.startedAt = performance.now();
            void this.wire.beginChallenge(this.wire.challengeToken);
            clearInterval(this.ticker);
            this.ticker = setInterval(() => {
                const elapsed = (performance.now() - this.startedAt) / 1000;
                this.remaining = Math.max(0, Math.ceil(this.durationSeconds - elapsed));

                if (elapsed >= this.durationSeconds) this.finish();
            }, 100);
        },

        record(key) {
            if (! this.active) return;

            this.beginClock();
            const at = Math.min(this.durationSeconds * 1000, performance.now() - this.startedAt);
            this.events.push({ key, at_ms: Math.round(at), type: 'keydown' });
        },

        beforeInput(event) {
            event.preventDefault();
            if (! this.active || this.submitting) return;

            if (event.inputType === 'deleteWordBackward') {
                this.record('BackspaceWord');
                this.deleteWordBackward();
                return;
            }

            if (event.inputType === 'deleteContentBackward') {
                this.record('Backspace');
                this.typed = this.typed.slice(0, -1);
                return;
            }

            const acceptedInsert = event.inputType === 'insertText'
                || event.inputType === 'insertCompositionText';

            if (! acceptedInsert || typeof event.data !== 'string' || [...event.data].length !== 1) {
                this.tamperedInput = true;
                return;
            }

            const key = event.data.toLowerCase();
            this.record(key);
            this.typed += key;
        },

        deleteWordBackward() {
            let index = this.typed.length;

            // Match native Option/Ctrl+Backspace: consume trailing separators first, then
            // the preceding word. The server independently replays the same operation.
            while (index > 0 && this.typed[index - 1] === ' ') index--;
            while (index > 0 && this.typed[index - 1] !== ' ') index--;

            this.typed = this.typed.slice(0, index);
        },

        preventTransfer(event) {
            event.preventDefault();
            this.tamperedInput = true;
        },

        classFor(index, expected) {
            if (index >= this.typed.length) return index === this.typed.length ? 'text-foreground bg-brand/20' : 'text-muted';
            return this.typed[index] === expected ? 'text-brand-bright' : 'text-danger bg-danger/10';
        },

        async finish() {
            if (! this.active || this.submitting) return;

            this.active = false;
            this.clockStarted = false;
            this.submitting = true;
            this.remaining = 0;
            clearInterval(this.ticker);

            if (this.tamperedInput) this.events = [];

            try {
                await this.wire.submitEvents(this.events, this.wire.challengeToken);
            } finally {
                this.submitting = false;
            }
        },

        destroy() {
            clearInterval(this.ticker);
        },
    };
}
