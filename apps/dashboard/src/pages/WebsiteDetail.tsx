import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import clsx from 'clsx';
import { api } from '@/lib/api';
import type { AuditLog, Heartbeat, Paginated, SiteDetail, SitePost, SiteStatus, SiteUser } from '@/types';
import { Badge, EmptyState, ErrorState, LoadingState, StatusBadge, VerifiedPill } from '@/components/ui';
import { formatDate, humanizeEvent, timeAgo } from '@/lib/format';

const TABS = ['Overview', 'Network', 'WordPress', 'Connection History', 'Plugin Status', 'Users & Logins', 'Content', 'Updates', 'Activity'] as const;
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
      {tab === 'Users & Logins' && <UsersTab uuid={uuid} />}
      {tab === 'Content' && <ContentTab uuid={uuid} />}
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
      await api.post(`/api/dashboard/sites/${site.uuid}/origin/verify`, {
        origin_ip: verifyOriginIp,
        notes: verifyNotes,
      });
      
      // Refresh the page to show updated data
      window.location.reload();
    } catch (err: any) {
      setVerifyError(err?.response?.data?.error || 'Verification failed');
    } finally {
      setIsVerifying(false);
    }
  };

  const getConfidenceBadge = (confidence: string | null) => {
    if (!confidence) return null;
    const toneMap: Record<string, 'success' | 'warning' | 'danger' | 'brand'> = {
      high: 'success',
      medium: 'warning',
      low: 'danger',
      unknown: 'brand',
    };
    return <Badge tone={toneMap[confidence] || 'brand'}>{confidence}</Badge>;
  };

  return (
    <div className="space-y-6">
      {/* Server Info */}
      <div className="card p-6">
        <h3 className="mb-4 text-lg font-semibold text-slate-800">Server Information</h3>
        <dl>
          <Row label="Server IP" value={site.server_ip} mono />
          <Row label="Server hostname" value={site.server_hostname} mono />
          <Row label="Server software" value={site.server_software} />
        </dl>
      </div>

      {/* Origin IP Info */}
      <div className="card p-6">
        <h3 className="mb-4 text-lg font-semibold text-slate-800">Origin IP Detection</h3>
        <dl>
          <Row label="Origin IP" value={site.origin_ip} mono />
          <Row label="Detection source" value={site.origin_ip_source} />
          <Row label="Confidence" value={getConfidenceBadge(site.origin_ip_confidence)} />
          <Row label="Verified" value={site.origin_ip ? <VerifiedPill verified={site.origin_ip_verified} /> : '—'} />
          {site.origin_ip_verified && (
            <Row label="Verified at" value={formatDate(site.origin_ip_verified_at)} />
          )}
        </dl>

        {/* Manual Verification Form */}
        {!site.origin_ip_verified && (
          <div className="mt-6 border-t border-slate-100 pt-6">
            <h4 className="mb-3 text-sm font-semibold text-slate-700">Manually Verify Origin IP</h4>
            <p className="mb-4 text-sm text-slate-500">
              If you know the correct origin IP (e.g., from hosting panel or DNS analysis), you can verify it here.
            </p>
            <div className="space-y-3">
              <div>
                <label htmlFor="verify-ip" className="block text-sm font-medium text-slate-700">
                  Origin IP Address
                </label>
                <input
                  id="verify-ip"
                  type="text"
                  value={verifyOriginIp}
                  onChange={(e) => setVerifyOriginIp(e.target.value)}
                  placeholder={site.origin_ip || '123.456.789.0'}
                  className="input mt-1"
                  disabled={isVerifying}
                />
              </div>
              <div>
                <label htmlFor="verify-notes" className="block text-sm font-medium text-slate-700">
                  Notes (optional)
                </label>
                <textarea
                  id="verify-notes"
                  value={verifyNotes}
                  onChange={(e) => setVerifyNotes(e.target.value)}
                  placeholder="Source: hosting panel, confirmed via dig, etc."
                  rows={2}
                  className="input mt-1"
                  disabled={isVerifying}
                />
              </div>
              {verifyError && (
                <div className="rounded-md bg-red-50 p-3 text-sm text-red-700">
                  {verifyError}
                </div>
              )}
              <button
                onClick={handleVerify}
                disabled={isVerifying}
                className="btn-primary"
              >
                {isVerifying ? 'Verifying...' : 'Verify Origin IP'}
              </button>
            </div>
          </div>
        )}
      </div>
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
  
  // Find most recent login across all users
  const mostRecentLogin = users.reduce((latest: SiteUser | null, user) => {
    if (!user.last_login_at) return latest;
    if (!latest || !latest.last_login_at) return user;
    return new Date(user.last_login_at) > new Date(latest.last_login_at) ? user : latest;
  }, null);

  return (
    <div className="space-y-6">
      {/* Summary Card */}
      <div className="card p-6">
        <h3 className="mb-4 text-lg font-semibold text-slate-900">User Summary</h3>
        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <dt className="text-sm text-slate-500">Total Users</dt>
            <dd className="mt-1 text-2xl font-semibold text-slate-900">{meta.total}</dd>
          </div>
          {mostRecentLogin && (
            <div>
              <dt className="text-sm text-slate-500">Most Recent Login</dt>
              <dd className="mt-1 text-sm text-slate-900">
                <div className="font-medium">{mostRecentLogin.display_name || mostRecentLogin.user_login}</div>
                <div className="text-xs text-slate-500">
                  {timeAgo(mostRecentLogin.last_login_at)} · {mostRecentLogin.last_login_ip || 'no IP'}
                </div>
              </dd>
            </div>
          )}
        </dl>
      </div>

      {/* Users Table */}
      <div className="card overflow-x-auto">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr>
              {['User', 'Email', 'Roles', 'Registered', 'Last Login', 'Login IP'].map((h) => (
                <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {users.map((user, i) => (
              <tr key={i} className="hover:bg-slate-50">
                <td className="px-4 py-3">
                  <div className="font-medium text-slate-900">{user.display_name || '—'}</div>
                  <div className="text-xs text-slate-500">{user.user_login}</div>
                </td>
                <td className="px-4 py-3 text-slate-600">{user.user_email || '—'}</td>
                <td className="px-4 py-3">
                  {user.roles && user.roles.length > 0 ? (
                    <div className="flex flex-wrap gap-1">
                      {user.roles.map((role, idx) => (
                        <Badge key={idx} tone="slate">{role}</Badge>
                      ))}
                    </div>
                  ) : '—'}
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-slate-600">{formatDate(user.user_registered)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-slate-600">
                  {user.last_login_at ? timeAgo(user.last_login_at) : '—'}
                </td>
                <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-600">{user.last_login_ip || '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-slate-600">
            Showing {meta.from || 0} to {meta.to || 0} of {meta.total} users
          </p>
          <div className="flex gap-2">
            <button
              onClick={() => setPage(p => Math.max(1, p - 1))}
              disabled={page === 1}
              className="rounded border border-slate-300 px-3 py-1 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            >
              Previous
            </button>
            <span className="px-3 py-1 text-sm text-slate-700">
              Page {page} of {meta.last_page}
            </span>
            <button
              onClick={() => setPage(p => Math.min(meta.last_page, p + 1))}
              disabled={page === meta.last_page}
              className="rounded border border-slate-300 px-3 py-1 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

function ContentTab({ uuid }: { uuid: string }) {
  const [page, setPage] = useState(1);
  const [statusFilter, setStatusFilter] = useState<'all' | 'publish' | 'future'>('all');
  
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['posts', uuid, page, statusFilter],
    queryFn: async () => {
      const params = new URLSearchParams({ per_page: '50', page: String(page) });
      if (statusFilter !== 'all') params.set('status', statusFilter);
      return (await api.get<Paginated<SitePost>>(`/api/dashboard/sites/${uuid}/posts?${params}`)).data;
    },
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message={(error as Error)?.message ?? 'Could not load content.'} onRetry={refetch} />;
  if (!data || data.data.length === 0) {
    return <EmptyState title="No content data yet" description="Post snapshots will appear here once the connector ships content data." />;
  }

  const posts = data.data;
  const meta = data.meta;

  const publishedCount = posts.filter(p => p.post_status === 'publish').length;
  const scheduledCount = posts.filter(p => p.post_status === 'future').length;

  return (
    <div className="space-y-6">
      {/* Summary Card */}
      <div className="card p-6">
        <h3 className="mb-4 text-lg font-semibold text-slate-900">Content Summary</h3>
        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <dt className="text-sm text-slate-500">Total Posts</dt>
            <dd className="mt-1 text-2xl font-semibold text-slate-900">{meta.total}</dd>
          </div>
          <div>
            <dt className="text-sm text-slate-500">Published</dt>
            <dd className="mt-1 text-2xl font-semibold text-green-600">{publishedCount}</dd>
          </div>
          <div>
            <dt className="text-sm text-slate-500">Scheduled</dt>
            <dd className="mt-1 text-2xl font-semibold text-brand-600">{scheduledCount}</dd>
          </div>
        </dl>
      </div>

      {/* Filters */}
      <div className="flex gap-2">
        {(['all', 'publish', 'future'] as const).map(status => (
          <button
            key={status}
            onClick={() => {
              setStatusFilter(status);
              setPage(1);
            }}
            className={clsx(
              'rounded px-3 py-1.5 text-sm font-medium transition',
              statusFilter === status
                ? 'bg-brand-600 text-white'
                : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
            )}
          >
            {status === 'all' ? 'All' : status === 'publish' ? 'Published' : 'Scheduled'}
          </button>
        ))}
      </div>

      {/* Posts Table */}
      <div className="card overflow-x-auto">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr>
              {['Title', 'Author', 'Status', 'Type', 'Date', 'Modified'].map((h) => (
                <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {posts.map((post, i) => (
              <tr key={i} className="hover:bg-slate-50">
                <td className="max-w-md px-4 py-3">
                  <div className="truncate font-medium text-slate-900">{post.post_title || '(no title)'}</div>
                  {post.guid && (
                    <a
                      href={post.guid}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="truncate text-xs text-brand-600 hover:underline"
                    >
                      View →
                    </a>
                  )}
                </td>
                <td className="px-4 py-3 text-slate-600">{post.post_author_name || `ID ${post.post_author_id}`}</td>
                <td className="px-4 py-3">
                  <Badge tone={post.post_status === 'publish' ? 'green' : post.post_status === 'future' ? 'brand' : 'slate'}>
                    {post.post_status}
                  </Badge>
                </td>
                <td className="px-4 py-3 text-slate-600">{post.post_type}</td>
                <td className="whitespace-nowrap px-4 py-3 text-slate-600">{formatDate(post.post_date)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-slate-600">{formatDate(post.post_modified)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-slate-600">
            Showing {meta.from || 0} to {meta.to || 0} of {meta.total} posts
          </p>
          <div className="flex gap-2">
            <button
              onClick={() => setPage(p => Math.max(1, p - 1))}
              disabled={page === 1}
              className="rounded border border-slate-300 px-3 py-1 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            >
              Previous
            </button>
            <span className="px-3 py-1 text-sm text-slate-700">
              Page {page} of {meta.last_page}
            </span>
            <button
              onClick={() => setPage(p => Math.min(meta.last_page, p + 1))}
              disabled={page === meta.last_page}
              className="rounded border border-slate-300 px-3 py-1 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            >
              Next
            </button>
          </div>
        </div>
      )}
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
