import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import { api } from '@/lib/api';
import type { AuditLog, Heartbeat, Paginated, SiteDetail, SiteStatus } from '@/types';
import { Badge, EmptyState, ErrorState, LoadingState, StatusBadge, VerifiedPill } from '@/components/ui';
import { formatDate, humanizeEvent, timeAgo } from '@/lib/format';

const TABS = ['Overview', 'Network', 'WordPress', 'Connection History', 'Plugin Status', 'Updates', 'Activity'] as const;
type Tab = (typeof TABS)[number];

function Row({ label, value, mono }: { label: string; value: React.ReactNode; mono?: boolean }) {
  return (
    <div className="flex flex-col gap-1 border-b border-slate-100 py-3 sm:flex-row sm:items-center sm:justify-between">
      <dt className="text-sm text-slate-500">{label}</dt>
      <dd className={clsx('text-sm text-slate-800', mono && 'font-mono text-xs')}>{value ?? '—'}</dd>
    </div>
  );
}

export default function WebsiteDetail() {
  const { uuid = '' } = useParams();
  const [tab, setTab] = useState<Tab>('Overview');

  const siteQuery = useQuery({
    queryKey: ['site', uuid],
    queryFn: async () => (await api.get<{ data: SiteDetail }>(`/api/dashboard/sites/${uuid}`)).data.data,
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

  return (
    <div>
      <div className="mb-6">
        <Link to="/websites" className="text-sm text-brand-700 hover:underline">
          ← Back to websites
        </Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold text-slate-900">{site.domain}</h1>
          <StatusBadge status={site.status as SiteStatus} />
          {site.is_multisite && <Badge tone="brand">Multisite</Badge>}
        </div>
        <p className="mt-1 text-sm text-slate-500">
          Last heartbeat {timeAgo(site.last_heartbeat_at)} · Enrolled {formatDate(site.enrolled_at)}
        </p>
      </div>

      {/* Tabs */}
      <div className="mb-6 border-b border-slate-200">
        <nav className="-mb-px flex flex-wrap gap-x-6 gap-y-2">
          {TABS.map((t) => (
            <button
              key={t}
              onClick={() => setTab(t)}
              className={clsx(
                'whitespace-nowrap border-b-2 px-1 py-2 text-sm font-medium transition',
                tab === t ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700',
              )}
            >
              {t}
            </button>
          ))}
        </nav>
      </div>

      {tab === 'Overview' && <OverviewTab site={site} />}
      {tab === 'Network' && <NetworkTab site={site} />}
      {tab === 'WordPress' && <WordPressTab site={site} />}
      {tab === 'Connection History' && <ConnectionHistoryTab uuid={uuid} />}
      {tab === 'Plugin Status' && <PluginStatusTab site={site} />}
      {tab === 'Updates' && <UpdatesTab site={site} />}
      {tab === 'Activity' && <ActivityTab uuid={uuid} />}
    </div>
  );
}

function OverviewTab({ site }: { site: SiteDetail }) {
  return (
    <div className="card p-6">
      <dl>
        <Row label="Domain" value={site.domain} />
        <Row label="Home URL" value={site.home_url} />
        <Row label="Site URL" value={site.site_url} />
        <Row label="Status" value={<StatusBadge status={site.status as SiteStatus} />} />
        <Row label="Origin IP" value={site.origin_ip} mono />
        <Row label="Origin verified" value={site.origin_ip ? <VerifiedPill verified={site.origin_ip_verified} /> : '—'} />
        <Row label="Server IP" value={site.server_ip} mono />
        <Row label="WordPress" value={site.wp_version} />
        <Row label="PHP" value={site.php_version} />
        <Row label="Connector" value={site.plugin_version} />
        <Row label="Last seen" value={timeAgo(site.last_seen_at)} />
      </dl>
    </div>
  );
}

function NetworkTab({ site }: { site: SiteDetail }) {
  return (
    <div className="card p-6">
      <dl>
        <Row label="Server IP" value={site.server_ip} mono />
        <Row label="Server hostname" value={site.server_hostname} mono />
        <Row label="Server software" value={site.server_software} />
        <Row label="Origin IP" value={site.origin_ip} mono />
        <Row label="Origin IP source" value={site.origin_ip_source} />
        <Row label="Origin IP confidence" value={site.origin_ip_confidence} />
        <Row label="Origin verified" value={site.origin_ip ? <VerifiedPill verified={site.origin_ip_verified} /> : '—'} />
        <Row label="Verified at" value={formatDate(site.origin_ip_verified_at)} />
        <Row label="Verified by" value={site.origin_ip_verified_by} />
      </dl>
    </div>
  );
}

function WordPressTab({ site }: { site: SiteDetail }) {
  return (
    <div className="card p-6">
      <dl>
        <Row label="WordPress version" value={site.wp_version} />
        <Row label="PHP version" value={site.php_version} />
        <Row label="Multisite" value={site.is_multisite ? 'Yes' : 'No'} />
        <Row label="Connector version" value={site.plugin_version} />
        <Row label="Home URL" value={site.home_url} />
        <Row label="Site URL" value={site.site_url} />
      </dl>
    </div>
  );
}

function ConnectionHistoryTab({ uuid }: { uuid: string }) {
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['heartbeats', uuid],
    queryFn: async () => (await api.get<{ data: Heartbeat[] }>(`/api/dashboard/sites/${uuid}/heartbeats`)).data.data,
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message={(error as Error)?.message ?? 'Could not load history.'} onRetry={refetch} />;
  if (!data || data.length === 0) return <EmptyState title="No heartbeats yet" description="This site has not reported in." />;

  return (
    <div className="card overflow-x-auto">
      <table className="min-w-full divide-y divide-slate-200 text-sm">
        <thead className="bg-slate-50">
          <tr>
            {['Received', 'WP', 'PHP', 'Connector', 'Server IP', 'Origin candidate'].map((h) => (
              <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {data.map((hb, i) => (
            <tr key={i} className="hover:bg-slate-50">
              <td className="whitespace-nowrap px-4 py-3 text-slate-700">{formatDate(hb.received_at)}</td>
              <td className="px-4 py-3 text-slate-600">{hb.wp_version ?? '—'}</td>
              <td className="px-4 py-3 text-slate-600">{hb.php_version ?? '—'}</td>
              <td className="px-4 py-3 text-slate-600">{hb.plugin_version ?? '—'}</td>
              <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-600">{hb.server_ip ?? '—'}</td>
              <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-600">{hb.origin_ip_candidate ?? '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function PluginStatusTab({ site }: { site: SiteDetail }) {
  return (
    <div className="card p-6">
      <dl>
        <Row label="Connector version" value={site.plugin_version} />
        <Row label="Last heartbeat" value={timeAgo(site.last_heartbeat_at)} />
        <Row label="Connection status" value={<StatusBadge status={site.status as SiteStatus} />} />
        <Row label="Disconnected at" value={formatDate(site.disconnected_at)} />
      </dl>
      <p className="mt-4 text-xs text-slate-400">
        Detailed connector health checks (module status, scheduled tasks) arrive with the release registry in Phase 7.
      </p>
    </div>
  );
}

function UpdatesTab({ site }: { site: SiteDetail }) {
  return (
    <div className="card p-6">
      <EmptyState
        title="Update tracking coming in Phase 7"
        description={`This site is running connector ${site.plugin_version ?? 'unknown'}. Once the release registry ships, available updates will be listed here.`}
      />
    </div>
  );
}

function ActivityTab({ uuid }: { uuid: string }) {
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['audit', 'site', uuid],
    queryFn: async () =>
      (await api.get<Paginated<AuditLog>>(`/api/dashboard/audit-logs?subject_uuid=${uuid}`)).data,
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message={(error as Error)?.message ?? 'Could not load activity.'} onRetry={refetch} />;
  if (!data || data.data.length === 0) return <EmptyState title="No activity recorded" description="Audit events for this site will appear here." />;

  return (
    <div className="card divide-y divide-slate-100">
      {data.data.map((log) => (
        <div key={log.uuid} className="flex items-start justify-between gap-4 px-5 py-3">
          <div>
            <p className="text-sm font-medium text-slate-800">{humanizeEvent(log.event)}</p>
            <p className="text-xs text-slate-500">
              {log.actor?.name ?? log.actor_type ?? 'system'} · {log.ip_address ?? 'no IP'}
            </p>
          </div>
          <span className="whitespace-nowrap text-xs text-slate-400">{formatDate(log.created_at)}</span>
        </div>
      ))}
    </div>
  );
}
