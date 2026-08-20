import { useState, type ReactNode } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import { api } from '@/lib/api';
import type {
  AuditLog,
  Heartbeat,
  Paginated,
  SiteDetail,
  SitePostsResponse,
  SiteStatus,
  SiteUpdateItems,
  SiteUpdateStatus,
  SiteUser,
  SiteVisitorAnalytics,
} from '@/types';
import { EmptyState, ErrorState, LoadingState, Pill, SiteStatusPill, VerifiedPill } from '@/components/ui';
import { AreaChart, CountUp, Seg } from '@/components/charts';
import { formatDate, humanizeEvent, timeAgo } from '@/lib/format';

// Ordered by the operator's journey: what's happening now → where the traffic
// comes from → who uses it → what's on it → the technical/platform detail →
// then the things that need attention or action.
const TABS = ['Overview', 'Traffic Analysis', 'Users', 'Content', 'WordPress', 'Plugin Status', 'Network', 'Connection History', 'Updates', 'Activity'] as const;
type Tab = (typeof TABS)[number];

/* -------------------------------------------------------------------------- */
/* Small presentational primitives (match the redesign, token-driven).        */
/* -------------------------------------------------------------------------- */

function KV({ k, v, mono, link }: { k: string; v: ReactNode; mono?: boolean; link?: boolean }) {
  return (
    <div className="flex items-center justify-between gap-4 border-b border-line px-[18px] py-[13px] last:border-b-0">
      <span className="text-[13px] font-medium text-ink-muted">{k}</span>
      <span
        className={clsx(
          'break-all text-right text-[13.5px] font-semibold',
          mono && 'font-mono text-[12.5px]',
          link ? 'text-brand-600 font-medium' : 'text-ink',
        )}
      >
        {v ?? '—'}
      </span>
    </div>
  );
}

type IconTone = 'brand' | 'ok' | 'sky' | 'indigo';
const ICON_TONE: Record<IconTone, string> = {
  brand: 'bg-brand-600/[0.12] text-brand-600',
  ok: 'bg-success/[0.13] text-success-text',
  sky: 'bg-sky-brand/[0.14] text-sky-brand',
  indigo: 'bg-indigo-brand/[0.14] text-indigo-brand',
};

function InfoCard({ icon, tone = 'brand', title, children }: { icon: ReactNode; tone?: IconTone; title: string; children: ReactNode }) {
  return (
    <div className="card">
      <div className="flex items-center gap-[9px] px-[18px] pb-1 pt-4 font-disp text-[14px] font-semibold text-ink">
        <span className={clsx('grid h-7 w-7 place-items-center rounded-lg', ICON_TONE[tone])}>{icon}</span>
        {title}
      </div>
      <dl className="px-1 pb-1">{children}</dl>
    </div>
  );
}

function MTile({ label, value, tone, sub }: { label: string; value: ReactNode; tone?: 'ok' | 'brand'; sub?: string }) {
  return (
    <div className="card p-5">
      <div className="text-[12.5px] font-medium text-ink-muted">{label}</div>
      <div
        className={clsx(
          'mt-2 font-disp text-[34px] font-bold leading-none tracking-tight',
          tone === 'ok' ? 'text-success-text' : tone === 'brand' ? 'text-brand-600' : 'text-ink',
        )}
      >
        {value}
      </div>
      {sub && <div className="mt-[7px] text-xs text-ink-muted">{sub}</div>}
    </div>
  );
}

function QStat({ label, value, dot, title }: { label: ReactNode; value: ReactNode; dot?: boolean; title?: string }) {
  return (
    <div className="card p-[14px_16px]" title={title}>
      <div className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
        {dot && <span className="pdot" />}
        {label}
      </div>
      <div className="mt-[5px] flex items-center gap-[7px] font-disp text-[19px] font-bold text-ink">{value}</div>
    </div>
  );
}

function ChartHead({ title, right }: { title: string; right?: ReactNode }) {
  return (
    <div className="flex items-center justify-between border-b border-line px-5 py-[18px]">
      <h3 className="font-disp text-[15px] font-semibold text-ink">{title}</h3>
      {right}
    </div>
  );
}

/* Table shells shared across tabs */
function TableShell({ head, children }: { head: string[]; children: ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className="min-w-full border-collapse text-[13.5px]">
        <thead>
          <tr>
            {head.map((h) => (
              <th key={h} className="border-b border-line px-[18px] py-3 text-left text-[10.5px] font-semibold uppercase tracking-wider text-ink-muted whitespace-nowrap">
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  );
}

/* -------------------------------------------------------------------------- */
/* Icons (inline, stroke-based — mirror the redesign glyphs).                  */
/* -------------------------------------------------------------------------- */
const S = { fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, viewBox: '0 0 24 24' } as const;
const IconGlobe = () => (<svg {...S} className="h-[15px] w-[15px]"><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3c2.5 2.5 2.5 15 0 18M12 3c-2.5 2.5-2.5 15 0 18" /></svg>);
const IconServer = () => (<svg {...S} className="h-[15px] w-[15px]"><rect x="3" y="4" width="18" height="8" rx="2" /><rect x="3" y="14" width="18" height="6" rx="2" /><path d="M7 8h.01M7 17h.01" /></svg>);
const IconCode = () => (<svg {...S} className="h-[15px] w-[15px]"><path d="M4 17l6-6-6-6M12 19h8" /></svg>);
const IconShield = () => (<svg {...S} className="h-[15px] w-[15px]"><path d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6z" /><path d="m9 12 2 2 4-4" /></svg>);
const IconWp = () => (<svg {...S} className="h-[15px] w-[15px]"><circle cx="12" cy="12" r="9" /><path d="m8 12 2 5 2-10 2 5" /></svg>);
const IconPlug = () => (<svg {...S} className="h-[15px] w-[15px]"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1.5 1.5" /><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1.5-1.5" /></svg>);
const IconPulse = () => (<svg {...S} className="h-[15px] w-[15px]"><path d="M2 12h4l2-7 4 14 2-7h8" /></svg>);
const IconSearch = () => (<svg {...S} className="h-[15px] w-[15px]"><circle cx="11" cy="11" r="7" /><path d="m20 20-3-3" /></svg>);
const IconClock = () => (<svg {...S} className="h-4 w-4"><circle cx="12" cy="12" r="9" /><path d="M12 8v4l3 2" /></svg>);

export default function WebsiteDetail() {
  const { uuid = '' } = useParams();
  const navigate = useNavigate();
  const [tab, setTab] = useState<Tab>('Overview');
  const [isDeleting, setIsDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState('');

  const siteQuery = useQuery({
    queryKey: ['site', uuid],
    queryFn: async () => (await api.get<{ data: SiteDetail }>(`/api/dashboard/sites/${uuid}`)).data.data,
    refetchInterval: 15000, // Auto-refresh every 15s for live heartbeat/status tracking
    refetchOnWindowFocus: true, // Refresh when user returns to tab
  });

  if (siteQuery.isLoading) return <LoadingState />;
  if (siteQuery.isError)
    return (
      <ErrorState
        message={(siteQuery.error as { message?: string })?.message ?? 'This website could not be loaded (it may not exist).'}
        onRetry={siteQuery.refetch}
      />
    );

  const site = siteQuery.data!;

  const handleDelete = async () => {
    const confirmed = window.confirm(
      `Remove ${site.domain} from MarQira Pulse?\n\nThis revokes the connection key and tells the WordPress plugin to disconnect on its next heartbeat. Down-alert emails for this site will stop. This cannot be undone.`,
    );
    if (!confirmed) return;
    setIsDeleting(true);
    setDeleteError('');
    try {
      await api.delete(`/api/dashboard/sites/${site.uuid}`);
      navigate('/websites', { replace: true });
    } catch (err) {
      setDeleteError((err as { message?: string })?.message ?? 'Failed to remove website. Please try again.');
      setIsDeleting(false);
    }
  };

  return (
    <div>
      {/* Back link */}
      <Link to="/websites" className="mb-3.5 inline-flex items-center gap-[7px] text-[13.5px] font-semibold text-brand-600 hover:gap-2.5 transition-all">
        <svg {...S} strokeWidth={2} className="h-4 w-4"><path d="M15 18l-6-6 6-6" /></svg> Back to websites
      </Link>

      {/* Site header */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex items-center gap-[15px]">
          <div className="relative grid h-[52px] w-[52px] flex-shrink-0 place-items-center overflow-hidden rounded-[14px] bg-brand-600/[0.12] text-brand-600">
            <IconGlobe />
            <svg className="absolute inset-x-0 bottom-0 h-4" viewBox="0 0 60 16" preserveAspectRatio="none">
              <path d="M0,8 L18,8 L21,3 L24,13 L27,8 L60,8" fill="none" stroke="#22b8ff" strokeWidth={1.4} strokeLinecap="round" />
            </svg>
          </div>
          <div>
            <div className="flex flex-wrap items-center gap-[11px] font-disp text-[25px] font-bold leading-none tracking-tight text-ink">
              {site.domain}
              <SiteStatusPill status={site.status as SiteStatus} />
              {site.is_multisite && <Pill tone="brand">Multisite</Pill>}
            </div>
            <div className="mt-1.5 text-[13px] text-ink-muted">
              Last heartbeat <b className="font-semibold text-ink-soft">{timeAgo(site.last_heartbeat_at)}</b> · Enrolled{' '}
              <b className="font-semibold text-ink-soft">{formatDate(site.enrolled_at)}</b>
            </div>
          </div>
        </div>
        <div className="flex flex-col items-end gap-1">
          <div className="flex gap-2.5">
            <button type="button" onClick={() => siteQuery.refetch()} className="btn-ghost btn-sm" disabled={siteQuery.isFetching}>
              <svg {...S} strokeWidth={2} className="h-4 w-4"><path d="M21 12a9 9 0 1 1-3-6.7M21 4v5h-5" /></svg>
              {siteQuery.isFetching ? 'Refreshing…' : 'Refresh'}
            </button>
            <button type="button" onClick={handleDelete} disabled={isDeleting} className="btn-danger btn-sm">
              <svg {...S} strokeWidth={2} className="h-4 w-4"><path d="M3 6h18M8 6V4h8v2m1 0v14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V6" /></svg>
              {isDeleting ? 'Removing…' : 'Remove website'}
            </button>
          </div>
          {deleteError && <span className="text-xs text-danger">{deleteError}</span>}
        </div>
      </div>

      {/* Quick-stat strip */}
      <div className="my-[22px] grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <QStat dot label="Status" value={<SiteStatusPill status={site.status as SiteStatus} />} />
        <QStat label="WordPress" value={<span className="font-mono text-base">{site.wp_version ?? '—'}</span>} />
        <QStat label="PHP" value={<span className="font-mono text-base">{site.php_version ?? '—'}</span>} />
        <QStat label="Connector" value={<span className="font-mono text-base">{site.plugin_version ?? '—'}</span>} />
        <QStat label="Last seen" value={<span className="text-base">{timeAgo(site.last_seen_at)}</span>} title={`Most recent verified liveness — a real heartbeat or a successful platform health-check.${site.last_heartbeat_at ? ` Last connector heartbeat ${timeAgo(site.last_heartbeat_at)}.` : ''}`} />
      </div>

      {/* Tabs */}
      <div className="mb-[22px] flex flex-nowrap gap-0.5 overflow-x-auto border-b border-line [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        {TABS.map((t) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            className={clsx(
              'relative whitespace-nowrap px-[15px] py-3 text-sm font-semibold transition',
              tab === t ? 'text-brand-600' : 'text-ink-muted hover:text-ink-soft',
            )}
          >
            {t}
            {tab === t && <span className="absolute inset-x-3 -bottom-px h-[2.5px] rounded bg-brand-gradient" />}
          </button>
        ))}
      </div>

      {tab === 'Overview' && <OverviewTab site={site} />}
      {tab === 'Traffic Analysis' && <TrafficTab uuid={uuid} />}
      {tab === 'Users' && <UsersTab uuid={uuid} />}
      {tab === 'Content' && <ContentTab uuid={uuid} />}
      {tab === 'WordPress' && <WordPressTab site={site} />}
      {tab === 'Plugin Status' && <PluginStatusTab site={site} />}
      {tab === 'Network' && <NetworkTab site={site} />}
      {tab === 'Connection History' && <ConnectionHistoryTab uuid={uuid} />}
      {tab === 'Updates' && <UpdatesTab site={site} />}
      {tab === 'Activity' && <ActivityTab uuid={uuid} />}
    </div>
  );
}

/* ============================== OVERVIEW ================================== */
function OverviewTab({ site }: { site: SiteDetail }) {
  const healthy = site.status === 'online';
  const upToDate = !site.has_updates;
  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <InfoCard icon={<IconGlobe />} tone="brand" title="Identity">
        <KV k="Domain" v={site.domain} />
        <KV k="Home URL" v={site.home_url} link mono />
        <KV k="Site URL" v={site.site_url} link mono />
        <KV k="Status" v={<SiteStatusPill status={site.status as SiteStatus} />} />
        <KV k="Last seen" v={timeAgo(site.last_seen_at)} />
      </InfoCard>

      <InfoCard icon={<IconServer />} tone="sky" title="Infrastructure">
        <KV k="Origin IP" v={site.origin_ip} mono />
        <KV k="Origin verified" v={site.origin_ip ? <VerifiedPill verified={site.origin_ip_verified} /> : '—'} />
        <KV k="Server IP" v={site.server_ip} mono />
      </InfoCard>

      <InfoCard icon={<IconCode />} tone="indigo" title="Software">
        <KV k="WordPress" v={site.wp_version} mono />
        <KV k="PHP" v={site.php_version} mono />
        <KV k="Connector" v={site.plugin_version} mono />
      </InfoCard>

      <InfoCard icon={<IconShield />} tone="ok" title="Health at a glance">
        <KV k="Connection" v={<Pill tone={healthy ? 'ok' : 'bad'} dot>{healthy ? 'Healthy' : 'Offline'}</Pill>} />
        <KV k="Origin trust" v={site.origin_ip ? <VerifiedPill verified={site.origin_ip_verified} /> : <Pill tone="neutral">Unknown</Pill>} />
        <KV k="Updates" v={<Pill tone={upToDate ? 'ok' : 'warn'} dot>{upToDate ? 'Up to date' : 'Updates available'}</Pill>} />
      </InfoCard>
    </div>
  );
}

/* ============================== TRAFFIC ================================== */
function TrafficTab({ uuid }: { uuid: string }) {
  const [range, setRange] = useState<30 | 7>(30);
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['site-visitors', uuid],
    queryFn: async () => (await api.get<SiteVisitorAnalytics>(`/api/dashboard/sites/${uuid}/visitors?days=30`)).data,
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message={(error as Error)?.message ?? 'Failed to load visitor data.'} />;
  if (!data || data.daily_metrics.length === 0) {
    return <EmptyState title="No visitor data yet" description="Daily visitor and pageview metrics appear here once the site's connector reports analytics." />;
  }

  const metrics = data.daily_metrics;
  const windowed = range === 7 ? metrics.slice(-7) : metrics;
  const avgDaily = Math.round(data.total_visitors / Math.max(metrics.length, 1));

  return (
    <div className="space-y-[18px]">
      <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
        <MTile label="Total visitors (30d)" value={<CountUp value={data.total_visitors} />} sub="Rolling 30-day window" />
        <MTile
          label="Growth vs previous period"
          value={<span>{data.growth > 0 ? '▲ ' : data.growth < 0 ? '▼ ' : ''}{data.growth}%</span>}
          tone={data.growth >= 0 ? 'ok' : undefined}
          sub="vs. prior 30 days"
        />
        <MTile label="Avg daily visitors" value={<CountUp value={avgDaily} />} sub="Across the period" />
      </div>

      <div className="card">
        <ChartHead
          title="Visitor trend"
          right={<Seg options={[{ label: '30d', value: 30 }, { label: '7d', value: 7 }]} value={range} onChange={(v) => setRange(v)} />}
        />
        <div className="px-3 pb-2 pt-4">
          <AreaChart values={windowed.map((m) => m.visitors)} gradientId="trFill" height={230} />
          <div className="flex justify-between px-3.5 pb-3 pt-0.5 text-[11px] text-ink-muted">
            <span>{windowed[0]?.date}</span>
            <span>today</span>
          </div>
        </div>
      </div>

      <div className="card">
        <ChartHead title="Daily breakdown" />
        <TableShell head={['Date', 'Visitors', 'Pageviews', 'Pages / visitor']}>
          {metrics.slice().reverse().map((m) => (
            <tr key={m.date} className="transition hover:bg-surface-soft">
              <td className="border-b border-line px-[18px] py-[13px] font-semibold text-ink">{m.date}</td>
              <td className="tnum border-b border-line px-[18px] py-[13px] text-ink-body">{m.visitors.toLocaleString()}</td>
              <td className="tnum border-b border-line px-[18px] py-[13px] text-ink-body">{m.pageviews.toLocaleString()}</td>
              <td className="tnum border-b border-line px-[18px] py-[13px] text-ink-body">{m.visitors > 0 ? (m.pageviews / m.visitors).toFixed(2) : '—'}</td>
            </tr>
          ))}
        </TableShell>
      </div>
    </div>
  );
}

/* ============================== USERS ================================== */
function UsersTab({ uuid }: { uuid: string }) {
  const [page, setPage] = useState(1);
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['users', uuid, page],
    queryFn: async () => (await api.get<Paginated<SiteUser>>(`/api/dashboard/sites/${uuid}/users?per_page=50&page=${page}`)).data,
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message={(error as Error)?.message ?? 'Could not load users.'} onRetry={refetch} />;
  if (!data || data.data.length === 0) {
    return <EmptyState title="No user data yet" description="User snapshots will appear here once the connector ships user data." />;
  }

  const users = data.data;
  const meta = data.meta;
  const admins = users.filter((u) => (u.roles ?? []).some((r) => r.toLowerCase() === 'administrator')).length;
  const sevenDaysAgo = Date.now() - 7 * 24 * 3600 * 1000;
  const loggedIn7d = users.filter((u) => u.last_login_at && new Date(u.last_login_at).getTime() >= sevenDaysAgo).length;

  return (
    <div className="space-y-[18px]">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <MTile label="Total users" value={<CountUp value={meta.total} />} sub="On this site" />
        <MTile label="Administrators" value={<CountUp value={admins} />} tone="brand" sub="Full access (this page)" />
        <MTile label="Logged in (7d)" value={<CountUp value={loggedIn7d} />} sub="Active sessions (this page)" />
      </div>

      <div className="card">
        <TableShell head={['User', 'Email', 'Roles', 'Registered', 'Last login', 'Login IP']}>
          {users.map((u, i) => (
            <tr key={i} className="transition hover:bg-surface-soft">
              <td className="border-b border-line px-[18px] py-[13px]">
                <div className="font-semibold text-ink">{u.display_name || u.user_login}</div>
                <div className="text-[11.5px] text-ink-muted">{u.user_login}</div>
              </td>
              <td className="border-b border-line px-[18px] py-[13px] font-mono text-[12.5px] text-ink-body">{u.user_email || '—'}</td>
              <td className="border-b border-line px-[18px] py-[13px]">
                {u.roles && u.roles.length > 0 ? (
                  <div className="flex flex-wrap gap-1">
                    {u.roles.map((r, idx) => (
                      <Pill key={idx} tone={r.toLowerCase() === 'administrator' ? 'brand' : 'neutral'}>{r}</Pill>
                    ))}
                  </div>
                ) : '—'}
              </td>
              <td className="whitespace-nowrap border-b border-line px-[18px] py-[13px] text-ink-body">{formatDate(u.user_registered)}</td>
              <td className="whitespace-nowrap border-b border-line px-[18px] py-[13px] text-ink-muted">{u.last_login_at ? timeAgo(u.last_login_at) : '—'}</td>
              <td className="whitespace-nowrap border-b border-line px-[18px] py-[13px] font-mono text-[12px] text-ink-muted">{u.last_login_ip || '—'}</td>
            </tr>
          ))}
        </TableShell>
      </div>

      <Pagination page={page} meta={meta} noun="users" onChange={setPage} />
    </div>
  );
}

/* ============================== CONTENT ================================== */
function ContentTab({ uuid }: { uuid: string }) {
  const [page, setPage] = useState(1);
  const [statusFilter, setStatusFilter] = useState<'all' | 'publish' | 'future'>('all');

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['posts', uuid, page, statusFilter],
    queryFn: async () => {
      const params = new URLSearchParams({ per_page: '50', page: String(page) });
      if (statusFilter !== 'all') params.set('status', statusFilter);
      return (await api.get<SitePostsResponse>(`/api/dashboard/sites/${uuid}/posts?${params}`)).data;
    },
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message={(error as Error)?.message ?? 'Could not load content.'} onRetry={refetch} />;
  if (!data || (data.data.length === 0 && statusFilter === 'all')) {
    return <EmptyState title="No content data yet" description="Post snapshots will appear here once the connector ships content data." />;
  }

  const posts = data.data;
  const meta = data.meta;
  const summary = data.summary; // site-wide, deduplicated counts computed server-side

  return (
    <div className="space-y-[18px]">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <MTile label="Total posts" value={<CountUp value={summary.total} />} sub="All content types" />
        <MTile label="Published" value={<CountUp value={summary.published} />} tone="ok" sub="Live on the site" />
        <MTile label="Scheduled" value={<CountUp value={summary.scheduled} />} tone="brand" sub="Queued to publish" />
      </div>

      <div className="flex flex-wrap gap-2">
        {(['all', 'publish', 'future'] as const).map((status) => (
          <button
            key={status}
            onClick={() => { setStatusFilter(status); setPage(1); }}
            className={clsx(
              'rounded-[9px] border px-3.5 py-[7px] text-[12.5px] font-semibold transition',
              statusFilter === status
                ? 'border-transparent bg-brand-600/[0.12] text-brand-600'
                : 'border-line-strong bg-surface text-ink-body hover:border-brand-600 hover:text-brand-600',
            )}
          >
            {status === 'all' ? 'All' : status === 'publish' ? 'Published' : 'Scheduled'}
          </button>
        ))}
      </div>

      <div className="card">
        {posts.length === 0 ? (
          <EmptyState title="Nothing here" description="No posts match this filter." />
        ) : (
          <TableShell head={['Title', 'Author', 'Status', 'Type', 'Date', 'Modified']}>
            {posts.map((post, i) => (
              <tr key={i} className="transition hover:bg-surface-soft">
                <td className="max-w-md border-b border-line px-[18px] py-[13px]">
                  <div className="truncate font-semibold text-ink">{post.post_title || '(no title)'}</div>
                  {(post.permalink || post.guid) && (
                    <a href={post.permalink || post.guid || undefined} target="_blank" rel="noopener noreferrer" className="text-xs font-medium text-brand-600 hover:underline">
                      {post.post_status === 'publish' ? 'View →' : 'Preview →'}
                    </a>
                  )}
                </td>
                <td className="border-b border-line px-[18px] py-[13px] text-ink-body">{post.post_author_name || `ID ${post.post_author_id}`}</td>
                <td className="border-b border-line px-[18px] py-[13px]">
                  <Pill tone={post.post_status === 'publish' ? 'ok' : post.post_status === 'future' ? 'brand' : 'neutral'}>{post.post_status}</Pill>
                </td>
                <td className="border-b border-line px-[18px] py-[13px]">
                  <span className="rounded-md border border-line bg-surface-soft px-2 py-0.5 text-[11px] font-semibold text-ink-body">{post.post_type}</span>
                </td>
                <td className="whitespace-nowrap border-b border-line px-[18px] py-[13px] text-ink-muted">{formatDate(post.post_date)}</td>
                <td className="whitespace-nowrap border-b border-line px-[18px] py-[13px] text-ink-muted">{formatDate(post.post_modified)}</td>
              </tr>
            ))}
          </TableShell>
        )}
      </div>

      <Pagination page={page} meta={meta} noun="posts" onChange={setPage} />
    </div>
  );
}

/* ============================== WORDPRESS ================================== */
function WordPressTab({ site }: { site: SiteDetail }) {
  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <InfoCard icon={<IconWp />} tone="indigo" title="Core & runtime">
        <KV k="WordPress version" v={site.wp_version} mono />
        <KV k="PHP version" v={site.php_version} mono />
        <KV k="Multisite" v={site.is_multisite ? 'Yes' : 'No'} />
        <KV k="Connector version" v={site.plugin_version} mono />
      </InfoCard>
      <InfoCard icon={<IconPlug />} tone="brand" title="Addresses">
        <KV k="Home URL" v={site.home_url} link mono />
        <KV k="Site URL" v={site.site_url} link mono />
      </InfoCard>
    </div>
  );
}

/* ============================== PLUGIN STATUS ================================== */
function PluginStatusTab({ site }: { site: SiteDetail }) {
  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <InfoCard icon={<IconPulse />} tone="ok" title="Connector health">
        <KV k="Connector version" v={site.plugin_version} mono />
        <KV k="Last heartbeat" v={timeAgo(site.last_heartbeat_at)} />
        <KV k="Connection status" v={<SiteStatusPill status={site.status as SiteStatus} />} />
        <KV k="Disconnected at" v={site.disconnected_at ? formatDate(site.disconnected_at) : '—'} />
      </InfoCard>
      <div className="card flex flex-col justify-center p-5">
        <div className="mb-2.5 flex items-center gap-[9px] font-disp text-[16px] font-semibold text-ink">
          <span className="grid h-[30px] w-[30px] place-items-center rounded-[9px] bg-brand-600/[0.12] text-brand-600"><IconClock /></span>
          Heartbeat cadence
        </div>
        <p className="text-[13px] text-ink-body">
          {site.last_heartbeat_at ? (
            <>The last signal arrived <b className="font-semibold text-ink">{timeAgo(site.last_heartbeat_at)}</b>
            {site.origin_ip_verified ? ' and the origin is verified, so the site is reporting normally.' : '.'}</>
          ) : (
            'This site has not reported a heartbeat yet.'
          )}
        </p>
        <div className="mt-3.5 rounded-[13px] border border-dashed border-line-strong bg-surface-soft px-4 py-[13px] text-[12.5px] text-ink-muted">
          Detailed connector health checks — module status and scheduled tasks — arrive with the release registry.
        </div>
      </div>
    </div>
  );
}

/* ============================== NETWORK ================================== */
function NetworkTab({ site }: { site: SiteDetail }) {
  const [verifyOriginIp, setVerifyOriginIp] = useState('');
  const [verifyNotes, setVerifyNotes] = useState('');
  const [isVerifying, setIsVerifying] = useState(false);
  const [verifyError, setVerifyError] = useState('');

  const handleVerify = async () => {
    if (!verifyOriginIp) {
      setVerifyError('Please enter an origin IP address');
      return;
    }
    setIsVerifying(true);
    setVerifyError('');
    try {
      await api.post(`/api/dashboard/sites/${site.uuid}/origin/verify`, { origin_ip: verifyOriginIp, notes: verifyNotes });
      window.location.reload();
    } catch (err: any) {
      setVerifyError(err?.response?.data?.error || 'Verification failed');
    } finally {
      setIsVerifying(false);
    }
  };

  const confidencePill = (c: string | null) => {
    if (!c) return '—';
    const tone = c === 'high' ? 'ok' : c === 'medium' ? 'warn' : c === 'low' ? 'bad' : 'neutral';
    return <Pill tone={tone as any}>{c}</Pill>;
  };

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <InfoCard icon={<IconServer />} tone="sky" title="Server information">
          <KV k="Server IP" v={site.server_ip} mono />
          <KV k="Server hostname" v={site.server_hostname} mono />
          <KV k="Server software" v={site.server_software} />
        </InfoCard>
        <InfoCard icon={<IconSearch />} tone="brand" title="Origin IP detection">
          <KV k="Origin IP" v={site.origin_ip} mono />
          <KV k="Detection source" v={site.origin_ip_source} mono />
          <KV k="Confidence" v={confidencePill(site.origin_ip_confidence)} />
          <KV k="Verified" v={site.origin_ip ? <VerifiedPill verified={site.origin_ip_verified} /> : '—'} />
          {site.origin_ip_verified && <KV k="Verified at" v={formatDate(site.origin_ip_verified_at)} />}
        </InfoCard>
      </div>

      {site.origin_ip_confidence === 'low' && site.origin_ip && (
        <div className="rounded-[13px] border border-dashed border-line-strong bg-surface-soft px-4 py-[13px] text-[12.5px] text-ink-muted">
          Confidence is <b className="font-semibold text-ink-body">low</b> because the origin was resolved from a DNS A record alone. Add a signed header check from the connector to raise confidence to high.
        </div>
      )}

      {/* Manual verification — preserved core functionality */}
      {!site.origin_ip_verified && (
        <div className="card p-6">
          <h4 className="mb-2 text-sm font-semibold text-ink">Manually verify origin IP</h4>
          <p className="mb-4 text-sm text-ink-muted">
            If you know the correct origin IP (e.g. from the hosting panel or DNS analysis), you can verify it here.
          </p>
          <div className="space-y-3">
            <div>
              <label htmlFor="verify-ip" className="label">Origin IP address</label>
              <input id="verify-ip" type="text" value={verifyOriginIp} onChange={(e) => setVerifyOriginIp(e.target.value)} placeholder={site.origin_ip || '123.45.67.89'} className="input mt-1" disabled={isVerifying} />
            </div>
            <div>
              <label htmlFor="verify-notes" className="label">Notes (optional)</label>
              <textarea id="verify-notes" value={verifyNotes} onChange={(e) => setVerifyNotes(e.target.value)} placeholder="Source: hosting panel, confirmed via dig, etc." rows={2} className="input mt-1" disabled={isVerifying} />
            </div>
            {verifyError && <div className="rounded-md bg-danger/10 p-3 text-sm text-danger">{verifyError}</div>}
            <button onClick={handleVerify} disabled={isVerifying} className="btn-primary">{isVerifying ? 'Verifying…' : 'Verify origin IP'}</button>
          </div>
        </div>
      )}
    </div>
  );
}

/* ============================== CONNECTION HISTORY ================================== */
function ConnectionHistoryTab({ uuid }: { uuid: string }) {
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['heartbeats', uuid],
    queryFn: async () => (await api.get<{ data: Heartbeat[] }>(`/api/dashboard/sites/${uuid}/heartbeats`)).data.data,
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message={(error as Error)?.message ?? 'Could not load history.'} onRetry={refetch} />;
  if (!data || data.length === 0) return <EmptyState title="No heartbeats yet" description="This site has not reported in." />;

  return (
    <div className="card">
      <ChartHead title="Heartbeat log" right={<Pill tone="neutral">Every 5 min</Pill>} />
      <TableShell head={['Received', 'WP', 'PHP', 'Connector', 'Server IP', 'Origin candidate']}>
        {data.map((hb, i) => (
          <tr key={i} className="transition hover:bg-surface-soft">
            <td className="whitespace-nowrap border-b border-line px-[18px] py-[13px] font-semibold text-ink">{formatDate(hb.received_at)}</td>
            <td className="border-b border-line px-[18px] py-[13px] font-mono text-[12.5px] text-ink-body">{hb.wp_version ?? '—'}</td>
            <td className="border-b border-line px-[18px] py-[13px] font-mono text-[12.5px] text-ink-body">{hb.php_version ?? '—'}</td>
            <td className="border-b border-line px-[18px] py-[13px] font-mono text-[12.5px] text-ink-body">{hb.plugin_version ?? '—'}</td>
            <td className="whitespace-nowrap border-b border-line px-[18px] py-[13px] font-mono text-[12px] text-ink-muted">{hb.server_ip ?? '—'}</td>
            <td className="whitespace-nowrap border-b border-line px-[18px] py-[13px] font-mono text-[12px] text-ink-muted">{hb.origin_ip_candidate ?? '—'}</td>
          </tr>
        ))}
      </TableShell>
    </div>
  );
}

/* ============================== UPDATES ================================== */
// Every non-terminal state the update command can be in. Kept in sync with the
// API (Site::UPDATE_CMD_IN_FLIGHT). Used as a fallback when the payload does not
// carry the authoritative `in_flight` boolean (older API responses).
const UPDATE_IN_FLIGHT_STATUSES = [
  'pending', 'queued', 'dispatched', 'starting',
  'downloading', 'installing', 'in_progress', 'verifying',
];
function commandInFlight(cmdStatus: string | null | undefined): boolean {
  return !!cmdStatus && UPDATE_IN_FLIGHT_STATUSES.includes(cmdStatus);
}
// Ordered lifecycle for the granular progress stepper. Push-delivered commands
// walk queued→starting→downloading→installing→verifying→completed; the pull
// (heartbeat) path may surface `dispatched`/`in_progress` from older connectors,
// which we fold onto the nearest step below.
const UPDATE_PROGRESS_STEPS: { key: string; label: string }[] = [
  { key: 'queued', label: 'Queued' },
  { key: 'starting', label: 'Starting' },
  { key: 'downloading', label: 'Downloading' },
  { key: 'installing', label: 'Installing' },
  { key: 'verifying', label: 'Verifying' },
  { key: 'completed', label: 'Completed' },
];
// Map any command status onto its index in UPDATE_PROGRESS_STEPS.
function progressIndexForStatus(cmdStatus: string | null | undefined): number {
  switch (cmdStatus) {
    case 'pending':
    case 'queued':
    case 'dispatched': return 0;
    case 'starting': return 1;
    case 'downloading': return 2;
    case 'installing':
    case 'in_progress': return 3;
    case 'verifying': return 4;
    case 'completed': return 5;
    default: return -1; // failed / rolled_back / null → no active step
  }
}

function UpdateProgressStepper({ status }: { status: string | null | undefined }) {
  const active = progressIndexForStatus(status);
  if (active < 0) return null;
  return (
    <div className="mt-3 flex items-center gap-1.5" aria-label="Update progress">
      {UPDATE_PROGRESS_STEPS.map((step, i) => {
        const done = i < active;
        const current = i === active;
        return (
          <div key={step.key} className="flex flex-1 flex-col items-center gap-1">
            <div className="flex w-full items-center">
              <div
                className={clsx(
                  'h-1.5 flex-1 rounded-full transition-colors',
                  done ? 'bg-success' : current ? 'bg-brand' : 'bg-line',
                )}
              />
            </div>
            <span className={clsx('text-[10.5px] font-medium', done ? 'text-success' : current ? 'text-brand' : 'text-ink-muted')}>
              {step.label}
            </span>
          </div>
        );
      })}
    </div>
  );
}

function UpSummaryCard({ tone, icon, n, label }: { tone: IconTone; icon: ReactNode; n: ReactNode; label: string }) {
  return (
    <div className="card flex items-center gap-[13px] p-4">
      <div className={clsx('grid h-[42px] w-[42px] flex-shrink-0 place-items-center rounded-xl', ICON_TONE[tone])}>{icon}</div>
      <div>
        <div className="font-disp text-[26px] font-bold leading-none text-ink">{n}</div>
        <div className="mt-[3px] text-xs font-medium text-ink-muted">{label}</div>
      </div>
    </div>
  );
}

function UpdatesTab({ site }: { site: SiteDetail }) {
  const [requesting, setRequesting] = useState(false);
  const [actionError, setActionError] = useState('');

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['site-update-status', site.uuid],
    queryFn: async () => (await api.get<{ data: SiteUpdateStatus }>(`/api/dashboard/sites/${site.uuid}/update-status`)).data.data,
    // Poll faster (5s) while a command is actively running so the granular
    // push states (starting→downloading→installing→verifying) surface promptly.
    // Prefer the authoritative `in_flight` flag; fall back to the status set for
    // older API responses that don't carry it.
    refetchInterval: (query) => {
      const cmd = query.state.data?.command;
      const active = cmd?.in_flight ?? commandInFlight(cmd?.status);
      return active ? 5000 : false;
    },
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message={(error as Error)?.message ?? 'Could not load update status.'} onRetry={refetch} />;

  const status = data!;
  const command = status.command;

  if (!status.has_active_release) {
    return (
      <div className="card p-6">
        <EmptyState
          title="No active release published"
          description={`This site is running connector ${status.current_version ?? 'unknown'}. Publish and activate a release under Plugin Releases to start tracking updates here.`}
          action={<Link to="/plugin-releases" className="btn-secondary">Go to Plugin Releases</Link>}
        />
      </div>
    );
  }

  const inFlight = command.in_flight ?? commandInFlight(command.status);
  const totalUpdates =
    (status.core_update_available ? 1 : 0) + (status.plugin_updates_count ?? 0) + (status.theme_updates_count ?? 0);

  const requestUpdate = async (type: 'plugin' | 'core' | 'plugins' | 'themes' = 'plugin') => {
    setRequesting(true);
    setActionError('');
    try {
      await api.post(`/api/dashboard/sites/${site.uuid}/request-update`, type === 'plugin' ? {} : { type });
      await refetch();
    } catch (err: any) {
      setActionError(err?.response?.data?.message || err?.response?.data?.error || 'Could not request the update. Please try again.');
    } finally {
      setRequesting(false);
    }
  };

  const commandTone: Record<string, 'ok' | 'warn' | 'bad' | 'brand' | 'neutral'> = {
    pending: 'warn', queued: 'warn', dispatched: 'warn',
    starting: 'brand', downloading: 'brand', installing: 'brand', in_progress: 'brand', verifying: 'brand',
    completed: 'ok', failed: 'bad', rolled_back: 'bad',
  };
  const cmdKind = command.type === 'core' ? 'WordPress core' : command.type === 'plugins' ? 'Plugin' : command.type === 'themes' ? 'Theme' : 'Connector';
  const commandLabel: Record<string, string> = {
    pending: `${cmdKind} update queued`,
    queued: `${cmdKind} update accepted`,
    dispatched: 'Delivered to site',
    starting: 'Starting…',
    downloading: 'Downloading…',
    installing: 'Installing…',
    in_progress: 'Updating…',
    verifying: 'Verifying…',
    completed: `${cmdKind} update completed`,
    failed: `${cmdKind} update failed`,
    rolled_back: `${cmdKind} update rolled back`,
  };

  return (
    <div className="space-y-[18px]">
      {/* Summary tiles — real update inventory */}
      <div className="grid grid-cols-2 gap-3.5 lg:grid-cols-4">
        <UpSummaryCard tone="indigo" icon={<IconWp />} n={status.core_update_available ? 1 : 0} label="Core update" />
        <UpSummaryCard tone="sky" icon={<IconPlug />} n={status.plugin_updates_count ?? 0} label="Plugin updates" />
        <UpSummaryCard tone="brand" icon={<IconCode />} n={status.theme_updates_count ?? 0} label="Theme updates" />
        <UpSummaryCard
          tone="ok"
          icon={<IconPulse />}
          n={status.update_available ? <span className="text-warning-text">!</span> : <svg {...S} strokeWidth={2.5} className="h-[22px] w-[22px] text-success"><path d="m5 12 5 5L20 6" /></svg>}
          label="Pulse Connector"
        />
      </div>

      {/* Action bar — status + one-click maintenance actions */}
      <div className="card p-[16px_20px]">
        <div>
          {totalUpdates > 0 ? (
            <Pill tone="warn" dot>{totalUpdates} update{totalUpdates === 1 ? '' : 's'} available</Pill>
          ) : (
            <Pill tone="ok" dot>Everything up to date</Pill>
          )}
          <div className="mt-1.5 text-[12.5px] text-ink-muted">
            {totalUpdates > 0
              ? 'Update WordPress, plugins, and themes to keep your site current, secure, and running smoothly. Changes may take a few minutes to complete.'
              : 'There are no updates available right now.'}
          </div>
        </div>
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          <MaintenanceAction label="Update WordPress core" enabled={status.can_update_core} busy={requesting} inFlight={inFlight} supported={status.maintenance_update_supported} unsupportedText="Remote core updates require connector v1.2.3 or newer on this site." upToDateText="WordPress is up to date" onClick={() => requestUpdate('core')} />
          <MaintenanceAction label="Update all plugins" enabled={status.can_update_plugins} busy={requesting} inFlight={inFlight} supported={status.maintenance_update_supported} unsupportedText="Remote plugin updates require connector v1.2.3 or newer on this site." upToDateText="All plugins are up to date" onClick={() => requestUpdate('plugins')} />
          <MaintenanceAction label="Update all themes" enabled={status.can_update_themes} busy={requesting} inFlight={inFlight} supported={status.themes_update_supported} unsupportedText="Remote theme updates require connector v1.2.4 or newer on this site." upToDateText="All themes are up to date" onClick={() => requestUpdate('themes')} />
        </div>
      </div>

      {actionError && <p className="text-sm text-danger">{actionError}</p>}

      {/* Detailed per-item inventory (connector 1.2.8+). When an older connector
          last reported, update_items is null and we fall back to the summary
          tiles + bulk maintenance below. */}
      {status.update_items && <UpdateItemsSections items={status.update_items} />}

      {/* Connector self-update banner + release toggle */}
      <div className="card">
        <div className="flex items-center gap-2.5 border-b border-line px-[18px] py-4 font-disp text-[14px] font-semibold text-ink">
          <span className="grid h-7 w-7 place-items-center rounded-lg bg-success/[0.13] text-success"><IconPulse /></span>
          Pulse Connector
        </div>
        <div className={clsx('m-[16px_18px] flex items-center gap-3.5 rounded-[14px] p-[16px_20px]', status.update_available ? 'border border-warning/25 bg-warning/[0.09]' : 'border border-success/25 bg-success/[0.09]')}>
          <span className={clsx('grid h-10 w-10 flex-shrink-0 place-items-center rounded-xl', status.update_available ? 'bg-warning/15 text-warning-text' : 'bg-success/15 text-success')}>
            {status.update_available
              ? <svg {...S} strokeWidth={2} className="h-5 w-5"><path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" /></svg>
              : <svg {...S} strokeWidth={2} className="h-5 w-5"><path d="m5 12 5 5L20 6" /></svg>}
          </span>
          <div>
            <div className="text-sm font-semibold text-ink">{status.update_available ? 'A newer connector is available' : 'Connector is up to date'}</div>
            <div className="mt-px text-[12.5px] text-ink-muted">
              Running <span className="font-mono text-ink-body">{status.current_version ?? 'unknown'}</span>
              {status.latest_version && <> · latest <span className="font-mono text-ink-body">{status.latest_version}</span></>}
            </div>
          </div>
          {status.update_available && (
            <button
              type="button"
              className="btn-primary btn-sm ml-auto"
              onClick={() => requestUpdate('plugin')}
              disabled={requesting || inFlight || !status.remote_update_supported}
              title={!status.remote_update_supported ? 'Remote update requires connector v1.2.2 or newer on this site.' : undefined}
            >
              {requesting ? 'Requesting…' : inFlight ? 'In progress…' : 'Update connector'}
            </button>
          )}
        </div>

        {!status.remote_update_supported && status.update_available && (
          <p className="px-[18px] pb-3 text-xs text-warning-text">
            This site's connector is older than v1.2.2 and doesn't support remote one-click updates yet. Update it once via the WordPress admin plugin updater; after that, all future updates can be pushed from here.
          </p>
        )}

        {/* Live command status */}
        {command.status && (
          <div className="m-[0_18px_18px] rounded-[13px] border border-line bg-surface-soft p-4">
            <div className="flex items-center gap-2">
              <Pill tone={commandTone[command.status] ?? 'neutral'}>{commandLabel[command.status] ?? command.status}</Pill>
              {command.target_version && <span className="text-sm text-ink-body">→ <span className="font-mono font-semibold">{command.target_version}</span></span>}
            </div>
            {command.message && <p className="mt-2 text-sm text-ink-body">{command.message}</p>}
            {/* Granular progress stepper for in-flight commands */}
            {inFlight && <UpdateProgressStepper status={command.status} />}
            <div className="mt-2 space-y-0.5 text-xs text-ink-muted">
              {command.requested_at && <p>Requested {timeAgo(command.requested_at)}</p>}
              {command.dispatched_at && <p>Delivered to site {timeAgo(command.dispatched_at)}</p>}
              {command.completed_at && <p>Finished {timeAgo(command.completed_at)}</p>}
            </div>
            {inFlight && (
              <p className="mt-2 text-xs text-ink-muted">
                {command.status === 'pending'
                  ? "This site's connector is older, so the command is delivered on its next heartbeat and can take a few minutes. This view refreshes automatically."
                  : 'The update is running on the site now and this view refreshes automatically. It can take a few minutes.'}
              </p>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

/* Version transition: current → new (highlighted) when an update is pending,
   otherwise the plain current version. */
function VersionDelta({ current, next }: { current: string | null; next: string | null }) {
  if (!current && !next) return <span className="text-ink-muted">—</span>;
  if (next) {
    return (
      <span className="inline-flex items-center gap-1.5 font-mono text-[12.5px]">
        <span className="text-ink-muted line-through">{current ?? '?'}</span>
        <svg {...S} strokeWidth={2} className="h-3.5 w-3.5 text-ink-muted"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
        <span className="font-semibold text-warning-text">{next}</span>
      </span>
    );
  }
  return <span className="font-mono text-[12.5px] text-ink-body">{current}</span>;
}

/* One row inside a Plugins / Themes list. */
function ItemRow({
  name, current, next, active,
}: { name: string; current: string | null; next: string | null; active?: boolean }) {
  const pending = !!next;
  return (
    <div className="flex items-center justify-between gap-3 border-t border-line px-[18px] py-3 first:border-t-0">
      <div className="min-w-0">
        <div className="flex items-center gap-2">
          <span className="truncate text-sm font-medium text-ink">{name}</span>
          {active && <Pill tone="brand">Active</Pill>}
        </div>
        <div className="mt-0.5"><VersionDelta current={current} next={next} /></div>
      </div>
      {pending ? <Pill tone="warn" dot>Update available</Pill> : <Pill tone="ok" dot>Up to date</Pill>}
    </div>
  );
}

/* Detailed WordPress core / Plugins / Themes inventory sections. Purely
   informational — the actionable bulk buttons live in "WordPress maintenance".
   Every value comes from the connector's reported inventory (no fabrication). */
function UpdateItemsSections({ items }: { items: SiteUpdateItems }) {
  const core = items.core;
  const corePending = !!core?.new;
  const plugins = items.plugins ?? [];
  const themes = items.themes ?? [];
  const pluginsPending = plugins.filter((p) => !!p.new).length;
  const themesPending = themes.filter((t) => !!t.new).length;

  return (
    <div className="space-y-[18px]">
      {/* WordPress core */}
      {core && (
        <div className="card">
          <div className="flex items-center justify-between gap-2.5 border-b border-line px-[18px] py-4">
            <span className="flex items-center gap-2.5 font-disp text-[14px] font-semibold text-ink">
              <span className="grid h-7 w-7 place-items-center rounded-lg bg-brand-500/[0.13] text-brand-600"><IconWp /></span>
              WordPress core
            </span>
            {corePending ? <Pill tone="warn" dot>Update available</Pill> : <Pill tone="ok" dot>Up to date</Pill>}
          </div>
          <div className="flex items-center justify-between gap-3 px-[18px] py-3.5">
            <span className="text-sm text-ink-body">{corePending ? 'A new WordPress version is available' : 'Running the latest WordPress version'}</span>
            <VersionDelta current={core.current} next={core.new} />
          </div>
        </div>
      )}

      {/* Plugins */}
      <div className="card">
        <div className="flex items-center justify-between gap-2.5 border-b border-line px-[18px] py-4">
          <span className="flex items-center gap-2.5 font-disp text-[14px] font-semibold text-ink">
            <span className="grid h-7 w-7 place-items-center rounded-lg bg-sky-500/[0.13] text-sky-600"><IconPlug /></span>
            Plugins
          </span>
          <span className="text-[12.5px] font-medium text-ink-muted">
            {plugins.length === 0 ? 'No plugins reported' : pluginsPending > 0 ? `${pluginsPending} of ${plugins.length} need updating` : `All ${plugins.length} up to date`}
          </span>
        </div>
        {plugins.length === 0
          ? <div className="px-[18px] py-4 text-sm text-ink-muted">No plugin inventory reported.</div>
          : plugins.map((p) => <ItemRow key={p.slug ?? p.name} name={p.name} current={p.current} next={p.new} />)}
      </div>

      {/* Themes */}
      <div className="card">
        <div className="flex items-center justify-between gap-2.5 border-b border-line px-[18px] py-4">
          <span className="flex items-center gap-2.5 font-disp text-[14px] font-semibold text-ink">
            <span className="grid h-7 w-7 place-items-center rounded-lg bg-brand-500/[0.13] text-brand-600"><IconCode /></span>
            Themes
          </span>
          <span className="text-[12.5px] font-medium text-ink-muted">
            {themes.length === 0 ? 'No themes reported' : themesPending > 0 ? `${themesPending} of ${themes.length} need updating` : `All ${themes.length} up to date`}
          </span>
        </div>
        {themes.length === 0
          ? <div className="px-[18px] py-4 text-sm text-ink-muted">No theme inventory reported.</div>
          : themes.map((t) => <ItemRow key={t.stylesheet ?? t.name} name={t.name} current={t.current} next={t.new} active={t.active} />)}
      </div>
    </div>
  );
}

function MaintenanceAction({
  label, enabled, busy, inFlight, supported, unsupportedText, upToDateText, onClick,
}: {
  label: string; enabled: boolean; busy: boolean; inFlight: boolean; supported: boolean; unsupportedText: string; upToDateText: string; onClick: () => void;
}) {
  const helper = !supported ? unsupportedText : inFlight ? 'An update is already in progress.' : !enabled ? upToDateText : null;
  return (
    <div className="flex flex-col gap-1.5">
      <button type="button" className="btn-secondary w-full justify-center" onClick={onClick} disabled={!enabled || busy}>{label}</button>
      {helper && <p className={clsx('text-xs', !supported ? 'text-warning-text' : 'text-ink-muted')}>{helper}</p>}
    </div>
  );
}

/* ============================== ACTIVITY ================================== */
function ActivityTab({ uuid }: { uuid: string }) {
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['audit', 'site', uuid],
    queryFn: async () => (await api.get<Paginated<AuditLog>>(`/api/dashboard/audit-logs?subject_uuid=${uuid}`)).data,
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message={(error as Error)?.message ?? 'Could not load activity.'} onRetry={refetch} />;
  if (!data || data.data.length === 0) return <EmptyState title="No activity recorded" description="Audit events for this site will appear here." />;

  return (
    <div className="card">
      <ChartHead title="Activity log" />
      <div className="px-[22px] py-4">
        <div className="relative pl-[30px] before:absolute before:bottom-2.5 before:left-[6px] before:top-1.5 before:w-0.5 before:bg-line">
          {data.data.map((log, i) => {
            const tone = /verif/i.test(log.event) ? 'ok' : /enroll|creat/i.test(log.event) ? 'brand' : 'neutral';
            return (
              <div key={log.uuid ?? i} className="relative pb-5 last:pb-1.5">
                <span
                  className={clsx(
                    'absolute -left-[30px] top-0.5 h-3.5 w-3.5 rounded-full border-2',
                    tone === 'ok' ? 'border-transparent bg-success shadow-[0_0_0_4px_rgba(16,185,129,0.18)]'
                      : tone === 'brand' ? 'border-transparent bg-brand-600 shadow-[0_0_0_4px_rgba(59,91,255,0.18)]'
                        : 'border-line-strong bg-surface',
                  )}
                />
                <div className="flex flex-wrap justify-between gap-3.5">
                  <div>
                    <div className="text-sm font-semibold text-ink">{humanizeEvent(log.event)}</div>
                    <div className="mt-0.5 text-xs text-ink-muted">{log.actor?.name ?? log.actor_type ?? 'system'} · {log.ip_address ?? 'no IP'}</div>
                  </div>
                  <div className="whitespace-nowrap text-xs text-ink-muted">{formatDate(log.created_at)}</div>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

/* ============================== shared pagination ================================== */
function Pagination({ page, meta, noun, onChange }: { page: number; meta: Paginated<unknown>['meta']; noun: string; onChange: (p: number) => void }) {
  if (meta.last_page <= 1) return null;
  return (
    <div className="flex items-center justify-between">
      <p className="text-sm text-ink-body">Showing {meta.from || 0} to {meta.to || 0} of {meta.total} {noun}</p>
      <div className="flex gap-2">
        <button onClick={() => onChange(Math.max(1, page - 1))} disabled={page === 1} className="rounded-[9px] border border-line-strong bg-surface px-3 py-1 text-sm text-ink-body hover:border-brand-600 disabled:opacity-50">Previous</button>
        <span className="px-3 py-1 text-sm text-ink-body">Page {page} of {meta.last_page}</span>
        <button onClick={() => onChange(Math.min(meta.last_page, page + 1))} disabled={page === meta.last_page} className="rounded-[9px] border border-line-strong bg-surface px-3 py-1 text-sm text-ink-body hover:border-brand-600 disabled:opacity-50">Next</button>
      </div>
    </div>
  );
}
