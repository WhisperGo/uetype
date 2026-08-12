import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

// Merujuk CSS variable berformat channel agar modifier opacity
// Tailwind tetap jalan (mis. `bg-surface/50`) & tema bisa ditukar lewat :root di app.css.
const token = (cssVar) => `rgb(var(${cssVar}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
    // `hover:` only applies where hovering actually exists (@media (hover: hover)).
    //
    // Without it, a tap on a touch screen leaves the element in its hover state until you
    // tap elsewhere -- a button stays lit long after you have moved on, which reads as a
    // stuck selection.
    //
    // The flag FIXES nothing by itself; it makes existing hover-dependence VISIBLE. Anything
    // whose resting state is unusable without hover becomes permanently unusable on a phone.
    // So it was turned on only after those were dealt with: the live-stats panel in
    // typing-engine (dimmed to 60%, restored on hover -> now full opacity on coarse pointers)
    // and the un-friend button (was `opacity-0 group-hover:opacity-100`, i.e. invisible on
    // touch -> now always shown). The remaining `group-hover:` uses only enhance an element
    // that is already legible at rest, so they degrade harmlessly.
    future: {
        hoverOnlyWhenSupported: true,
    },

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        // `screens` is set at the top level, not inside `extend`: keys inside `extend` are
        // appended after the defaults, so `xs` would sort AFTER `2xl` and its media query
        // would lose to every other breakpoint. Listing the full ladder keeps ascending
        // order, which is what mobile-first cascading depends on.
        //
        // `xs` exists because there was previously no way to target 360-420px at all: `sm`
        // (640px) is already a phone in landscape, so the entire portrait-phone range --
        // the majority of usage -- could only be reached through base classes that apply
        // everywhere at once.
        //
        // 400px, not 480px: the boundary has to fall BETWEEN small phones (360/390) and
        // large ones (414/430) so `xs:` means "past the narrowest screens". At 480px it
        // would switch on for every phone and separate nothing.
        screens: {
            xs: '400px',
            sm: '640px',
            md: '768px',
            lg: '1024px',
            xl: '1280px',
            '2xl': '1536px',
        },

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
                active: {
                    DEFAULT: token('--color-active'),
                    1: '#E8F5EE', 2: '#C7E7D6', 3: '#9AD4B5', 4: '#6CC093', 5: '#3FAD73',
                    6: '#159B54', 7: '#128447', 8: '#0F6E3C', 9: '#0C5830', 10: '#094626',
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
                sans: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
                pixel: ['"Pixelify Sans"', ...defaultTheme.fontFamily.sans],
                display: ['"Press Start 2P"', ...defaultTheme.fontFamily.mono],
            },

            // Skala modular (~1.2). lineHeight 1 = "100%" (mengikuti Figma).
            //
            // JANGAN ubah nilai-nilai ini untuk memperbaiki tinggi tombol. Konsekuensinya
            // memang nyata -- kotak baris persis setinggi font, jadi tinggi tombol yang
            // digerakkan padding = font-size + padding saja, dan `py-[5px] text-small`
            // keluar ~23px -- tapi skala ini mengikuti prototype dan bukan milik kode untuk
            // diubah. Perbaiki di tombolnya: beri ukuran eksplisit (lihat <x-icon-button>,
            // min-h-[44px]) atau longgarkan per elemen dengan `leading-*`.
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

                'fluid-type': ['clamp(1.375rem, 1.1rem + 1.4vw, 1.875rem)', { lineHeight: '1.6' }],
                'fluid-title': ['clamp(1.5rem, 1.2rem + 1.5vw, 2rem)', { lineHeight: '1.15' }],
                'fluid-timer': ['clamp(2.25rem, 1.5rem + 3.75vw, 3rem)', { lineHeight: '1' }],
                'fluid-hero': ['clamp(2.75rem, 1.5rem + 6.25vw, 4.5rem)', { lineHeight: '1' }],
            },

            boxShadow: {
                glow: '0 8px 24px -8px rgba(0, 0, 0, 0.5)',
            },
        },
    },

    plugins: [forms],
};
