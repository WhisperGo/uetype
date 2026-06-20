import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            colors: {
                typing: {
                    // Dark slate base (lebih biru/dingin daripada charcoal Monkeytype)
                    bg: '#0f172a',       // slate-900-ish, latar utama
                    surface: '#1e293b',  // kartu/panel
                    elevated: '#334155', // panel terangkat / border aktif
                    text: '#e2e8f0',     // teks utama
                    muted: '#94a3b8',    // teks sekunder
                    // Aksen cyan/teal futuristik (pengganti kuning Monkeytype)
                    accent: '#22d3ee',   // cyan-400, highlight utama
                    accent2: '#14b8a6',  // teal-500, aksen sekunder/grafik
                    success: '#34d399',  // emerald, untuk metrik positif
                    error: '#f43f5e',    // rose-500, error/missed
                    gold: '#facc15',     // amber-400, khusus koin/mata uang
                },
            },
            fontFamily: {
                // UI / heading: font sans modern. Area mengetik tetap pakai `font-mono`.
                sans: ['"Space Grotesk"', ...defaultTheme.fontFamily.sans],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },
            boxShadow: {
                glow: '0 0 20px -2px rgba(34, 211, 238, 0.35)',
            },
        },
    },

    plugins: [forms],
};
