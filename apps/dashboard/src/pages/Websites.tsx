import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import { api } from '@/lib/api';
import type { Paginated, Site, SiteStatus } from '@/types';
import { EmptyState, ErrorState, LoadingState, StatusBadge, VerifiedPill } from '@/components/ui';
import ConnectionCodeModal from '@/components/ConnectionCodeModal';
import { timeAgo } from '@/lib/format';

type SortKey = 'domain' | 'status' | 'wp_version' | 'php_version' | 'plugin_version' | 'last_heartbeat_at';

const COLUMNS: { key: SortKey | null; label: string; sortable: boolean }[] = [
  { key: 'domain', label: 'Domain', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
  { key: null, label: 'Origin IP', sortable: false },
  { key: null, label: 'Origin Verified', sortable: false },
  { key: null, label: 'Server IP', sortable: false },
  { key: 'wp_version', label: 'WP', sortable: true },
  { key: 'php_version', label: 'PHP', sortable: true },
  { key: 'plugin_version', label: 'Connector', sortable: true },
  { key: 'last_heartbeat_at', label: 'Last Seen', sortable: true },
];

export default function Websites() {
  const [params, setParams] = useSearchParams();
  const [searchInput, setSearchInput] = useState(params.get('q') ?? '');
  const [connectOpen, setConnectOpen] = useState(false);

  const q = params.get('q') ?? '';
  const status = params.get('status') ?? '';
  const needsAttention = params.get('needs_attention') === '1';
  const sort = (params.get('sort') as SortKey) ?? 'last_heartbeat_at';
  const direction = params.get('direction') ?? 'desc';
  const page = Number(params.get('page') ?? '1');

  // Debounce the search box into the URL params.
  useEffect(() => {
    const t = setTimeout(() => {
      const next = new URLSearchParams(params);
      if (searchInput) next.set('q', searchInput);
      else next.delete('q');
      next.set('page', '1');
      if (searchInput !== q) setParams(next, { replace: true });
    }, 350);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey: ['sites', { q, status, needsAttention, sort, direction, page }],
    queryFn: async () => {
      const search = new URLSearchParams();
      if (q) search.set('q', q);
      if (status) search.set('status', status);
      if (needsAttention) search.set('needs_attention', '1');
      search.set('sort', sort);
      search.set('direction', direction);
      search.set('page', String(page));
      return (await api.get<Paginated<Site>>(`/api/dashboard/sites?${search.toString()}`)).data;
    },
    placeholderData: keepPreviousData,
  });

  const update = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params);
    for (const [k, v] of Object.entries(patch)) {
      if (v === null || v === '') next.delete(k);
      else next.set(k, v);
    }
    setParams(next, { replace: true });
  };

  const toggleSort = (key: SortKey) => {
    if (sort === key) {
      update({ direction: direction === 'asc' ? 'desc' : 'asc', page: '1' });
    } else {
      update({ sort: key, direction: 'asc', page: '1' });
    }
  };

  const meta = data?.meta;

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Websites</h1>
          <p className="mt-1 text-sm text-slate-500">Every WordPress site connected to your organization.</p>
        </div>
        <button className="btn-primary" onClick={() => setConnectOpen(true)}>
          Connect a website
        </button>
      </div>

      <ConnectionCodeModal open={connectOpen} onClose={() => setConnectOpen(false)} />

      {/* Filters */}
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <div className="relative flex-1 min-w-[220px]">
          <svg className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
          </svg>
          <input
            className="input pl-9"
            placeholder="Search domain or IP…"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
          />
        </div>

        <select className="input w-auto" value={status} onChange={(e) => update({ status: e.target.value || null, page: '1' })}>
          <option value="">All statuses</option>
          <option value="online">Online</option>
          <option value="offline">Offline</option>
          <option value="unknown">Unknown</option>
        </select>

        <label className="flex items-center gap-2 text-sm text-slate-600">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
            checked={needsAttention}
            onChange={(e) => update({ needs_attention: e.target.checked ? '1' : null, page: '1' })}
          />
          Needs attention
        </label>
      </div>

      <div className="card overflow-hidden">
        {isLoading ? (
          <LoadingState />
        ) : isError ? (
          <ErrorState message={(error as Error)?.message ?? 'Could not load websites.'} onRetry={refetch} />
        ) : data && data.data.length === 0 ? (
          <EmptyState
            title="No websites found"
            description={q || status || needsAttention ? 'Try adjusting your filters.' : 'Generate a connection code to enrol your first site.'}
            action={
              <Link to="/api-tokens" className="btn-secondary">
                Manage tokens
              </Link>
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50">
                <tr>
                  {COLUMNS.map((col) => (
                    <th key={col.label} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                      {col.sortable && col.key ? (
                        <button className="inline-flex items-center gap-1 hover:text-slate-700" onClick={() => toggleSort(col.key!)}>
                          {col.label}
                          <SortIcon active={sort === col.key} direction={direction} />
                        </button>
                      ) : (
                        col.label
                      )}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className={clsx('divide-y divide-slate-100', isFetching && 'opacity-60')}>
                {data?.data.map((site) => (
                  <tr key={site.uuid} className="hover:bg-slate-50">
                    <td className="whitespace-nowrap px-4 py-3">
                      <Link to={`/websites/${site.uuid}`} className="font-medium text-brand-700 hover:underline">
                        {site.domain}
                      </Link>
                    </td>
                    <td className="px-4 py-3">
                      <StatusBadge status={site.status as SiteStatus} />
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-600">{site.origin_ip ?? '—'}</td>
                    <td className="px-4 py-3">
                      {site.origin_ip ? <VerifiedPill verified={site.origin_ip_verified} /> : <span className="text-slate-400">—</span>}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-600">{site.server_ip ?? '—'}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-slate-600">{site.wp_version ?? '—'}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-slate-600">{site.php_version ?? '—'}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-slate-600">{site.plugin_version ?? '—'}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{timeAgo(site.last_heartbeat_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* Pagination */}
        {meta && meta.total > 0 && (
          <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm text-slate-600">
            <span>
              {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
            </span>
            <div className="flex items-center gap-2">
              <button
                className="btn-secondary px-3 py-1"
                disabled={meta.current_page <= 1}
                onClick={() => update({ page: String(meta.current_page - 1) })}
              >
                Previous
              </button>
              <span className="px-1">
                Page {meta.current_page} of {meta.last_page}
              </span>
              <button
                className="btn-secondary px-3 py-1"
                disabled={meta.current_page >= meta.last_page}
                onClick={() => update({ page: String(meta.current_page + 1) })}
              >
                Next
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

function SortIcon({ active, direction }: { active: boolean; direction: string }) {
  if (!active) {
    return (
      <svg className="h-3 w-3 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M8 9l4-4 4 4m0 6l-4 4-4-4" />
      </svg>
    );
  }
  return (
    <svg className="h-3 w-3 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
      {direction === 'asc' ? (
        <path strokeLinecap="round" strokeLinejoin="round" d="M12 5l7 7H5l7-7z" />
      ) : (
        <path strokeLinecap="round" strokeLinejoin="round" d="M12 19l-7-7h14l-7 7z" />
      )}
    </svg>
  );
}
