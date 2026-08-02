/**
 * Rebuild a typing position from the coarse percentage the server saved for a Clan War attempt.
 *
 * Extracted from typing-game.js as a pure function because it is the one piece of the resume
 * path that is pure arithmetic, and getting it wrong is silent: land a character early and the
 * player retypes a letter that already counted; land one late and the caret sits mid-word with
 * inputResults disagreeing about what was typed. Neither throws, and no PHP test can see it --
 * the project runs no JavaScript in Pest.
 *
 * Only whole "word + space" spans are consumed, so the cursor always lands at the START of the
 * first unfinished word. A partial word is never restored: the saved percentage was derived
 * from committed words, so a prefix was never part of it, and inventing one would put
 * characters on screen the player never typed.
 *
 * @param {Array<{start: number, end: number, space: number|null}>} wordBounds
 * @param {number} totalChars  length of the full text
 * @param {number} progressPercent  0-100, as persisted on the claim
 * @returns {{consumed: number, wordIndex: number}} characters to mark correct, and the word to
 *          resume on. `consumed` is 0 when there is nothing to restore.
 */
export function resumePosition(wordBounds, totalChars, progressPercent) {
    const percent = Math.max(0, Math.min(100, progressPercent || 0));

    if (!wordBounds.length || totalChars <= 0 || percent <= 0) {
        return { consumed: 0, wordIndex: 0 };
    }

    const targetChars = Math.round((percent / 100) * totalChars);

    let consumed = 0;
    let wordIndex = 0;

    while (wordIndex < wordBounds.length) {
        const bounds = wordBounds[wordIndex];
        // The word's own characters plus its trailing space, matching how currentIndex
        // advances when a word is committed. The last word has no space (space === null).
        const span = (bounds.end - bounds.start + 1) + (bounds.space === null ? 0 : 1);

        if (consumed + span > targetChars) break;

        consumed += span;
        wordIndex++;
    }

    return {
        consumed,
        // Clamped so wordBounds[wordIndex] is never undefined. Landing on the last word is
        // fine and never auto-finishes: finishing still requires real input.
        wordIndex: Math.min(wordIndex, wordBounds.length - 1),
    };
}
