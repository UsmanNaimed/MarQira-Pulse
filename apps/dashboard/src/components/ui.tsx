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
    <div className="flex items-center justify-center gap-3 py-16 text-slate-500">
      <Spinner />
      <span className="text-sm">{label}</span>
    </div>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-16 text-center">
      <div className="flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-600">
        <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m0 3.75h.008M10.34 3.94l-8.4 14.55A1.5 1.5 0 003.24 21h17.52a1.5 1.5 0 001.3-2.51L13.66 3.94a1.5 1.5 0 00-2.6 0z" />
        </svg>
      </div>
      <p className="max-w-sm text-sm text-slate-600">{message}</p>
      {onRetry && (
        <button className="btn-secondary" onClick={onRetry}>
          Try again
        </button>
      )}
    </div>
  );
}

export function EmptyState({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-16 text-center">
      <div className="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
        <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 9.75h16.5M3.75 6.75h16.5M3.75 12.75h16.5M3.75 15.75h9" />
        </svg>
      </div>
      <p className="text-sm font-medium text-slate-700">{title}</p>
      {description && <p className="max-w-sm text-sm text-slate-500">{description}</p>}
      {action}
    </div>
  );
}

const STATUS_STYLES: Record<SiteStatus, string> = {
  online: 'bg-emerald-100 text-emerald-700 ring-emerald-600/20',
  offline: 'bg-red-100 text-red-700 ring-red-600/20',
  unknown: 'bg-slate-100 text-slate-600 ring-slate-500/20',
};

const STATUS_DOT: Record<SiteStatus, string> = {
  online: 'bg-emerald-500',
  offline: 'bg-red-500',
  unknown: 'bg-slate-400',
};

export function StatusBadge({ status }: { status: SiteStatus }) {
  return (
    <span className={clsx('inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset', STATUS_STYLES[status])}>
      <span className={clsx('h-1.5 w-1.5 rounded-full', STATUS_DOT[status])} />
      {status.charAt(0).toUpperCase() + status.slice(1)}
    </span>
  );
}

export function Badge({ children, tone = 'slate' }: { children: ReactNode; tone?: 'slate' | 'green' | 'red' | 'amber' | 'brand' }) {
  const tones: Record<string, string> = {
    slate: 'bg-slate-100 text-slate-700 ring-slate-500/20',
    green: 'bg-emerald-100 text-emerald-700 ring-emerald-600/20',
    red: 'bg-red-100 text-red-700 ring-red-600/20',
    amber: 'bg-amber-100 text-amber-700 ring-amber-600/20',
    brand: 'bg-brand-100 text-brand-700 ring-brand-600/20',
  };
  return (
    <span className={clsx('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tones[tone])}>
      {children}
    </span>
  );
}

export function VerifiedPill({ verified }: { verified: boolean }) {
  return verified ? <Badge tone="green">Verified</Badge> : <Badge tone="amber">Unverified</Badge>;
}
