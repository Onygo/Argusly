/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './app/**/*.php',
    ],
    theme: {
        extend: {
            colors: {
                background: '#F7F7F8',
                surface: '#FFFFFF',
                surfaceSubtle: '#FAFAFB',
                surfaceMuted: '#F3F4F6',

                border: '#E5E7EB',
                borderStrong: '#D1D5DB',
                divider: '#EEF0F2',

                textPrimary: '#111827',
                textSecondary: '#4B5563',
                textMuted: '#6B7280',
                textFaint: '#9CA3AF',
                textInverse: '#FFFFFF',

                primary: '#111827',
                primaryHover: '#0B0F19',
                primarySoftBg: 'rgba(17, 24, 39, 0.06)',
                primarySoftRing: 'rgba(17, 24, 39, 0.18)',

                link: '#111827',
                linkHover: '#0B0F19',

                success: '#16A34A',
                warning: '#F59E0B',
                danger: '#EF4444',
                info: '#2563EB',
                brandYellowLight: '#FEF3C7',
                brandYellowDark: '#7C2D12',
                accentYellow: {
                    100: '#FEF3C7',
                    900: '#7C2D12',
                },

                // Public marketing site colors (isolated from app)
                publicPrimary: '#1F2A37',
                publicPrimaryHover: '#151C26',
                publicPrimarySoftBg: 'rgba(31, 42, 55, 0.08)',
                publicPrimarySoftRing: 'rgba(31, 42, 55, 0.18)',
            },
            borderRadius: {
                sm: '6px',
                md: '8px',
                lg: '10px',
            },
            boxShadow: {
                none: 'none',
                sm: '0 1px 1px rgba(0, 0, 0, 0.04)',
                md: '0 4px 12px rgba(0, 0, 0, 0.06)',
            },
            fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Monaco', 'Consolas', 'monospace'],
            },
        },
    },
};
