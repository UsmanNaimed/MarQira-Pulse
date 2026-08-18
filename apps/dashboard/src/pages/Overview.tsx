import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import type { JSX } from 'react';
import { api } from '@/lib/api';
import type {
  OverviewResponse,
  FleetUptimeResponse,
  AuditLog,
  Paginated,
  Site,
} from '@/types';
import { ErrorState, LoadingState, EmptyState, FavAvatar, Pill } from '@/components/ui';
import { CountUp, AreaChart, Seg, PulseLine } from '@/components/charts';
import { useAuth } from '@/context/AuthContext';
import { timeAgo, humanizeEvent } from '@/lib/format';

/* -------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* -------------------------------------------------------------------------- */

function withAccount(path: string, account: string): string {
  if (!account) return path;
  return path + (path.includes('?') ? '&' : '?') + 'account=' + account;
}

type MetricTone = 'brand' | 'ok' | 'warn' | 'indigo';

const TONE_TILE: Record<MetricTone, string> = {
  brand: 'bg-brand-100 text-brand-700',
  ok: 'bg-success/10 text-success-text',
  warn: 'bg-warning/15 text-warning-text',
  indigo: 'bg-indigo-brand/10 text-indigo-brand',
};

const TONE_TREND: Record<MetricTone, string> = {
  brand: 'text-success-text',
  ok: 'text-success-text',
  warn: 'text-ink-muted',
  indigo: 'text-ink-muted',
};

/* -------------------------------------------------------------------------- */
/* Metric card                                                                */
/* -------------------------------------------------------------------------- */

function MetricCard({
  tone,
  icon,
  label,
  value,
  trend,
  to,
}: {
  tone: MetricTone;
  icon: JSX.Element;
  label: string;
  value: number;
  trend: string;
  to?: string;
}) {
  const body = (
    <div className="card h-full p-[18px] transition-all hover:-translate-y-0.5 hover:shadow-card-hover">
      <div className="flex items-center justify-between">
        <span className={clsx('grid h-[38px] w-[38px] place-items-center rounded-pill', TONE_TILE[tone])}>
          <svg className="h-[19px] w-[19px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8}>
            {icon}
          </svg>
        </span>
      </div>
      <p className="mt-3.5 text-[12.5px] font-medium text-ink-muted">{label}</p>
      <p className="mt-0.5 font-disp text-[30px] font-bold leading-none tracking-tight text-ink">
        <CountUp value={value} />
      </p>
      <p className={clsx('mt-2 text-xs font-semibold', TONE_TREND[tone])}>{trend}</p>
    </div>
  );
  return to ? (
    <Link to={to} className="block">
      {body}
    </Link>
  ) : (
    body
  );
}

/* -------------------------------------------------------------------------- */
/* Overview page                                                              */
/* -------------------------------------------------------------------------- */

export default function Overview() {
  const { user } = useAuth();
  const [searchParams] = useSearchParams();
  const account = searchParams.get('account') ?? '';

  const params = account ? { account } : {};

  const overview = useQuery({
    queryKey: ['overview', account],
    queryFn: async () => (await api.get<OverviewResponse>('/api/dashboard/overview', { params })).data,
    refetchInterval: 30000,
    refetchOnWindowFocus: true,
  });

  return (
    <div>
      <div className="mb-[22px] flex flex-wrap items-end justify-between gap-3.5">
        <div>
          <h1 className="font-disp text-[26px] font-bold tracking-tight text-ink">Overview</h1>
          <p className="mt-1 text-sm text-ink-muted">A live snapshot of every site you monitor.</p>
        </div>
        <span className="live-chip">
          <span className="pdot" /> Live · auto-refreshing
        </span>
      </div>

      {overview.isLoading && <LoadingState />}
      {overview.isError && (
        <ErrorState
          message={(overview.error as Error)?.message ?? 'Could not load the overview.'}
          onRetry={overview.refetch}
        />
      )}

      {overview.data && (
        <>
          <PulseHero data={overview.data} />

          <div className="mb-[18px] grid grid-cols-2 gap-4 lg:grid-cols-4">
            <MetricCard
              tone="brand"
              label="Total sites"
              value={overview.data.cards.total}
              trend={
                overview.data.trends.sites_added_this_month > 0
                  ? `▲ ${overview.data.trends.sites_added_this_month} this month`
                  : 'No new sites this month'
              }
              to={withAccount('/websites', account)}
              icon={<><circle cx="12" cy="12" r="9" /><path d="M3.6 9h16.8M3.6 15h16.8" /></>}
            />
            <MetricCard
              tone="ok"
              label="Online now"
              value={overview.data.cards.online}
              trend={
                overview.data.trends.uptime_7d_pct !== null
                  ? `${overview.data.trends.uptime_7d_pct}% uptime · 7d`
                  : 'No uptime data yet'
              }
              to={withAccount('/websites?status=online', account)}
              icon={<path d="m9 12.5 2 2 4-4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />}
            />
            <MetricCard
              tone="warn"
              label="Needs attention"
              value={overview.data.cards.needs_attention}
              trend={
                overview.data.cards.needs_attention > 0
                  ? `${overview.data.cards.needs_attention} pending review`
                  : 'All sites reachable'
              }
              to={withAccount('/websites?needs_attention=1', account)}
              icon={<path d="M12 9v4m0 3h.01M10.3 3.9 2.4 17.4A1.9 1.9 0 0 0 4 20.3h16a1.9 1.9 0 0 0 1.6-2.9L13.7 3.9a1.9 1.9 0 0 0-3.4 0z" />}
            />
            <MetricCard
              tone="indigo"
              label="Updates available"
              value={overview.data.cards.updates_available}
              trend={updatesBreakdownLabel(overview.data.trends.updates_breakdown)}
              to={withAccount('/websites', account)}
              icon={<path d="M12 16V9m0 0 3 3m-3-3-3 3M6.8 19.5A4.5 4.5 0 0 1 5.3 10.7 5.25 5.25 0 0 1 15.6 8.4a3 3 0 0 1 3.7 3.8A3.75 3.75 0 0 1 18 19.5z" />}
            />
          </div>

          <div className="mb-[18px] grid gap-4 lg:grid-cols-[1.6fr_1fr]">
            <FleetUptimeCard account={account} />
            {user?.is_owner ? <ActivityFeedCard account={account} /> : <AttentionCard account={account} />}
          </div>

          <UpdateQueueCard account={account} />

          <div className="mt-[18px] grid gap-4 lg:grid-cols-2">
            <ConnectorReleaseCard data={overview.data} />
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

function updatesBreakdownLabel(b: OverviewResponse['trends']['updates_breakdown']): string {
  const parts: string[] = [];
  if (b.core > 0) parts.push(`${b.core} core`);
  if (b.plugins > 0) parts.push(`${b.plugins} plugins`);
  if (b.themes > 0) parts.push(`${b.themes} themes`);
  return parts.length > 0 ? parts.join(' · ') : 'Everything up to date';
}

/* -------------------------------------------------------------------------- */
/* Hero — fleet health, derived from REAL scoped counts + uptime.             */
/* -------------------------------------------------------------------------- */

function PulseHero({ data }: { data: OverviewResponse }) {
  const { cards, trends } = data;
  // Fleet health: prefer the real 7-day availability average; fall back to the
  // live online/total ratio when uptime has not been collected yet.
  const healthPct =
    trends.uptime_7d_pct !== null
      ? trends.uptime_7d_pct
      : cards.total > 0
        ? Math.round((cards.online / cards.total) * 1000) / 10
        : null;

  return (
    <div className="relative mb-[18px] overflow-hidden rounded-cardlg border border-line px-7 py-[26px] shadow-card-hover"
      style={{ background: 'linear-gradient(120deg,#151f3f 0%,#0e1730 60%,#0b1428 100%)' }}
    >
      <div
        className="pointer-events-none absolute inset-0"
        style={{
          background:
            'radial-gradient(60% 120% at 85% 0,rgba(34,184,255,.22),transparent 55%),radial-gradient(50% 100% at 10% 100%,rgba(109,94,252,.22),transparent 55%)',
        }}
      />
      <div className="relative z-10 flex flex-wrap items-center justify-between gap-3.5">
        <div className="text-white">
          <div className="flex items-center gap-2 text-[12.5px] font-medium text-white/60">
            <span className="pdot" /> Fleet health · last 7 days
          </div>
          <div className="mt-2 font-disp text-[46px] font-bold leading-none tracking-tight">
            {healthPct !== null ? (
              <>
                <CountUpDecimal value={healthPct} />
                <small className="text-xl font-semibold text-white/50">%</small>
              </>
            ) : (
              <span className="text-2xl font-semibold text-white/70">No data yet</span>
            )}
          </div>
          <div className="mt-2 text-[12.5px] text-white/55">
            {cards.online} of {cards.total} {cards.total === 1 ? 'site' : 'sites'} online
            {cards.needs_attention > 0 && (
              <>
                {' · '}
                <span className="font-semibold text-[#fbbf24]">{cards.needs_attention} need attention</span>
              </>
            )}
          </div>
        </div>
        <div className="flex gap-4">
          <HeroStat value={cards.online} label="Online" tone="text-[#34d399]" />
          <HeroStat value={cards.needs_attention} label="Attention" tone="text-[#fbbf24]" />
          <HeroStat value={cards.offline} label="Offline" tone="text-[#fb7185]" />
          <HeroStat value={cards.updates_available} label="Updates" tone="text-white" />
        </div>
      </div>
      <div className="relative z-10 mt-3.5 h-24">
        <PulseLine />
      </div>
    </div>
  );
}

function HeroStat({ value, label, tone }: { value: number; label: string; tone: string }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className={clsx('font-disp text-[22px] font-bold', tone)}>{value}</span>
      <span className="text-[11px] font-medium text-white/55">{label}</span>
    </div>
  );
}

// CountUp variant that preserves one decimal place (for percentages).
function CountUpDecimal({ value }: { value: number }) {
  return <span className="tnum">{value}</span>;
}

/* -------------------------------------------------------------------------- */
/* Fleet uptime chart — REAL availability series from /fleet/uptime.          */
/* -------------------------------------------------------------------------- */

function FleetUptimeCard({ account }: { account: string }) {
  const [range, setRange] = useState<7 | 30 | 90>(7);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['fleet-uptime', account, range],
    queryFn: async () =>
      (
        await api.get<FleetUptimeResponse>('/api/dashboard/fleet/uptime', {
          params: { range, ...(account ? { account } : {}) },
        })
      ).data,
    refetchInterval: 60000,
  });

  // Only the portion of the series that has a real denominator (sites enrolled
  // by that day) carries a number; earlier days are null and excluded so we
  // never plot a fabricated value.
  const values = (data?.series ?? []).map((p) => p.uptime_pct).filter((v): v is number => v !== null);

  return (
    <div className="card">
      <div className="flex items-center justify-between border-b border-line px-5 py-[18px]">
        <h3 className="font-disp text-base font-semibold text-ink">Fleet uptime</h3>
        <Seg
          value={range}
          onChange={(v) => setRange(v)}
          options={[
            { label: '7d', value: 7 },
            { label: '30d', value: 30 },
            { label: '90d', value: 90 },
          ]}
        />
      </div>
      <div className="px-3 pb-2.5 pt-[18px]">
        {isLoading && <div className="flex h-[220px] items-center justify-center"><LoadingState /></div>}
        {isError && (
          <div className="px-2">
            <ErrorState message="Could not load uptime." onRetry={refetch} />
          </div>
        )}
        {data && !isLoading && !isError && (
          <>
            {data.has_data && values.length >= 2 ? (
              <>
                <AreaChart values={values} domain={[0, 100]} gradientId="ovFill" />
                <div className="flex justify-between px-3.5 pb-3.5 pt-0.5 text-[11px] text-ink-muted">
                  <span>{range} days ago</span>
                  <span>
                    today{data.average_uptime_pct !== null ? ` · avg ${data.average_uptime_pct}%` : ''}
                  </span>
                </div>
              </>
            ) : (
              <div className="px-2">
                <EmptyState
                  title="No uptime data yet"
                  description="Uptime is derived from connector heartbeats. Once your sites start reporting in, their availability trend will appear here."
                />
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}

/* -------------------------------------------------------------------------- */
/* Live activity feed (Owner) — REAL org audit trail.                         */
/* -------------------------------------------------------------------------- */

function eventTone(event: string): 'ok' | 'warn' | 'bad' | 'brand' {
  const e = event.toLowerCase();
  if (e.includes('offline') || e.includes('remove') || e.includes('revoke') || e.includes('delete') || e.includes('fail')) return 'bad';
  if (e.includes('update') || e.includes('attention') || e.includes('warn')) return 'warn';
  if (e.includes('online') || e.includes('verify') || e.includes('complete') || e.includes('activate')) return 'ok';
  return 'brand';
}

const FEED_ICON: Record<'ok' | 'warn' | 'bad' | 'brand', string> = {
  bad: 'M12 9v4m0 3h.01',
  warn: 'M12 16V9m0 0 3 3m-3-3-3 3',
  ok: 'm9 12.5 2 2 4-4.5',
  brand: 'M12 8v8m-4-4h8',
};

const FEED_TILE: Record<'ok' | 'warn' | 'bad' | 'brand', string> = {
  ok: 'bg-success/10 text-success-text',
  warn: 'bg-warning/15 text-warning-text',
  bad: 'bg-danger/10 text-danger',
  brand: 'bg-brand-100 text-brand-700',
};

function ActivityFeedCard({ account }: { account: string }) {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['audit-feed', account],
    queryFn: async () =>
      (await api.get<Paginated<AuditLog>>('/api/dashboard/audit-logs', { params: { per_page: 8 } })).data,
    refetchInterval: 30000,
  });

  return (
    <div className="card">
      <div className="flex items-center justify-between border-b border-line px-5 py-[18px]">
        <h3 className="font-disp text-base font-semibold text-ink">Live activity</h3>
        <span className="live-chip">
          <span className="pdot" /> Live
        </span>
      </div>
      <div className="p-2.5">
        {isLoading && <LoadingState />}
        {isError && <ErrorState message="Could not load activity." onRetry={refetch} />}
        {data && data.data.length === 0 && (
          <EmptyState title="No activity yet" description="Dashboard actions and site events will show up here as they happen." />
        )}
        {data && data.data.length > 0 && (
          <ul className="flex flex-col">
            {data.data.map((log) => {
              const tone = eventTone(log.event);
              return (
                <li key={log.uuid} className="flex items-start gap-3 rounded-card px-3 py-2.5">
                  <span className={clsx('mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full', FEED_TILE[tone])}>
                    <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
                      <circle cx="12" cy="12" r="9" />
                      <path d={FEED_ICON[tone]} />
                    </svg>
                  </span>
                  <div className="min-w-0">
                    <div className="text-[13px] text-ink-body">
                      {humanizeEvent(log.event)}
                      {log.actor?.name && <span className="text-ink-muted"> · {log.actor.name}</span>}
                    </div>
                    <div className="text-xs text-ink-muted">{timeAgo(log.created_at)}</div>
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </div>
  );
}

/* -------------------------------------------------------------------------- */
/* Attention card (Subscriber) — scoped list of their own flagged sites.      */
/* Subscribers never see the org-wide audit trail (strict isolation).         */
/* -------------------------------------------------------------------------- */

function AttentionCard({ account }: { account: string }) {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['attention-sites', account],
    queryFn: async () =>
      (
        await api.get<Paginated<Site>>('/api/dashboard/sites', {
          params: { needs_attention: 1, per_page: 6, ...(account ? { account } : {}) },
        })
      ).data,
    refetchInterval: 30000,
  });

  return (
    <div className="card">
      <div className="flex items-center justify-between border-b border-line px-5 py-[18px]">
        <h3 className="font-disp text-base font-semibold text-ink">Needs attention</h3>
        <Link to="/websites?needs_attention=1" className="text-xs font-semibold text-brand-600 hover:text-brand-700">
          View all
        </Link>
      </div>
      <div className="p-2.5">
        {isLoading && <LoadingState />}
        {isError && <ErrorState message="Could not load sites." onRetry={refetch} />}
        {data && data.data.length === 0 && (
          <EmptyState title="All clear" description="None of your sites currently need attention." />
        )}
        {data && data.data.length > 0 && (
          <ul className="flex flex-col">
            {data.data.map((site) => (
              <li key={site.uuid}>
                <Link to={`/websites/${site.uuid}`} className="flex items-center gap-3 rounded-card px-3 py-2.5 hover:bg-surface-soft">
                  <FavAvatar domain={site.domain} />
                  <div className="min-w-0 flex-1">
                    <div className="truncate text-[13px] font-medium text-ink-body">{site.domain}</div>
                    <div className="text-xs text-ink-muted">
                      {site.origin_ip === null ? 'Origin not determined yet' : 'Origin not verified'}
                    </div>
                  </div>
                  <Pill tone="warn" dot>
                    Attention
                  </Pill>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

/* -------------------------------------------------------------------------- */
/* Update queue — scoped sites with pending updates (REAL inventory).         */
/* -------------------------------------------------------------------------- */

function UpdateQueueCard({ account }: { account: string }) {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['update-queue', account],
    queryFn: async () =>
      (
        await api.get<Paginated<Site>>('/api/dashboard/sites', {
          params: { per_page: 100, ...(account ? { account } : {}) },
        })
      ).data,
    refetchInterval: 60000,
  });

  const withUpdates = (data?.data ?? []).filter((s) => s.has_updates).slice(0, 6);

  return (
    <div className="card">
      <div className="flex items-center justify-between border-b border-line px-5 py-[18px]">
        <h3 className="font-disp text-base font-semibold text-ink">Update queue</h3>
        <Link to={withAccount('/websites', account)} className="btn-ghost btn-sm">
          Manage updates
        </Link>
      </div>
      <div className="p-2.5">
        {isLoading && <LoadingState />}
        {isError && <ErrorState message="Could not load the update queue." onRetry={refetch} />}
        {data && withUpdates.length === 0 && (
          <EmptyState title="Everything is up to date" description="No pending core, plugin or theme updates across your sites." />
        )}
        {withUpdates.length > 0 && (
          <ul className="flex flex-col">
            {withUpdates.map((site) => (
              <li key={site.uuid}>
                <Link
                  to={`/websites/${site.uuid}`}
                  className="flex items-center gap-3 rounded-card px-3 py-2.5 hover:bg-surface-soft"
                >
                  <span className="grid h-[34px] w-[34px] shrink-0 place-items-center rounded-pill bg-indigo-brand/10 text-indigo-brand">
                    <svg className="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8}>
                      <path d="M12 3v12m0 0 4-4m-4 4-4-4" />
                    </svg>
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="truncate text-[13px] font-medium text-ink-body">{site.domain}</div>
                    <div className="text-xs text-ink-muted">{updateItemLabel(site)}</div>
                  </div>
                  <div className="flex shrink-0 items-center gap-1.5">
                    {site.core_updates_available && <Pill tone="warn">Core</Pill>}
                    {site.plugin_updates_available > 0 && <Pill tone="brand">{site.plugin_updates_available} plugin</Pill>}
                    {site.theme_updates_available > 0 && <Pill tone="neutral">{site.theme_updates_available} theme</Pill>}
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

function updateItemLabel(site: Site): string {
  const parts: string[] = [];
  if (site.core_updates_available) parts.push('WordPress core');
  if (site.plugin_updates_available > 0) parts.push(`${site.plugin_updates_available} plugin${site.plugin_updates_available === 1 ? '' : 's'}`);
  if (site.theme_updates_available > 0) parts.push(`${site.theme_updates_available} theme${site.theme_updates_available === 1 ? '' : 's'}`);
  return parts.join(' · ') || 'Updates available';
}

/* -------------------------------------------------------------------------- */
/* Connector release card (§11) — preserved existing functionality.          */
/* -------------------------------------------------------------------------- */

function ConnectorReleaseCard({ data }: { data: OverviewResponse }) {
  const version = data.latest_plugin_version;
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
          <Pill tone="warn" dot>
            Updates available
          </Pill>
        )}
      </div>
      <div className="mt-4 flex flex-wrap items-center gap-2">
        <a href={downloadUrl} className={clsx('btn-primary', !version && 'pointer-events-none opacity-50')}>
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
          </svg>
          Download Latest Plugin
        </a>
        <span className="text-xs text-ink-muted">Install or update the MarQira Connector on any WordPress site.</span>
      </div>
    </div>
  );
}
