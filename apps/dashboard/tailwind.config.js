/** @type {import('tailwindcss').Config} */

// Surface / ink / line / nav families are driven by CSS variables (channel
// form: "R G B") defined in index.css under [data-theme="light|dark"]. Using
// the channel form keeps Tailwind's `/opacity` modifier working (e.g.
// bg-surface/60). Every `bg-surface`, `text-ink`, `border-line` utility across
// the app therefore flips automatically between the light and dark themes with
// no class renaming. Brand accents (brand/indigo/sky/success/warning/danger)
// are identical in both themes, so they stay as static hex.
function withVar(variable) {
  return ({ opacityValue }) =>
    opacityValue === undefined
      ? `rgb(var(${variable}))`
      : `rgb(var(${variable}) / ${opacityValue})`;
}

export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  // Dark mode is opt-in via a `data-theme="dark"` attribute on <html>, toggled
  // by ThemeContext (respects prefers-color-scheme + localStorage).
  darkMode: ['selector', '[data-theme="dark"]'],
  theme: {
    extend: {
      colors: {
        // MarQira brand scale. The 600/700 steps are the Primary Blue / Blue
        // Dark from the design system, so every existing `brand-600`/`brand-700`
        // usage (buttons, active nav, focus rings) upgrades to the new palette
        // automatically without renaming classes across the app.
        brand: {
          50: '#eef3ff',  // pale blue
          100: '#e0e8ff',
          200: '#c3d0ff',
          300: '#9fb3ff',
          400: '#6d84ff',
          500: '#4f6bff',
          600: '#3b5bff', // Primary Blue
          700: '#2440e0', // Blue Dark
          800: '#1d34b8',
          900: '#1a2d8f',
        },
        // Named brand accents (identical in light + dark).
        indigo: {
          brand: '#6d5efc',
        },
        sky: {
          brand: '#22b8ff',
        },
        // Surfaces — theme-aware (CSS variables).
        surface: {
          DEFAULT: withVar('--surface'),
          soft: withVar('--surface-soft'),   // app background
          pale: withVar('--surface-pale'),   // pale tint
          grid: withVar('--surface-grid'),   // chart gridlines
        },
        // Text ink ramp — theme-aware (CSS variables).
        ink: {
          DEFAULT: withVar('--ink'),   // headings
          soft: withVar('--ink-soft'), // secondary
          body: withVar('--ink-body'), // body
          muted: withVar('--ink-muted'), // muted
        },
        // Borders — theme-aware (CSS variables).
        line: {
          DEFAULT: withVar('--line'),
          strong: withVar('--line-strong'),
        },
        // The dark command sidebar/nav — dark navy in BOTH themes (a light
        // sidebar in dark mode would look wrong). Slightly deeper in dark.
        nav: {
          DEFAULT: withVar('--nav-bg'),
          soft: withVar('--nav-soft'),
        },
        // Semantic (identical in both themes).
        success: {
          DEFAULT: '#10b981',
          text: '#0a8a58',
        },
        warning: {
          DEFAULT: '#f59e0b',
          text: '#b45309',
        },
        danger: {
          DEFAULT: '#ef4560',
        },
        // macOS window dots (used in marketing/browser chrome mockups).
        mac: {
          red: '#ff5f57',
          yellow: '#febc2e',
          green: '#28c840',
        },
      },
      backgroundImage: {
        // The MarQira signature gradient. Use sparingly (logo, hero, active
        // brand surfaces) — not as a wallpaper.
        'brand-gradient': 'linear-gradient(105deg, #6d5efc, #3b5bff 52%, #22b8ff)',
      },
      boxShadow: {
        card: '0 1px 2px 0 rgb(var(--shadow-rgb) / 0.04), 0 1px 3px 0 rgb(var(--shadow-rgb) / 0.06)',
        'card-hover': '0 4px 12px -2px rgb(var(--shadow-rgb) / 0.10), 0 2px 6px -2px rgb(var(--shadow-rgb) / 0.06)',
        brand: '0 6px 20px -6px rgba(59, 91, 255, 0.45)',
      },
      borderRadius: {
        // Design-system radii (--radius-sm / --radius / --radius-lg). Added as
        // NEW names so the standard Tailwind scale (rounded-lg/xl/2xl) that the
        // existing app depends on is left untouched.
        pill: '11px',
        card: '16px',
        cardlg: '22px',
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        disp: ['"Space Grotesk"', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'],
      },
    },
  },
  plugins: [],
};
