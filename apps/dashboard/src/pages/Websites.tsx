import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import { api } from '@/lib/api';
import type { OverviewResponse, Paginated, Site, SiteStatus } from '@/types';
import {
  EmptyState,
  ErrorState,
  LoadingState,
  SiteStatusPill,
  VerifiedPill,
  FavAvatar,
  Pill,
} from '@/components/ui';
import { Sparkline } from '@/components/charts';
import ConnectionCodeModal from '@/components/ConnectionCodeModal';
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

type SortKey = 'domain' | 'status' | 'wp_version' | 'php_version' | 'plugin_version' | 'last_seen_at';
type ViewMode = 'table' | 'grid';

/** Which filter chip is active, derived from the URL params. */
function activeChip(status: string, needsAttention: boolean): 'all' | 'online' | 'attention' | 'offline' {
  if (needsAttention) return 'attention';
  if (status === 'online') return 'online';
  if (status === 'offline') return 'offline';
  return 'all';
}

export default function Websites() {
  const { user } = useAuth();
  const [params, setParams] = useSearchParams();
  const [searchInput, setSearchInput] = useState(params.get('q') ?? '');
  const [connectOpen, setConnectOpen] = useState(false);
  const [view, setView] = useState<ViewMode>('table');

  const isOwner = user?.is_owner || user?.website_limit === null;
  const limitReached = !isOwner && (user?.website_limit_reached ?? false);
  const usageLabel =
    !isOwner && user && user.website_limit !== null
      ? `${user.owned_sites_count} of ${user.website_limit} used`
      : null;

  const q = params.get('q') ?? '';
  const status = params.get('status') ?? '';
  const needsAttention = params.get('needs_attention') === '1';
  const sort = (params.get('sort') as SortKey) ?? 'last_seen_at';
  const direction = params.get('direction') ?? 'desc';
  const page = Number(params.get('page') ?? '1');
  const account = params.get('account') ?? '';
  const chip = activeChip(status, needsAttention);

  // Keep the search box synced if the term arrives via the top-bar search.
  useEffect(() => {
    setSearchInput(params.get('q') ?? '');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q]);

  // Debounce the local search box into the URL params.
  useEffect(() => {
    const t = setTimeout(() => {
      if (searchInput === q) return;
      const next = new URLSearchParams(params);
      if (searchInput) next.set('q', searchInput);
      else next.delete('q');
      next.set('page', '1');
      setParams(next, { replace: true });
    }, 350);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput]);

  // Real filter-chip counts come from the same scoped overview cards.
  const overview = useQuery({
    queryKey: ['overview', account],
    queryFn: async () =>
      (await api.get<OverviewResponse>('/api/dashboard/overview', { params: account ? { account } : {} })).data,
    refetchInterval: 30000,
  });
  const counts = overview.data?.cards;

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
    refetchInterval: 30000,
    refetchOnWindowFocus: true,
  });

  const update = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params);
    for (const [k, v] of Object.entries(patch)) {
      if (v === null || v === '') next.delete(k);
      else next.set(k, v);
    }
    setParams(next, { replace: true });
  };

  const setChip = (which: 'all' | 'online' | 'attention' | 'offline') => {
    if (which === 'all') update({ status: null, needs_attention: null, page: '1' });
    else if (which === 'online') update({ status: 'online', needs_attention: null, page: '1' });
    else if (which === 'offline') update({ status: 'offline', needs_attention: null, page: '1' });
    else update({ status: null, needs_attention: '1', page: '1' });
  };

  const toggleSort = (key: SortKey) => {
    if (sort === key) update({ direction: direction === 'asc' ? 'desc' : 'asc', page: '1' });
    else update({ sort: key, direction: 'asc', page: '1' });
  };

  const meta = data?.meta;
  const sites = data?.data ?? [];

  return (
    <div>
      <div className="mb-[22px] flex flex-wrap items-end justify-between gap-3.5">
        <div>
          <h1 className="font-disp text-[26px] font-bold tracking-tight text-ink">Websites</h1>
          <p className="mt-1 text-sm text-ink-muted">Every WordPress site you monitor, in one place.</p>
        </div>
        <div className="flex flex-col items-end gap-1">
          <button
            className="btn-primary"
            onClick={() => setConnectOpen(true)}
            disabled={limitReached}
            title={limitReached ? 'You have reached your website limit.' : undefined}
          >
            <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
              <path d="M12 5v14M5 12h14" />
            </svg>
            Add website
          </button>
          {usageLabel && <span className="text-xs text-ink-muted">{usageLabel}</span>}
          {limitReached && <span className="text-xs font-medium text-warning-text">You have reached your website limit.</span>}
        </div>
      </div>

      <ConnectionCodeModal open={connectOpen} onClose={() => setConnectOpen(false)} />

      {/* Toolbar: filter chips + search + view toggle */}
      <div className="mb-4 flex flex-wrap items-center gap-2.5">
        <div className="flex flex-wrap gap-1.5">
          <FilterChip label="All" count={counts?.total} active={chip === 'all'} onClick={() => setChip('all')} />
          <FilterChip label="Online" count={counts?.online} active={chip === 'online'} onClick={() => setChip('online')} tone="ok" />
          <FilterChip label="Attention" count={counts?.needs_attention} active={chip === 'attention'} onClick={() => setChip('attention')} tone="warn" />
          <FilterChip label="Offline" count={counts?.offline} active={chip === 'offline'} onClick={() => setChip('offline')} tone="bad" />
        </div>

        <div className="ml-auto flex items-center gap-2 rounded-[10px] border border-line bg-surface px-3 py-2 min-w-[200px]">
          <svg className="h-[15px] w-[15px] text-ink-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3-3" />
          </svg>
          <input
            className="w-full border-none bg-transparent text-sm text-ink outline-none placeholder:text-ink-muted"
            placeholder="Filter by domain…"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
          />
        </div>

        <div className="inline-flex rounded-[10px] border border-line bg-surface-soft p-[3px]">
          <ViewButton active={view === 'table'} onClick={() => setView('table')} label="Table view">
            <path d="M3 6h18M3 12h18M3 18h18" />
          </ViewButton>
          <ViewButton active={view === 'grid'} onClick={() => setView('grid')} label="Grid view">
            <>
              <rect x="3" y="3" width="7" height="7" rx="1" />
              <rect x="14" y="3" width="7" height="7" rx="1" />
              <rect x="3" y="14" width="7" height="7" rx="1" />
              <rect x="14" y="14" width="7" height="7" rx="1" />
            </>
          </ViewButton>
        </div>
      </div>

      {isLoading ? (
        <div className="card">
          <LoadingState />
        </div>
      ) : isError ? (
        <div className="card">
          <ErrorState message={(error as Error)?.message ?? 'Could not load websites.'} onRetry={refetch} />
        </div>
      ) : sites.length === 0 ? (
        <div className="card">
          <EmptyState
            title="No websites found"
            description={q || status || needsAttention ? 'Try adjusting your filters.' : 'Add a website to enrol your first site.'}
            action={
              <button className="btn-secondary" onClick={() => setConnectOpen(true)} disabled={limitReached}>
                Add website
              </button>
            }
          />
        </div>
      ) : view === 'table' ? (
        <div className="card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="min-w-full text-[13.5px]">
              <thead>
                <tr>
                  <Th onClick={() => toggleSort('domain')} sortable active={sort === 'domain'} dir={direction}>Website</Th>
                  {user?.is_owner && <Th>Owner</Th>}
                  <Th onClick={() => toggleSort('status')} sortable active={sort === 'status'} dir={direction}>Status</Th>
                  <Th title="Share of the last 7 days this site was reporting, measured at hourly resolution from its own heartbeats.">7-Day Uptime</Th>
                  <Th>Origin</Th>
                  <Th onClick={() => toggleSort('wp_version')} sortable active={sort === 'wp_version'} dir={direction}>WordPress</Th>
                  <Th onClick={() => toggleSort('plugin_version')} sortable active={sort === 'plugin_version'} dir={direction}>Connector</Th>
                  <Th>Visitors 7d</Th>
                  <Th onClick={() => toggleSort('last_seen_at')} sortable active={sort === 'last_seen_at'} dir={direction} title="Most recent verified liveness — a real heartbeat or a successful platform health-check (refreshed within the monitoring interval). Distinct from the raw connector heartbeat shown on the site page.">Last seen</Th>
                </tr>
              </thead>
              <tbody className={clsx(isFetching && 'opacity-60')}>
                {sites.map((site) => (
                  <tr key={site.uuid} className="border-b border-line transition last:border-0 hover:bg-surface-soft">
                    <td className="px-4 py-3.5">
                      <div className="flex items-center gap-3">
                        <FavAvatar domain={site.domain} />
                        <div className="min-w-0">
                          <div className="flex items-center gap-2">
                            <Link to={`/websites/${site.uuid}`} className="font-semibold text-ink hover:text-brand-700 hover:underline">
                              {site.domain}
                            </Link>
                            {site.has_updates && (
                              <span title={updatesSummary(site)}>
                                <Pill tone="warn" dot>Updates</Pill>
                              </span>
                            )}
                          </div>
                          {site.is_multisite && <div className="text-[11.5px] text-ink-muted">Multisite network</div>}
                        </div>
                      </div>
                    </td>
                    {user?.is_owner && (
                      <td className="px-4 py-3.5">
                        {site.owner ? (
                          <span className="text-ink-soft" title={site.owner.email}>{site.owner.name}</span>
                        ) : (
                          <span className="text-ink-muted">Unassigned</span>
                        )}
                      </td>
                    )}
                    <td className="px-4 py-3.5">
                      <SiteStatusPill status={site.status as SiteStatus} needsAttention={!site.origin_ip || !site.origin_ip_verified} />
                    </td>
                    <td className="px-4 py-3.5">
                      <UptimeCell site={site} />
                    </td>
                    <td className="px-4 py-3.5">
                      {site.origin_ip ? <VerifiedPill verified={site.origin_ip_verified} /> : <span className="text-ink-muted">—</span>}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3.5 font-mono text-xs text-ink-body">{site.wp_version ?? '—'}</td>
                    <td className="whitespace-nowrap px-4 py-3.5 font-mono text-xs text-ink-body">{site.plugin_version ?? '—'}</td>
                    <td className="px-4 py-3.5">
                      <VisitorsCell site={site} />
                    </td>
                    <td className="whitespace-nowrap px-4 py-3.5 text-ink-muted" title={site.last_heartbeat_at ? `Last connector heartbeat ${timeAgo(site.last_heartbeat_at)}` : undefined}>{timeAgo(site.last_seen_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {meta && meta.total > 0 && <Pagination meta={meta} onPage={(p) => update({ page: String(p) })} />}
        </div>
      ) : (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {sites.map((site) => (
              <SiteCard key={site.uuid} site={site} showOwner={!!user?.is_owner} />
            ))}
          </div>
          {meta && meta.total > 0 && (
            <div className="card mt-4">
              <Pagination meta={meta} onPage={(p) => update({ page: String(p) })} />
            </div>
          )}
        </>
      )}
    </div>
  );
}

/* -------------------------------------------------------------------------- */
/* Bits                                                                       */
/* -------------------------------------------------------------------------- */

function FilterChip({
  label,
  count,
  active,
  onClick,
  tone,
}: {
  label: string;
  count?: number;
  active: boolean;
  onClick: () => void;
  tone?: 'ok' | 'warn' | 'bad';
}) {
  const dotColor = tone === 'ok' ? 'bg-success' : tone === 'warn' ? 'bg-warning' : tone === 'bad' ? 'bg-danger' : '';
  return (
    <button
      type="button"
      onClick={onClick}
      className={clsx(
        'inline-flex items-center gap-1.5 rounded-[9px] border px-3 py-1.5 text-[12.5px] font-semibold transition',
        active
          ? 'border-transparent bg-brand-100 text-brand-700'
          : 'border-line-strong bg-surface text-ink-soft hover:border-brand-500 hover:text-brand-600',
      )}
    >
      {dotColor && <span className={clsx('h-1.5 w-1.5 rounded-full', dotColor)} />}
      {label}
      {typeof count === 'number' && <span className="text-[11px] opacity-70">{count}</span>}
    </button>
  );
}

function ViewButton({ active, onClick, label, children }: { active: boolean; onClick: () => void; label: string; children: React.ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      className={clsx('grid place-items-center rounded-[7px] px-2.5 py-1.5 transition', active ? 'bg-surface text-brand-600 shadow-card' : 'text-ink-muted hover:text-ink-soft')}
    >
      <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
        {children}
      </svg>
    </button>
  );
}

function Th({
  children,
  sortable,
  active,
  dir,
  onClick,
  title,
}: {
  children: React.ReactNode;
  sortable?: boolean;
  active?: boolean;
  dir?: string;
  onClick?: () => void;
  title?: string;
}) {
  return (
    <th
      title={title}
      className={clsx(
        'whitespace-nowrap border-b border-line px-4 py-3.5 text-left text-[10.5px] font-semibold uppercase tracking-wider text-ink-muted',
        sortable && 'cursor-pointer select-none hover:text-ink-soft',
      )}
      onClick={onClick}
    >
      <span className="inline-flex items-center gap-1">
        {children}
        {sortable && (
          <svg className={clsx('h-3 w-3', active ? 'text-brand-600' : 'text-ink-muted/50')} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
            {active ? (
              dir === 'asc' ? <path d="M12 5l7 7H5l7-7z" /> : <path d="M12 19l-7-7h14l-7 7z" />
            ) : (
              <path strokeLinecap="round" strokeLinejoin="round" d="M8 9l4-4 4 4m0 6l-4 4-4-4" />
            )}
          </svg>
        )}
      </span>
    </th>
  );
}

/** Visitors 7d — numeric only (the trend line now lives in the Uptime column). */
function VisitorsCell({ site }: { site: Site }) {
  return <span className="tnum text-sm font-semibold text-ink">{site.visitors_7d.toLocaleString()}</span>;
}

/** Tone for an uptime percentage: green when healthy, amber when degraded,
 *  red once availability drops below the SLA-ish 95% mark. */
function uptimeColor(pct: number): string {
  if (pct >= 99) return '#10b981'; // success
  if (pct >= 95) return '#f59e0b'; // warning
  return '#ef4444'; // danger
}

/** 7-Day Uptime cell — a small trend line above the headline percentage, matching
 *  the Websites list design. Shows an honest em-dash until the site reports. */
function UptimeCell({ site }: { site: Site }) {
  if (site.uptime_7d_pct === null) {
    return <span className="text-ink-muted">—</span>;
  }
  const pct = site.uptime_7d_pct;
  const color = uptimeColor(pct);
  const hasTrend = site.uptime_trend_7d && site.uptime_trend_7d.length >= 2;
  return (
    <div className="flex flex-col gap-1">
      {hasTrend && (
        <div className="h-[24px] w-[84px]">
          <Sparkline data={site.uptime_trend_7d} color={color} />
        </div>
      )}
      <span className="tnum text-[13px] font-semibold" style={{ color }}>
        {pct.toFixed(1)}%
      </span>
    </div>
  );
}

function SiteCard({ site, showOwner }: { site: Site; showOwner: boolean }) {
  return (
    <Link to={`/websites/${site.uuid}`} className="card block p-[18px] transition-all hover:-translate-y-0.5 hover:shadow-card-hover">
      <div className="mb-3.5 flex items-center gap-3">
        <FavAvatar domain={site.domain} />
        <div className="min-w-0 flex-1">
          <h4 className="truncate font-disp text-[15px] font-semibold text-ink">{site.domain}</h4>
          <p className="text-[11.5px] text-ink-muted">
            {showOwner && site.owner ? site.owner.name : site.is_multisite ? 'Multisite network' : 'Single site'}
          </p>
        </div>
        <SiteStatusPill status={site.status as SiteStatus} needsAttention={!site.origin_ip || !site.origin_ip_verified} />
      </div>
      <div className="flex justify-between gap-2 border-t border-line pt-3.5">
        <CardStat
          label="7d Uptime"
          value={site.uptime_7d_pct === null ? '—' : `${site.uptime_7d_pct.toFixed(1)}%`}
        />
        <CardStat label="Visitors 7d" value={site.visitors_7d.toLocaleString()} />
        <CardStat label="Connector" value={site.plugin_version ?? '—'} mono />
      </div>
      <div className="mt-3 text-[11.5px] text-ink-muted" title={site.last_heartbeat_at ? `Last connector heartbeat ${timeAgo(site.last_heartbeat_at)}` : undefined}>Last seen {timeAgo(site.last_seen_at)}</div>
    </Link>
  );
}

function CardStat({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <div className="min-w-0">
      <div className="text-[10.5px] font-semibold uppercase tracking-wide text-ink-muted">{label}</div>
      <div className={clsx('mt-0.5 truncate font-disp text-base font-semibold text-ink', mono && 'font-mono text-sm')}>{value}</div>
    </div>
  );
}

function Pagination({ meta, onPage }: { meta: Paginated<Site>['meta']; onPage: (p: number) => void }) {
  return (
    <div className="flex items-center justify-between border-t border-line px-4 py-3 text-sm text-ink-body">
      <span>
        {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
      </span>
      <div className="flex items-center gap-2">
        <button className="btn-secondary btn-sm" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
          Previous
        </button>
        <span className="px-1">
          Page {meta.current_page} of {meta.last_page}
        </span>
        <button className="btn-secondary btn-sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
          Next
        </button>
      </div>
    </div>
  );
}
