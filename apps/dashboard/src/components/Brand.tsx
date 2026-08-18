import clsx from 'clsx';

interface BrandProps {
  /** Show the "Pulse" wordmark next to the logo mark. */
  showWordmark?: boolean;
  /** Optional caption under the wordmark (e.g. "Site monitoring"). */
  caption?: string;
  /** Colour of the wordmark text — light on dark sidebars, ink on light bgs. */
  tone?: 'light' | 'dark';
  className?: string;
}

/**
 * The MarQira Pulse brand mark: a pulse/heartbeat glyph inside the signature
 * gradient tile, followed by the "MarQira Pulse" wordmark. This is the single
 * reusable brand component — never duplicate the raw SVG/markup elsewhere.
 */
export function BrandMark({ className }: { className?: string }) {
  return (
    <span className={clsx('brand-logo', className)}>
      <svg viewBox="0 0 24 24" fill="none" className="h-5 w-5" aria-hidden="true">
        <path
          d="M2 12h4l2-7 4 14 2-7h8"
          stroke="#fff"
          strokeWidth="2.2"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
      </svg>
    </span>
  );
}

export default function Brand({
  showWordmark = true,
  caption,
  tone = 'dark',
  className,
}: BrandProps) {
  return (
    <span className={clsx('inline-flex items-center gap-2.5', className)}>
      <BrandMark />
      {showWordmark && (
        <span className="leading-tight">
          <span
            className={clsx(
              'block text-[15px] font-extrabold tracking-tight',
              tone === 'light' ? 'text-white' : 'text-ink',
            )}
          >
            MarQira <span className="brand-accent">Pulse</span>
          </span>
          {caption && (
            <span
              className={clsx(
                'block text-xs font-medium',
                tone === 'light' ? 'text-white/60' : 'text-ink-muted',
              )}
            >
              {caption}
            </span>
          )}
        </span>
      )}
    </span>
  );
}
