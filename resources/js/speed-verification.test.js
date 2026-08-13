import { afterEach, describe, expect, it, vi } from 'vitest';
import speedVerification from './speed-verification';

function verificationHarness() {
    const wire = {
        challengeText: 'alpha beta gamma',
        challengeToken: 'token',
        startChallenge: vi.fn().mockResolvedValue(true),
        beginChallenge: vi.fn().mockResolvedValue(true),
        submitEvents: vi.fn().mockResolvedValue({ passed: true }),
    };
    const component = speedVerification(wire, 30);
    const input = {
        value: '',
        focus: vi.fn(),
        setSelectionRange: vi.fn(),
    };

    component.$refs = { input };
    component.$nextTick = (callback) => callback();

    return { component, wire, input };
}

describe('speed verification input lifecycle', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('focuses during the Start gesture but waits for the first input before timing', async () => {
        const { component, wire, input } = verificationHarness();

        const starting = component.start();

        expect(input.focus).toHaveBeenCalledOnce();
        await starting;

        expect(wire.startChallenge).toHaveBeenCalledOnce();
        expect(component.active).toBe(true);
        expect(component.clockStarted).toBe(false);
        expect(component.remaining).toBe(30);
    });

    it('starts both clocks on the first accepted character and records that character', async () => {
        vi.useFakeTimers();
        const { component, wire } = verificationHarness();
        await component.start();

        component.beforeInput({
            preventDefault: vi.fn(),
            inputType: 'insertText',
            data: 'a',
        });

        expect(wire.beginChallenge).toHaveBeenCalledOnce();
        expect(component.clockStarted).toBe(true);
        expect(component.typed).toBe('a');
        expect(component.events).toEqual([{ key: 'a', at_ms: 0, type: 'keydown' }]);

        await vi.advanceTimersByTimeAsync(1000);
        expect(component.remaining).toBe(29);
    });

    it('accepts the platform word-delete event without marking the attempt as tampered', async () => {
        const { component } = verificationHarness();
        await component.start();
        component.typed = 'alpha beta';

        component.beforeInput({
            preventDefault: vi.fn(),
            inputType: 'deleteWordBackward',
            data: null,
        });

        expect(component.typed).toBe('alpha ');
        expect(component.events[0].key).toBe('BackspaceWord');
        expect(component.tamperedInput).toBe(false);
    });
});
