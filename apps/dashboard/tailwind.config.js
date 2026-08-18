/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
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
        // Named brand accents.
        indigo: {
          brand: '#6d5efc',
        },
        sky: {
          brand: '#22b8ff',
        },
        // Surfaces.
        surface: {
          DEFAULT: '#ffffff',
          soft: '#f5f7fc',   // light grey-blue app background
          pale: '#eef3ff',   // pale blue tint
          grid: '#eef1f8',   // chart gridlines
        },
        // Text ink ramp.
        ink: {
          DEFAULT: '#101a33', // headings
          soft: '#2b3654',    // secondary
          body: '#5a668a',    // body slate
          muted: '#8a93ad',   // muted
        },
        // Borders.
        line: {
          DEFAULT: '#e6eaf3',
          strong: '#d7deec',
        },
        // Semantic.
        success: {
          DEFAULT: '#10b981',
          text: '#0a8a58',
        },
        warning: {
          DEFAULT: '#f59e0b',
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
        'brand-gradient': 'linear-gradient(100deg, #6d5efc, #3b5bff 50%, #22b8ff)',
      },
      boxShadow: {
        card: '0 1px 2px 0 rgba(16, 26, 51, 0.04), 0 1px 3px 0 rgba(16, 26, 51, 0.06)',
        'card-hover': '0 4px 12px -2px rgba(16, 26, 51, 0.10), 0 2px 6px -2px rgba(16, 26, 51, 0.06)',
        brand: '0 6px 20px -6px rgba(59, 91, 255, 0.45)',
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
