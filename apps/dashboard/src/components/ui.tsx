import clsx from 'clsx';
import type { ReactNode } from 'react';
import type { SiteStatus } from '@/types';

export function Spinner({ className }: { className?: string }) {
  return (
    <svg
      className={clsx('animate-spin text-brand-600', className ?? 'h-5 w-5')}
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden="true"
    >
      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
    </svg>
  );
}

export function LoadingState({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="flex items-center justify-center gap-3 py-16 text-ink-muted">
      <Spinner />
      <span className="text-sm">{label}</span>
    </div>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-16 text-center">
      <div className="flex h-12 w-12 items-center justify-center rounded-full bg-danger/10 text-danger">
        <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m0 3.75h.008M10.34 3.94l-8.4 14.55A1.5 1.5 0 003.24 21h17.52a1.5 1.5 0 001.3-2.51L13.66 3.94a1.5 1.5 0 00-2.6 0z" />
        </svg>
      </div>
      <p className="max-w-sm text-sm text-ink-body">{message}</p>
      {onRetry && (
        <button className="btn-secondary" onClick={onRetry}>
          Try again
        </button>
      )}
    </div>
  );
}

export function EmptyState({ title, description, action, icon }: { title: string; description?: string; action?: ReactNode; icon?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-14 text-center">
      <div className="flex h-12 w-12 items-center justify-center rounded-full bg-surface-soft text-ink-muted">
        {icon ?? (
          <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 9.75h16.5M3.75 6.75h16.5M3.75 12.75h16.5M3.75 15.75h9" />
          </svg>
        )}
      </div>
      <p className="font-disp text-base font-semibold text-ink">{title}</p>
      {description && <p className="max-w-sm text-sm text-ink-muted">{description}</p>}
      {action}
    </div>
  );
}

const STATUS_STYLES: Record<SiteStatus, string> = {
  online: 'bg-success/10 text-success-text',
  offline: 'bg-danger/10 text-danger',
  unknown: 'bg-ink/5 text-ink-body',
};

const STATUS_DOT: Record<SiteStatus, string> = {
  online: 'bg-success',
  offline: 'bg-danger',
  unknown: 'bg-ink-muted',
};

const STATUS_LABEL: Record<SiteStatus, string> = {
  online: 'Online',
  offline: 'Offline',
  unknown: 'Unknown',
};

export function StatusBadge({ status }: { status: SiteStatus }) {
  return (
    <span className={clsx('inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold', STATUS_STYLES[status])}>
      <span className={clsx('h-1.5 w-1.5 rounded-full', STATUS_DOT[status])} />
      {STATUS_LABEL[status]}
    </span>
  );
}

export function Badge({ children, tone = 'slate' }: { children: ReactNode; tone?: 'slate' | 'green' | 'red' | 'amber' | 'brand' }) {
  const tones: Record<string, string> = {
    slate: 'bg-ink/5 text-ink-body',
    green: 'bg-success/10 text-success-text',
    red: 'bg-danger/10 text-danger',
    amber: 'bg-warning/10 text-warning-text',
    brand: 'bg-brand-600/10 text-brand-700',
  };
  return (
    <span className={clsx('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', tones[tone])}>
      {children}
    </span>
  );
}

export function VerifiedPill({ verified }: { verified: boolean }) {
  return verified ? <Badge tone="green">Verified</Badge> : <Badge tone="amber">Unverified</Badge>;
}

/* -------------------------------------------------------------------------- */
/* Design helpers                                                             */
/* -------------------------------------------------------------------------- */

/** Two-letter "favicon" initials from a domain (strips common subdomains). */
export function faviInitials(domain: string): string {
  return domain
    .replace(/^(www\.|blog\.|shop\.|news\.|lms\.|app\.|dev\.|staging\.)/, '')
    .slice(0, 2)
    .toUpperCase();
}

/** Square gradient-soft avatar tile with a site's favicon initials. */
export function FavAvatar({ domain, className }: { domain: string; className?: string }) {
  return (
    <span
      className={clsx(
        'grid shrink-0 place-items-center rounded-[9px] bg-brand-gradient/[0.14] font-disp font-bold text-brand-600',
        className ?? 'h-[34px] w-[34px] text-sm',
      )}
      style={{ background: 'linear-gradient(105deg,rgba(109,94,252,.14),rgba(34,184,255,.14))' }}
      aria-hidden="true"
    >
      {faviInitials(domain)}
    </span>
  );
}

type PillTone = 'ok' | 'warn' | 'bad' | 'brand' | 'neutral';

const PILL_TONES: Record<PillTone, string> = {
  ok: 'bg-success/10 text-success-text',
  warn: 'bg-warning/[0.15] text-warning-text',
  bad: 'bg-danger/[0.13] text-danger',
  brand: 'text-brand-600',
  neutral: 'bg-surface-soft text-ink-body border border-line',
};

const PILL_DOT: Record<PillTone, string> = {
  ok: 'bg-success',
  warn: 'bg-warning',
  bad: 'bg-danger',
  brand: 'bg-brand-600',
  neutral: 'bg-ink-muted',
};

/** Design status pill with optional leading dot (matches .pill / .p-*). */
export function Pill({ tone, children, dot = false }: { tone: PillTone; children: ReactNode; dot?: boolean }) {
  return (
    <span
      className={clsx('inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold', PILL_TONES[tone])}
      style={tone === 'brand' ? { background: 'linear-gradient(105deg,rgba(109,94,252,.14),rgba(34,184,255,.14))' } : undefined}
    >
      {dot && <span className={clsx('h-[7px] w-[7px] rounded-full', PILL_DOT[tone])} />}
      {children}
    </span>
  );
}

/** Map a site status to the design pill (Online / Attention / Offline). */
export function SiteStatusPill({ status, needsAttention }: { status: SiteStatus; needsAttention?: boolean }) {
  if (status === 'offline') return <Pill tone="bad" dot>Offline</Pill>;
  if (status === 'unknown') return <Pill tone="neutral" dot>Unknown</Pill>;
  if (needsAttention) return <Pill tone="warn" dot>Attention</Pill>;
  return <Pill tone="ok" dot>Online</Pill>;
}
