import { afterEach, describe, expect, it, vi } from 'vitest';
import typingGame from './typing-game';

function shortcutHarness() {
    const game = typingGame('alpha beta');
    game.isStarted = true;
    game.currentIndex = 5;
    game.currentWordIndex = 0;
    game.wordBounds = [{ start: 0, space: 5 }];
    game.trackIdle = vi.fn();
    game.schedulePositionUpdate = vi.fn();
    game.deleteOneStep = vi.fn(function () {
        if (this.currentIndex <= 0) return false;
        this.currentIndex--;
        return true;
    });

    return game;
}

describe('platform word-delete shortcuts', () => {
    afterEach(() => vi.restoreAllMocks());

    it.each([
        ['Windows/Linux Ctrl', { ctrlKey: true, altKey: false, metaKey: false }],
        ['macOS Option', { ctrlKey: false, altKey: true, metaKey: false }],
    ])('deletes the current word with %s + Backspace', (_label, modifiers) => {
        const game = shortcutHarness();

        game.handleInput({
            key: 'Backspace',
            ...modifiers,
            preventDefault: vi.fn(),
        });

        expect(game.currentIndex).toBe(0);
        expect(game.deleteOneStep).toHaveBeenCalledTimes(5);
    });

    it('preserves modifiers when the focusable typing input forwards Backspace', () => {
        const game = typingGame('alpha beta');
        game.handleInput = vi.fn();

        game.feedKey('Backspace', { altKey: true });

        expect(game.handleInput).toHaveBeenCalledWith(expect.objectContaining({
            key: 'Backspace',
            altKey: true,
            ctrlKey: false,
            metaKey: false,
        }));
    });
});
