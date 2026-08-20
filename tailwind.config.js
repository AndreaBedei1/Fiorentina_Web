/**
 * Design system Baraonda Fiorentina.
 *
 * Tre famiglie cromatiche soltanto: viola (identita Fiorentina), rosso (giglio /
 * accento passionale) e una scala neutra leggermente calda che le lega. Ogni
 * tonalita usata su testo e verificata da `scripts/check-contrast.php` per
 * rispettare WCAG 2.1 AA.
 */

import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.twig',
        './resources/js/**/*.{ts,js}',
        './app/**/*.php',
    ],
    theme: {
        extend: {
            colors: {
                /*
                 * Il gradino 600 e il viola ufficiale della societa
                 * (Pantone 267 C, #482E92). Tutti gli altri gradini nascono
                 * da li mantenendone la tonalita: cambia la luminosita, non
                 * il colore. Non usare un viola scelto a occhio.
                 */
                viola: {
                    50: '#f5f3fc',
                    100: '#eae5fa',
                    200: '#d4c8f5',
                    300: '#b7a5ea',
                    400: '#8b71d6',
                    500: '#603dc2',
                    600: '#482e92',
                    700: '#3b247d',
                    800: '#2f1d64',
                    900: '#221547',
                    950: '#150d2d',
                },
                rosso: {
                    50: '#fef2f3',
                    100: '#fde3e6',
                    200: '#fbccd2',
                    300: '#f7a4af',
                    400: '#f07387',
                    500: '#e34460',
                    600: '#cd2247',
                    700: '#ac173a',
                    800: '#901637',
                    900: '#7b1634',
                    950: '#450718',
                },
                sabbia: {
                    50: '#faf9f7',
                    100: '#f3f1ed',
                    200: '#e7e3dc',
                    300: '#d5cec3',
                    400: '#b8ae9e',
                    500: '#9c9081',
                    600: '#7a6f63',
                    700: '#6b6157',
                    800: '#57504a',
                    900: '#3d3833',
                    950: '#231f1c',
                },
            },
            fontFamily: {
                /*
                 * Stack di sistema: nessun webfont di terze parti, quindi zero
                 * richieste esterne, nessun consenso cookie aggiuntivo e LCP piu
                 * rapido. Per adottare un webfont self-hosted basta anteporlo qui
                 * e dichiarare @font-face in resources/css/base/fonts.css.
                 */
                sans: ['Inter var', 'Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
                display: ['Archivo Expanded', 'Archivo', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
                mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'monospace'],
            },
            fontSize: {
                '2xs': ['0.6875rem', { lineHeight: '1rem', letterSpacing: '0.06em' }],
                'display-sm': ['clamp(1.75rem, 1.2rem + 2.4vw, 2.5rem)', { lineHeight: '1.1', letterSpacing: '-0.02em' }],
                'display-md': ['clamp(2.25rem, 1.4rem + 3.6vw, 3.5rem)', { lineHeight: '1.05', letterSpacing: '-0.025em' }],
                'display-lg': ['clamp(2.75rem, 1.5rem + 5.2vw, 4.75rem)', { lineHeight: '1', letterSpacing: '-0.03em' }],
                'display-xl': ['clamp(3.25rem, 1.6rem + 7vw, 6rem)', { lineHeight: '0.95', letterSpacing: '-0.035em' }],
            },
            spacing: {
                // Passi intermedi non presenti nella scala predefinita: servono
                // alle icone dell interfaccia amministrativa (18px, 22px).
                4.5: '1.125rem',
                5.5: '1.375rem',
                18: '4.5rem',
                22: '5.5rem',
                30: '7.5rem',
                section: 'clamp(3.5rem, 2rem + 6vw, 7rem)',
            },
            borderRadius: {
                card: '1rem',
                panel: '1.5rem',
            },
            boxShadow: {
                card: '0 1px 2px 0 rgb(26 13 39 / 0.04), 0 8px 24px -12px rgb(26 13 39 / 0.18)',
                'card-lift': '0 2px 4px 0 rgb(26 13 39 / 0.06), 0 24px 48px -20px rgb(26 13 39 / 0.28)',
                panel: '0 1px 3px 0 rgb(26 13 39 / 0.06), 0 16px 40px -24px rgb(26 13 39 / 0.24)',
            },
            maxWidth: {
                'prose-wide': '72ch',
            },
            aspectRatio: {
                poster: '3 / 4',
                card: '4 / 3',
            },
            transitionTimingFunction: {
                'out-expo': 'cubic-bezier(0.16, 1, 0.3, 1)',
                spring: 'cubic-bezier(0.34, 1.56, 0.64, 1)',
            },
            keyframes: {
                'fade-up': {
                    '0%': { opacity: '0', transform: 'translateY(1rem)' },
                    '100%': { opacity: '1', transform: 'none' },
                },
                'fade-in': {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                'scale-in': {
                    '0%': { opacity: '0', transform: 'scale(0.97)' },
                    '100%': { opacity: '1', transform: 'none' },
                },
                marquee: {
                    '0%': { transform: 'translateX(0)' },
                    '100%': { transform: 'translateX(-50%)' },
                },
            },
            animation: {
                'fade-up': 'fade-up 0.5s cubic-bezier(0.16, 1, 0.3, 1) both',
                'fade-in': 'fade-in 0.4s ease-out both',
                'scale-in': 'scale-in 0.25s cubic-bezier(0.16, 1, 0.3, 1) both',
                marquee: 'marquee 30s linear infinite',
            },
            typography: (theme) => ({
                baraonda: {
                    css: {
                        '--tw-prose-body': theme('colors.sabbia.800'),
                        '--tw-prose-headings': theme('colors.viola.900'),
                        '--tw-prose-links': theme('colors.rosso.700'),
                        '--tw-prose-bold': theme('colors.viola.900'),
                        '--tw-prose-quotes': theme('colors.viola.800'),
                        '--tw-prose-quote-borders': theme('colors.rosso.600'),
                        '--tw-prose-bullets': theme('colors.viola.400'),
                        '--tw-prose-counters': theme('colors.viola.700'),
                        '--tw-prose-hr': theme('colors.sabbia.200'),
                        '--tw-prose-captions': theme('colors.sabbia.700'),
                        a: {
                            textUnderlineOffset: '0.2em',
                            textDecorationThickness: '0.08em',
                        },
                        'h2, h3, h4': {
                            fontFamily: theme('fontFamily.display').join(', '),
                            letterSpacing: '-0.02em',
                        },
                    },
                },
            }),
        },
    },
    plugins: [
        forms({ strategy: 'class' }),
        typography,
    ],
};
