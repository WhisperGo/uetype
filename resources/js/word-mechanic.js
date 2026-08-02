export function evaluateTyping(targetWord, typedText, prevTypedLength) {
    // Karakter BARU, bukan hasil backspace: hanya ini yang boleh menambah hitungan.
    const isNewChar = typedText.length > prevTypedLength;

    // Field kosong selalu prefiks yang sah ('anything'.startsWith('') === true).
    const isPrefix = targetWord.startsWith(typedText);

    return {
        hasError: !isPrefix,
        keystrokes: isNewChar ? 1 : 0,
        mistakes: isNewChar && !isPrefix ? 1 : 0,
    };
}