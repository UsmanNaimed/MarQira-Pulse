import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';

/** Respect the user's reduced-motion preference for all animated primitives. */
function prefersReducedMotion(): boolean {
  if (typeof window === 'undefined') return false;
  return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

/* -------------------------------------------------------------------------- */
/* CountUp — animates a number from 0 to `value` on mount.                    */
/* -------------------------------------------------------------------------- */
export function CountUp({
  value,
  className,
  duration = 850,
}: {
  value: number;
  className?: string;
  duration?: number;
}) {
  const [display, setDisplay] = useState(() => (prefersReducedMotion() ? value : 0));
  const frame = useRef<number>();

  useEffect(() => {
    if (prefersReducedMotion()) {
      setDisplay(value);
      return;
    }
    let start: number | null = null;
    const step = (t: number) => {
      if (start === null) start = t;
      const p = Math.min((t - start) / duration, 1);
      // easeOutCubic
      const eased = 1 - Math.pow(1 - p, 3);
      setDisplay(Math.round(eased * value));
      if (p < 1) frame.current = requestAnimationFrame(step);
    };
    setDisplay(0);
    frame.current = requestAnimationFrame(step);
    return () => {
      if (frame.current) cancelAnimationFrame(frame.current);
    };
  }, [value, duration]);

  return <span className={clsx('tnum', className)}>{display.toLocaleString('en-US')}</span>;
}

/* -------------------------------------------------------------------------- */
/* Catmull-Rom → cubic-bezier smoothing for area/line charts.                 */
/* -------------------------------------------------------------------------- */
function smoothPath(points: [number, number][]): string {
  if (points.length === 0) return '';
  if (points.length < 3) return 'M' + points.map((q) => q.join(',')).join(' L');
  let d = `M${points[0][0].toFixed(1)},${points[0][1].toFixed(1)}`;
  for (let i = 0; i < points.length - 1; i++) {
    const p0 = points[i - 1] || points[i];
    const p1 = points[i];
    const p2 = points[i + 1];
    const p3 = points[i + 2] || p2;
    const c1x = p1[0] + (p2[0] - p0[0]) / 6;
    const c1y = p1[1] + (p2[1] - p0[1]) / 6;
    const c2x = p2[0] - (p3[0] - p1[0]) / 6;
    const c2y = p2[1] - (p3[1] - p1[1]) / 6;
    d += ` C${c1x.toFixed(1)},${c1y.toFixed(1)} ${c2x.toFixed(1)},${c2y.toFixed(1)} ${p2[0].toFixed(1)},${p2[1].toFixed(1)}`;
  }
  return d;
}

/* -------------------------------------------------------------------------- */
/* Sparkline — tiny inline line chart from a REAL data series.                */
/* Renders nothing when there is no data (caller shows an empty state).       */
/* -------------------------------------------------------------------------- */
export function Sparkline({
  data,
  color = '#3b5bff',
  width = 84,
  height = 26,
  className,
}: {
  data: number[];
  color?: string;
  width?: number;
  height?: number;
  className?: string;
}) {
  if (!data || data.length < 2) return null;
  const min = Math.min(...data);
  const max = Math.max(...data);
  const range = max - min || 1;
  const pts: [number, number][] = data.map((y, i) => [
    (i / (data.length - 1)) * width,
    height - ((y - min) / range) * (height - 4) - 2,
  ]);
  return (
    <svg
      className={className}
      viewBox={`0 0 ${width} ${height}`}
      preserveAspectRatio="none"
      style={{ width: '100%', height: '100%', display: 'block' }}
    >
      <path
        d={smoothPath(pts)}
        fill="none"
        stroke={color}
        strokeWidth={1.8}
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

/* -------------------------------------------------------------------------- */
/* AreaChart — smoothed area + line + end dot for a REAL series.              */
/* `domain` sets the y-scale (e.g. [0,100] for uptime %). Auto if omitted.    */
/* -------------------------------------------------------------------------- */
export function AreaChart({
  values,
  color = '#3b5bff',
  height = 220,
  domain,
  gradientId,
  className,
}: {
  values: number[];
  color?: string;
  height?: number;
  domain?: [number, number];
  gradientId: string;
  className?: string;
}) {
  const W = 600;
  const H = height;
  const pad = 18;
  if (!values || values.length === 0) return null;
  const lo = domain ? domain[0] : Math.min(...values);
  const hiRaw = domain ? domain[1] : Math.max(...values);
  const hi = hiRaw === lo ? lo + 1 : hiRaw;
  const n = values.length;
  const pts: [number, number][] = values.map((v, i) => [
    n === 1 ? W / 2 : (i / (n - 1)) * W,
    H - pad - ((Math.max(lo, Math.min(hi, v)) - lo) / (hi - lo)) * (H - pad * 2),
  ]);
  const line = smoothPath(pts);
  const area = `${line} L${W},${H - pad} L0,${H - pad} Z`;
  const last = pts[n - 1];
  return (
    <svg
      className={className}
      viewBox={`0 0 ${W} ${H}`}
      preserveAspectRatio="none"
      style={{ width: '100%', height, display: 'block' }}
    >
      <defs>
        <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor={color} stopOpacity="0.26" />
          <stop offset="1" stopColor={color} stopOpacity="0" />
        </linearGradient>
      </defs>
      <line x1="0" y1={H * 0.25} x2={W} y2={H * 0.25} stroke="rgb(var(--line))" />
      <line x1="0" y1={H * 0.5} x2={W} y2={H * 0.5} stroke="rgb(var(--line))" />
      <line x1="0" y1={H * 0.75} x2={W} y2={H * 0.75} stroke="rgb(var(--line))" />
      <path d={area} fill={`url(#${gradientId})`} />
      <path d={line} fill="none" stroke={color} strokeWidth={2.6} strokeLinecap="round" strokeLinejoin="round" />
      <circle cx={last[0]} cy={last[1]} r={4.5} fill={color} stroke="rgb(var(--surface))" strokeWidth={2.5} />
    </svg>
  );
}

/* -------------------------------------------------------------------------- */
/* Seg — segmented range control (7d / 30d / 90d etc).                        */
/* -------------------------------------------------------------------------- */
export function Seg<T extends string | number>({
  options,
  value,
  onChange,
}: {
  options: { label: string; value: T }[];
  value: T;
  onChange: (v: T) => void;
}) {
  return (
    <div className="inline-flex rounded-[10px] border border-line bg-surface-soft p-[3px]">
      {options.map((o) => (
        <button
          key={String(o.value)}
          type="button"
          onClick={() => onChange(o.value)}
          className={clsx(
            'rounded-lg px-3 py-1.5 text-xs font-semibold transition',
            value === o.value ? 'bg-surface text-brand-600 shadow-card' : 'text-ink-muted hover:text-ink-soft',
          )}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

/* -------------------------------------------------------------------------- */
/* RolloutRing — conic-gradient donut showing "N of M on latest".             */
/* -------------------------------------------------------------------------- */
export function RolloutRing({
  pct,
  centerTop,
  centerBottom,
}: {
  pct: number;
  centerTop: string;
  centerBottom: string;
}) {
  const clamped = Math.max(0, Math.min(100, Math.round(pct)));
  return (
    <div
      className="relative grid h-[120px] w-[120px] shrink-0 place-items-center rounded-full"
      style={{
        background: `conic-gradient(#3b5bff ${clamped}%, rgb(var(--surface-grid)) 0)`,
      }}
    >
      <div className="absolute inset-[12px] rounded-full bg-surface" />
      <div className="relative z-[1] text-center">
        <b className="block font-disp text-2xl font-bold text-ink">{centerTop}</b>
        <span className="block text-[11px] text-ink-muted">{centerBottom}</span>
      </div>
    </div>
  );
}

/* -------------------------------------------------------------------------- */
/* PulseLine — the animated EKG signature used in the Overview hero.          */
/* Deterministic geometry (no random data). Purely decorative.               */
/* -------------------------------------------------------------------------- */
function ekgPath(w: number, h: number): string {
  const mid = h * 0.5;
  const seg = w / 6;
  let d = `M0,${mid}`;
  let x = 0;
  for (let i = 0; i < 6; i++) {
    d += ` L${x + seg * 0.55},${mid}`;
    d += ` L${x + seg * 0.62},${mid - h * 0.12}`;
    d += ` L${x + seg * 0.68},${mid + h * 0.3}`;
    d += ` L${x + seg * 0.74},${mid - h * 0.42}`;
    d += ` L${x + seg * 0.8},${mid + h * 0.1}`;
    d += ` L${x + seg},${mid}`;
    x += seg;
  }
  return d;
}

export function PulseLine() {
  const pathRef = useRef<SVGPathElement>(null);
  const dotRef = useRef<SVGCircleElement>(null);

  useEffect(() => {
    const pl = pathRef.current;
    const pdot = dotRef.current;
    if (!pl || !pdot || prefersReducedMotion() || !pl.getTotalLength) return;
    const L = pl.getTotalLength();
    pl.style.strokeDasharray = String(L);
    pl.style.strokeDashoffset = String(L);
    let t0: number | null = null;
    const dur = 2600;
    let raf = 0;
    const run = (t: number) => {
      if (t0 === null) t0 = t;
      const p = ((t - t0) % dur) / dur;
      pl.style.strokeDashoffset = String(L * (1 - p));
      const pt = pl.getPointAtLength(L * p);
      pdot.setAttribute('cx', String(pt.x));
      pdot.setAttribute('cy', String(pt.y));
      raf = requestAnimationFrame(run);
    };
    raf = requestAnimationFrame(run);
    return () => cancelAnimationFrame(raf);
  }, []);

  return (
    <svg viewBox="0 0 800 100" preserveAspectRatio="none" style={{ width: '100%', height: '100%', display: 'block' }}>
      <defs>
        <linearGradient id="mqp-pulse" x1="0" y1="0" x2="1" y2="0">
          <stop offset="0" stopColor="#6d5efc" />
          <stop offset=".5" stopColor="#3b5bff" />
          <stop offset="1" stopColor="#22b8ff" />
        </linearGradient>
      </defs>
      <path
        ref={pathRef}
        d={ekgPath(800, 100)}
        fill="none"
        stroke="url(#mqp-pulse)"
        strokeWidth={2.5}
        strokeLinecap="round"
        strokeLinejoin="round"
        style={{ filter: 'drop-shadow(0 0 6px rgba(34,184,255,.6))' }}
      />
      <circle ref={dotRef} r={4} cx={0} cy={50} fill="#22b8ff" style={{ filter: 'drop-shadow(0 0 6px rgba(34,184,255,.9))' }} />
    </svg>
  );
}
