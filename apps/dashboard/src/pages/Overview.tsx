import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import type { JSX } from 'react';
import { api } from '@/lib/api';
import type { OverviewResponse } from '@/types';
import { ErrorState, LoadingState } from '@/components/ui';
import { useAuth } from '@/context/AuthContext';
import AccountSelector from '@/components/AccountSelector';

interface CardDef {
  key: keyof OverviewResponse['cards'];
  label: string;
  /** Accent colour for the value + icon tile. */
  accent: string;
  tile: string;
  icon: JSX.Element;
  to?: (account: string) => string;
}

function withAccount(path: string, account: string): string {
  if (!account) return path;
  return path + (path.includes('?') ? '&' : '?') + 'account=' + account;
}

const CARDS: CardDef[] = [
  {
    key: 'total',
    label: 'Total Websites',
    accent: 'text-ink',
    tile: 'bg-brand-gradient text-white',
    to: (a) => withAccount('/websites', a),
    icon: <path strokeLinecap="round" strokeLinejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm-8.716-12h17.432M3.284 15h17.432" />,
  },
  {
    key: 'visitors_7d',
    label: 'Visitors (7d)',
    accent: 'text-sky-brand',
    tile: 'bg-sky-brand/10 text-sky-brand',
    icon: <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18L9 11.25l3.75 3.75L21.75 6M21.75 6h-4.5m4.5 0v4.5" />,
  },
  {
    key: 'online',
    label: 'Online',
    accent: 'text-success-text',
    tile: 'bg-success/10 text-success-text',
    to: (a) => withAccount('/websites?status=online', a),
    icon: <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />,
  },
  {
    key: 'offline',
    label: 'Offline',
    accent: 'text-danger',
    tile: 'bg-danger/10 text-danger',
    to: (a) => withAccount('/websites?status=offline', a),
    icon: <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />,
  },
  {
    key: 'needs_attention',
    label: 'Needs Attention',
    accent: 'text-warning',
    tile: 'bg-warning/10 text-warning',
    to: (a) => withAccount('/websites?needs_attention=1', a),
    icon: <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />,
  },
  {
    key: 'updates_available',
    label: 'Updates Available',
    accent: 'text-brand-600',
    tile: 'bg-brand-100 text-brand-700',
    icon: <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />,
  },
];

export default function Overview() {
  const { user } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const account = searchParams.get('account') ?? '';

  const setAccount = (value: string) => {
    const next = new URLSearchParams(searchParams);
    if (value) next.set('account', value);
    else next.delete('account');
    setSearchParams(next, { replace: true });
  };

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['overview', account],
    queryFn: async () =>
      (await api.get<OverviewResponse>('/api/dashboard/overview', { params: account ? { account } : {} })).data,
    refetchInterval: 30000, // Auto-refresh every 30s for live status/visitor updates
    refetchOnWindowFocus: true, // Refresh when user returns to tab
  });

  return (
    <div>
      <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-ink">Overview</h1>
          <p className="mt-1 text-sm text-ink-muted">A summary of every website connected to your organization.</p>
        </div>
        {user?.is_owner && <AccountSelector value={account} onChange={setAccount} />}
      </div>

      {isLoading && <LoadingState />}
      {isError && <ErrorState message={(error as Error)?.message ?? 'Could not load the overview.'} onRetry={refetch} />}

      {data && (
        <>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            {CARDS.map((c) => {
              const value = data.cards[c.key];
              const content = (
                <div className="card h-full p-5 transition-all hover:-translate-y-0.5 hover:shadow-card-hover">
                  <div className={clsx('mb-3 flex h-9 w-9 items-center justify-center rounded-xl', c.tile)}>
                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
                      {c.icon}
                    </svg>
                  </div>
                  <p className="text-xs font-medium text-ink-muted">{c.label}</p>
                  <p className={clsx('mt-1 text-3xl font-bold', c.accent)}>{value}</p>
                </div>
              );
              const to = c.to?.(account);
              return to ? (
                <Link key={c.key} to={to} className="block">
                  {content}
                </Link>
              ) : (
                <div key={c.key}>{content}</div>
              );
            })}
          </div>

          <div className="mt-8 grid gap-4 lg:grid-cols-2">
            <ConnectorReleaseCard data={data} />
            <div className="card p-5">
              <h2 className="text-sm font-semibold text-ink">Quick actions</h2>
              <div className="mt-3 flex flex-wrap gap-2">
                <Link to={withAccount('/websites', account)} className="btn-secondary">
                  View all websites
                </Link>
                <Link to={withAccount('/websites?needs_attention=1', account)} className="btn-secondary">
                  Review attention items
                </Link>
                <Link to="/api-tokens" className="btn-secondary">
                  Manage API tokens
                </Link>
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  );
}

/**
 * Connector release card (§11). Shows the currently published connector version
 * and a discoverable "Download Latest Plugin" action available to ALL users.
 * The download always resolves to the canonical latest release via the public
 * download endpoint (never a hardcoded version URL); an amber indicator flags
 * that sites need updating when the overview reports pending updates.
 */
function ConnectorReleaseCard({ data }: { data: OverviewResponse }) {
  const version = data.latest_plugin_version;
  // Canonical "latest" download: prefer the active release's URL, otherwise the
  // public update endpoint which always serves the current active release.
  const downloadUrl = data.latest_plugin_download_url ?? '/api/v1/plugin/download';
  const updatesPending = data.cards.updates_available > 0;

  return (
    <div className="card p-5">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h2 className="text-sm font-semibold text-ink">Connector release</h2>
          <p className="mt-2 text-sm text-ink-body">
            Latest connector version:{' '}
            <span className="font-semibold text-ink">{version ?? 'Not published yet'}</span>
          </p>
        </div>
        {updatesPending && (
          <span className="pill bg-warning/10 text-warning">
            <span className="h-1.5 w-1.5 rounded-full bg-warning" />
            Updates available
          </span>
        )}
      </div>
      <div className="mt-4 flex flex-wrap items-center gap-2">
        <a href={downloadUrl} className={clsx('btn-primary', !version && 'pointer-events-none opacity-50')}>
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
          </svg>
          Download Latest Plugin
        </a>
        <span className="text-xs text-ink-muted">
          Install or update the MarQira Connector on any WordPress site.
        </span>
      </div>
    </div>
  );
}
