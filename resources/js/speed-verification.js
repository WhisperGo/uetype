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
        ticker: null,
        tamperedInput: false,

        async start() {
            if (this.starting || this.active || this.submitting) return;

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
            this.tamperedInput = false;
            this.active = true;
            this.startedAt = performance.now();
            this.$nextTick(() => this.$refs.input?.focus({ preventScroll: true }));

            clearInterval(this.ticker);
            this.ticker = setInterval(() => {
                const elapsed = (performance.now() - this.startedAt) / 1000;
                this.remaining = Math.max(0, Math.ceil(this.durationSeconds - elapsed));

                if (elapsed >= this.durationSeconds) this.finish();
            }, 100);
        },

        record(key) {
            if (! this.active) return;

            const at = Math.min(this.durationSeconds * 1000, performance.now() - this.startedAt);
            this.events.push({ key, at_ms: Math.round(at), type: 'keydown' });
        },

        beforeInput(event) {
            event.preventDefault();
            if (! this.active || this.submitting) return;

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
