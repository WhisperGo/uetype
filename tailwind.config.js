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
                    bg: '#323437',
                    surface: '#2c2e31',
                    text: '#d1d0c5',
                    muted: '#646669',
                    accent: '#eab308',
                    error: '#ca4754'
                }
            },
            fontFamily: {
                sans: ['"JetBrains Mono"', ...defaultTheme.fontFamily.sans],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },
        },
    },

    plugins: [forms],
};
