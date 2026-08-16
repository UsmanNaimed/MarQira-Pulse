import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { api } from '@/lib/api';
import type { OverviewResponse } from '@/types';
import { ErrorState, LoadingState } from '@/components/ui';

interface CardDef {
  key: keyof OverviewResponse['cards'];
  label: string;
  tone: string;
  to?: string;
}

const CARDS: CardDef[] = [
  { key: 'total', label: 'Total Websites', tone: 'text-slate-900', to: '/websites' },
  { key: 'online', label: 'Online', tone: 'text-emerald-600', to: '/websites?status=online' },
  { key: 'offline', label: 'Offline', tone: 'text-red-600', to: '/websites?status=offline' },
  { key: 'needs_attention', label: 'Needs Attention', tone: 'text-amber-600', to: '/websites?needs_attention=1' },
  { key: 'updates_available', label: 'Updates Available', tone: 'text-brand-600' },
];

export default function Overview() {
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['overview'],
    queryFn: async () => (await api.get<OverviewResponse>('/api/dashboard/overview')).data,
  });

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-slate-900">Overview</h1>
        <p className="mt-1 text-sm text-slate-500">A summary of every website connected to your organization.</p>
      </div>

      {isLoading && <LoadingState />}
      {isError && <ErrorState message={(error as Error)?.message ?? 'Could not load the overview.'} onRetry={refetch} />}

      {data && (
        <>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            {CARDS.map((c) => {
              const value = data.cards[c.key];
              const content = (
                <div className="card h-full p-5 transition hover:shadow-md">
                  <p className="text-sm font-medium text-slate-500">{c.label}</p>
                  <p className={clsx('mt-2 text-3xl font-semibold', c.tone)}>{value}</p>
                </div>
              );
              return c.to ? (
                <Link key={c.key} to={c.to} className="block">
                  {content}
                </Link>
              ) : (
                <div key={c.key}>{content}</div>
              );
            })}
          </div>

          <div className="mt-8 grid gap-4 lg:grid-cols-2">
            <div className="card p-5">
              <h2 className="text-sm font-semibold text-slate-900">Connector release</h2>
              <p className="mt-2 text-sm text-slate-500">
                Latest connector version:{' '}
                <span className="font-medium text-slate-800">{data.latest_plugin_version ?? 'Not published yet'}</span>
              </p>
              <p className="mt-1 text-xs text-slate-400">
                Update tracking becomes active once the release registry ships (Phase 7).
              </p>
            </div>
            <div className="card p-5">
              <h2 className="text-sm font-semibold text-slate-900">Quick actions</h2>
              <div className="mt-3 flex flex-wrap gap-2">
                <Link to="/websites" className="btn-secondary">
                  View all websites
                </Link>
                <Link to="/websites?needs_attention=1" className="btn-secondary">
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
