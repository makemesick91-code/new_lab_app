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
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },

            // UIX-1 Luxury Healthcare Design System — semantic tokens.
            // Do NOT hardcode raw #hex in Blade; use these token classes.
            colors: {
                // Brand dark / primary text.
                navy: {
                    DEFAULT: '#0F2540',
                    50: '#F2F5F9',
                    100: '#E2E8F0',
                    500: '#0F2540',
                    600: '#0C1E36',
                    700: '#0A1B32',
                    900: '#071426',
                },
                // Primary action / active / link / focus (blue).
                brand: {
                    DEFAULT: '#2563EB',
                    50: '#EFF4FF',
                    100: '#DBEAFE',
                    200: '#BFD7FE',
                    500: '#3B82F6',
                    600: '#2563EB',
                    700: '#1D4ED8',
                    800: '#1E40AF',
                },
                // Premium accent only — never a primary CTA.
                gold: {
                    DEFAULT: '#C8A45C',
                    100: '#F6EEDD',
                    200: '#EAD9B0',
                    500: '#C8A45C',
                    600: '#A9863F',
                    700: '#7A5A15',
                },
                // Surfaces & text.
                canvas: '#F7F9FC',
                surface: '#FFFFFF',
                ink: {
                    DEFAULT: '#0F2540',
                    soft: '#5B6B7F',
                    muted: '#8A97A8',
                },
                hairline: '#E3E8EF',

                // Status.
                success: {
                    DEFAULT: '#059669',
                    50: '#ECFDF5',
                    100: '#D1FAE5',
                    700: '#047857',
                },
                danger: {
                    DEFAULT: '#DC2626',
                    50: '#FEF2F2',
                    100: '#FEE2E2',
                    700: '#B91C1C',
                },
                warning: {
                    DEFAULT: '#D97706',
                    50: '#FFFBEB',
                    100: '#FEF3C7',
                    700: '#B45309',
                },
                info: {
                    DEFAULT: '#2563EB',
                    50: '#EFF4FF',
                    100: '#DBEAFE',
                    700: '#1D4ED8',
                },
            },

            boxShadow: {
                card: '0 1px 2px rgba(15,37,64,0.06), 0 4px 16px rgba(15,37,64,0.06)',
                'card-hover': '0 2px 4px rgba(15,37,64,0.08), 0 8px 24px rgba(15,37,64,0.10)',
                focusring: '0 0 0 3px rgba(37,99,235,0.25)',
            },

            borderRadius: {
                xl: '12px',
                '2xl': '16px',
            },
        },
    },

    plugins: [forms],
};
