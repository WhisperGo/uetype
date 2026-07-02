import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

// Merujuk CSS variable berformat channel agar modifier opacity
// Tailwind tetap jalan (mis. `bg-surface/50`) & tema bisa ditukar lewat :root di app.css.
const token = (cssVar) => `rgb(var(${cssVar}) / <alpha-value>)`;

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
                // Primitive - cerminan lengkap Figma Prototype (1–10).
                primary: {
                    1: '#E8EAF4', 2: '#C7CDE5', 3: '#9BA5D1', 4: '#6C7BBB', 5: '#4054A6',
                    6: '#162E93', 7: '#13277D', 8: '#102168', 9: '#0D1A54', 10: '#0A1542',
                },
                secondary: {
                    1: '#F9F5F0', 2: '#F1E8DB', 3: '#E6D6BE', 4: '#DBC3A0', 5: '#D0B083',
                    6: '#C69F68', 7: '#A88758', 8: '#8D714A', 9: '#715B3B', 10: '#59482F',
                },
                accent: {
                    1: '#E8E9EA', 2: '#C8C9CB', 3: '#9C9FA3', 4: '#6E7278', 5: '#42474F',
                    6: '#191F28', 7: '#151A22', 8: '#12161C', 9: '#0E1217', 10: '#0B0E12',
                },
                tertiary: {
                    1: '#F8E8EA', 2: '#EEC8CC', 3: '#E09DA4', 4: '#D26F7A', 5: '#C54452',
                    6: '#B81B2C', 7: '#9C1725', 8: '#83131F', 9: '#690F19', 10: '#530C14',
                },

                // Semantic - token peran yang dipakai komponen (nilainya di app.css :root).
                background: token('--color-background'),
                surface: token('--color-surface'),
                elevated: token('--color-elevated'),
                foreground: token('--color-foreground'),
                muted: token('--color-muted'),
                border: token('--color-border'),
                brand: token('--color-brand'),
                'brand-bright': token('--color-brand-bright'),
                gold: token('--color-gold'),
                danger: token('--color-danger'),

                // Deprecated - dipakai view lama, jangan untuk kode baru.
                typing: {
                    bg: '#0f172a',
                    surface: '#1e293b',
                    elevated: '#334155',
                    text: '#e2e8f0',
                    muted: '#94a3b8',
                    accent: '#22d3ee',
                    accent2: '#14b8a6',
                    success: '#34d399',
                    error: '#f43f5e',
                    gold: '#facc15',
                },
            },

            fontFamily: {
                sans: ['"Space Grotesk"', ...defaultTheme.fontFamily.sans],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
                pixel: ['"Pixelify Sans"', ...defaultTheme.fontFamily.sans],
                display: ['"Press Start 2P"', ...defaultTheme.fontFamily.mono],
            },

            // Skala modular (~1.2). lineHeight 1 = "100%";
            fontSize: {
                h1: ['47.78px', { lineHeight: '1' }],
                h2: ['39.81px', { lineHeight: '1' }],
                h3: ['33.18px', { lineHeight: '1' }],
                h4: ['27.65px', { lineHeight: '1' }],
                h5: ['23.04px', { lineHeight: '1' }],
                h6: ['19.20px', { lineHeight: '1' }],
                body: ['16px', { lineHeight: '1' }],
                small: ['13.33px', { lineHeight: '1' }],
                'x-small': ['11.11px', { lineHeight: '1' }],
            },

            boxShadow: {
                glow: '0 8px 24px -8px rgba(0, 0, 0, 0.5)',
            },
        },
    },

    plugins: [forms],
};
