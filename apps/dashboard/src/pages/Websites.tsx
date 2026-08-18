import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import { api } from '@/lib/api';
import type { Paginated, Site, SiteStatus } from '@/types';
import { EmptyState, ErrorState, LoadingState, StatusBadge, VerifiedPill } from '@/components/ui';
import ConnectionCodeModal from '@/components/ConnectionCodeModal';
import AccountSelector from '@/components/AccountSelector';
import { timeAgo } from '@/lib/format';
import { useAuth } from '@/context/AuthContext';

/** Build a short human summary of the pending updates on a site. */
function updatesSummary(site: Site): string {
  const parts: string[] = [];
  if (site.core_updates_available) parts.push('WordPress core');
  if (site.plugin_updates_available > 0)
    parts.push(`${site.plugin_updates_available} plugin${site.plugin_updates_available === 1 ? '' : 's'}`);
  if (site.theme_updates_available > 0)
    parts.push(`${site.theme_updates_available} theme${site.theme_updates_available === 1 ? '' : 's'}`);
  return parts.length ? `Updates available: ${parts.join(', ')}` : 'Updates available';
}

/** Mini sparkline for 7-day visitor trend. */
function VisitorSparkline({ trend }: { trend: number[] }) {
  if (!trend || trend.length === 0) return null;
  const max = Math.max(...trend, 1);
  return (
    <div className="inline-flex h-5 items-end gap-0.5">
      {trend.map((val, i) => (
        <div key={i} className="w-1 rounded-t-sm bg-cyan-400" style={{ height: `${(val / max) * 100}%` }} />
      ))}
    </div>
  );
}

/** Subtle amber "updates available" indicator shown next to a site's domain. */
function UpdatesIndicator({ site }: { site: Site }) {
  if (!site.has_updates) return null;
  return (
    <span
      className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-700"
      title={updatesSummary(site)}
    >
      <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
      </svg>
      Updates
    </span>
  );
}

type SortKey = 'domain' | 'status' | 'wp_version' | 'php_version' | 'plugin_version' | 'last_heartbeat_at';

interface ColumnDef {
  key: SortKey | null;
  label: string;
  sortable: boolean;
  ownerOnly?: boolean;
}

// Server IP is intentionally NOT a column here (§16) — it stays on the website
// detail view only. The "Owner" column is shown to the platform Owner so they
// can see which account each website belongs to (§14/§15).
const COLUMNS: ColumnDef[] = [
  { key: 'domain', label: 'Domain', sortable: true },
  { key: null, label: 'Owner', sortable: false, ownerOnly: true },
  { key: null, label: 'Visitors (7d)', sortable: false },
  { key: 'status', label: 'Status', sortable: true },
  { key: null, label: 'Origin IP', sortable: false },
  { key: null, label: 'Origin Verified', sortable: false },
  { key: 'wp_version', label: 'WP', sortable: true },
  { key: 'php_version', label: 'PHP', sortable: true },
  { key: 'plugin_version', label: 'Connector', sortable: true },
  { key: 'last_heartbeat_at', label: 'Last Seen', sortable: true },
];

export default function Websites() {
  const { user } = useAuth();
  const [params, setParams] = useSearchParams();
  const [searchInput, setSearchInput] = useState(params.get('q') ?? '');
  const [connectOpen, setConnectOpen] = useState(false);

  // Website-limit context (§9). Owner is always unlimited (website_limit null).
  const isOwner = user?.is_owner || user?.website_limit === null;
  const limitReached = !isOwner && (user?.website_limit_reached ?? false);
  const usageLabel =
    !isOwner && user && user.website_limit !== null
      ? `${user.owned_sites_count} of ${user.website_limit} used`
      : null;

  const q = params.get('q') ?? '';
  const status = params.get('status') ?? '';
  const needsAttention = params.get('needs_attention') === '1';
  const sort = (params.get('sort') as SortKey) ?? 'last_heartbeat_at';
  const direction = params.get('direction') ?? 'desc';
  const page = Number(params.get('page') ?? '1');
  // Owner-selected account filter (§14/§15). Ignored server-side for non-owners.
  const account = params.get('account') ?? '';
  const visibleColumns = COLUMNS.filter((c) => !c.ownerOnly || user?.is_owner);

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
    queryKey: ['sites', { q, status, needsAttention, sort, direction, page, account }],
    queryFn: async () => {
      const search = new URLSearchParams();
      if (q) search.set('q', q);
      if (status) search.set('status', status);
      if (needsAttention) search.set('needs_attention', '1');
      if (account) search.set('account', account);
      search.set('sort', sort);
      search.set('direction', direction);
      search.set('page', String(page));
      return (await api.get<Paginated<Site>>(`/api/dashboard/sites?${search.toString()}`)).data;
    },
    placeholderData: keepPreviousData,
    refetchInterval: 30000, // Auto-refresh every 30s for live status updates
    refetchOnWindowFocus: true, // Refresh when user returns to tab
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
          <h1 className="text-2xl font-bold tracking-tight text-ink">Websites</h1>
          <p className="mt-1 text-sm text-ink-muted">Every WordPress site connected to your organization.</p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          {user?.is_owner && (
            <AccountSelector value={account} onChange={(v) => update({ account: v || null, page: '1' })} />
          )}
          <div className="flex flex-col items-end gap-1">
            <button
              className="btn-primary"
              onClick={() => setConnectOpen(true)}
              disabled={limitReached}
              title={limitReached ? 'You have reached your website limit.' : undefined}
            >
              Connect a website
            </button>
            {usageLabel && <span className="text-xs text-ink-muted">{usageLabel}</span>}
            {limitReached && (
              <span className="text-xs font-medium text-warning">You have reached your website limit.</span>
            )}
          </div>
        </div>
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
            <table className="min-w-full divide-y divide-line text-sm">
              <thead className="bg-surface-soft">
                <tr>
                  {visibleColumns.map((col) => (
                    <th key={col.label} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-muted">
                      {col.sortable && col.key ? (
                        <button className="inline-flex items-center gap-1 hover:text-ink-soft" onClick={() => toggleSort(col.key!)}>
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
              <tbody className={clsx('divide-y divide-line', isFetching && 'opacity-60')}>
                {data?.data.map((site) => (
                  <tr key={site.uuid} className="hover:bg-surface-soft">
                    <td className="whitespace-nowrap px-4 py-3">
                      <div className="flex items-center gap-2">
                        <Link to={`/websites/${site.uuid}`} className="font-semibold text-brand-700 hover:underline">
                          {site.domain}
                        </Link>
                        <UpdatesIndicator site={site} />
                      </div>
                    </td>
                    {user?.is_owner && (
                      <td className="whitespace-nowrap px-4 py-3">
                        {site.owner ? (
                          <span className="text-ink-soft" title={site.owner.email}>{site.owner.name}</span>
                        ) : (
                          <span className="text-ink-muted">Unassigned</span>
                        )}
                      </td>
                    )}
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-semibold text-ink">{site.visitors_7d.toLocaleString()}</span>
                        <VisitorSparkline trend={site.visitors_trend_7d} />
                        {site.visitors_growth !== 0 && (
                          <span className={clsx('text-xs font-medium', site.visitors_growth > 0 ? 'text-success-text' : 'text-danger')}>
                            {site.visitors_growth > 0 ? '+' : ''}{site.visitors_growth}%
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <StatusBadge status={site.status as SiteStatus} />
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-ink-body">{site.origin_ip ?? '—'}</td>
                    <td className="px-4 py-3">
                      {site.origin_ip ? <VerifiedPill verified={site.origin_ip_verified} /> : <span className="text-ink-muted">—</span>}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-ink-body">{site.wp_version ?? '—'}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-ink-body">{site.php_version ?? '—'}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-ink-body">{site.plugin_version ?? '—'}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-ink-muted">{timeAgo(site.last_heartbeat_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* Pagination */}
        {meta && meta.total > 0 && (
          <div className="flex items-center justify-between border-t border-line px-4 py-3 text-sm text-ink-body">
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
