/**
 * Rebuild a typing position from the character count the server saved for a Clan War attempt.
 *
 * Extracted from typing-game.js as a pure function because it is the one piece of the resume
 * path that is pure arithmetic, and getting it wrong is silent: land a character early and the
 * player retypes a letter that already counted; land one late and the caret sits mid-word with
 * inputResults disagreeing about what was typed. Neither throws, and no PHP test can see it --
 * the project runs no JavaScript in Pest.
 *
 * Only whole "word + space" spans are consumed, so the cursor always lands at the START of the
 * first unfinished word. A partial word is never restored: the saved position was recorded on
 * finished words, so a prefix was never part of it, and inventing one would put characters on
 * screen the player never typed.
 *
 * The input used to be a PERCENT, and that cost a word before this function was even reached.
 * On a ~280-character Words text one percent is nearly three characters, so the position was
 * rounded once on the way into the database and again to a word boundary here. Rounding twice
 * to solve a problem that only needs rounding once is how a player ends up several words behind
 * where they stopped. Characters cost the same to store.
 *
 * @param {Array<{start: number, end: number, space: number|null}>} wordBounds
 * @param {number} totalChars  length of the full text
 * @param {number} savedChars  characters confirmed typed, as persisted on the claim
 * @returns {{consumed: number, wordIndex: number}} characters to mark correct, and the word to
 *          resume on. `consumed` is 0 when there is nothing to restore.
 */
export function resumePosition(wordBounds, totalChars, savedChars) {
    const targetChars = Math.max(0, Math.min(totalChars, savedChars || 0));

    if (!wordBounds.length || totalChars <= 0 || targetChars <= 0) {
        return { consumed: 0, wordIndex: 0 };
    }

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
