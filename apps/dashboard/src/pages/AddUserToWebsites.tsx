import { useMemo, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api, toApiError } from '@/lib/api';
import type { BulkUserResponse, BulkUserResult, Paginated, Site, WpRole } from '@/types';
import { EmptyState, ErrorState, LoadingState, Pill } from '@/components/ui';

/* -------------------------------------------------------------------------- */
/* Small local primitives                                                      */
/* -------------------------------------------------------------------------- */

const inputCls =
  'w-full rounded-lg border border-line bg-surface px-3 py-2 text-[13.5px] text-ink placeholder:text-ink-muted focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600';

/** Standard WordPress roles, used for the default-role selector. Per-site
 *  overrides fetch that site's actual roles (custom roles included). */
const STANDARD_ROLES: WpRole[] = [
  { slug: 'subscriber', name: 'Subscriber' },
  { slug: 'contributor', name: 'Contributor' },
  { slug: 'author', name: 'Author' },
  { slug: 'editor', name: 'Editor' },
  { slug: 'administrator', name: 'Administrator' },
];

function Field({
  label,
  children,
  hint,
  required,
}: {
  label: string;
  children: ReactNode;
  hint?: string;
  required?: boolean;
}) {
  return (
    <label className="block">
      <span className="mb-1 block text-[12.5px] font-medium text-ink-body">
        {label} {required && <span className="text-danger">*</span>}
      </span>
      {children}
      {hint && <span className="mt-1 block text-[11.5px] text-ink-muted">{hint}</span>}
    </label>
  );
}

function statusTone(status: BulkUserResult['status']): 'ok' | 'bad' | 'warn' {
  if (status === 'created') return 'ok';
  if (status === 'failed') return 'bad';
  return 'warn';
}

/* -------------------------------------------------------------------------- */
/* Page                                                                         */
/* -------------------------------------------------------------------------- */

interface SiteSelection {
  role: string; // '' means "use default role"
}

export default function AddUserToWebsites() {
  // ---- User detail form ---------------------------------------------------
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [defaultRole, setDefaultRole] = useState('subscriber');

  // ---- Site selection -----------------------------------------------------
  const [siteSearch, setSiteSearch] = useState('');
  const [selected, setSelected] = useState<Record<string, SiteSelection>>({});

  // ---- Submission state ---------------------------------------------------
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState('');
  const [results, setResults] = useState<BulkUserResult[] | null>(null);
  const [operationId, setOperationId] = useState<string | null>(null);

  const sitesQuery = useQuery({
    queryKey: ['bulk-sites'],
    queryFn: async () =>
      (await api.get<Paginated<Site>>('/api/dashboard/sites?per_page=200')).data,
  });

  const sites = sitesQuery.data?.data ?? [];

  const filteredSites = useMemo(() => {
    const q = siteSearch.trim().toLowerCase();
    if (!q) return sites;
    return sites.filter((s) => s.domain.toLowerCase().includes(q));
  }, [sites, siteSearch]);

  const selectedUuids = Object.keys(selected);
  const selectedCount = selectedUuids.length;

  const toggleSite = (uuid: string) => {
    setSelected((prev) => {
      const next = { ...prev };
      if (next[uuid]) delete next[uuid];
      else next[uuid] = { role: '' };
      return next;
    });
  };

  const setSiteRole = (uuid: string, role: string) => {
    setSelected((prev) => ({ ...prev, [uuid]: { role } }));
  };

  const allFilteredSelected =
    filteredSites.length > 0 && filteredSites.every((s) => selected[s.uuid]);

  const toggleSelectAll = () => {
    setSelected((prev) => {
      const next = { ...prev };
      if (allFilteredSelected) {
        filteredSites.forEach((s) => delete next[s.uuid]);
      } else {
        filteredSites.forEach((s) => {
          if (!next[s.uuid]) next[s.uuid] = { role: '' };
        });
      }
      return next;
    });
  };

  const validate = (): string | null => {
    if (!username.trim()) return 'Username is required.';
    if (!/^[a-zA-Z0-9._@-]{1,60}$/.test(username.trim()))
      return 'Username may only contain letters, numbers and . _ @ - characters.';
    if (!email.trim()) return 'Email is required.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) return 'Enter a valid email address.';
    if (password && password.length < 8) return 'Password must be at least 8 characters.';
    if (selectedCount === 0) return 'Select at least one website.';
    return null;
  };

  const buildSitesPayload = (uuids: string[]) =>
    uuids.map((uuid) => ({
      uuid,
      role: selected[uuid]?.role || undefined,
    }));

  const submit = async (opts?: { retryUuids?: string[]; opId?: string }) => {
    const err = validate();
    if (err) {
      setFormError(err);
      return;
    }
    setFormError('');
    setSubmitting(true);

    const targetUuids = opts?.retryUuids ?? selectedUuids;
    try {
      const payload: Record<string, unknown> = {
        username: username.trim(),
        email: email.trim(),
        first_name: firstName.trim(),
        last_name: lastName.trim(),
        display_name: displayName.trim(),
        default_role: defaultRole,
        sites: buildSitesPayload(targetUuids),
      };
      if (password) payload.password = password;
      if (opts?.opId) payload.operation_id = opts.opId;

      const res = (await api.post<BulkUserResponse>('/api/dashboard/wp-users/bulk-create', payload)).data;
      setOperationId(res.operation_id);

      if (opts?.retryUuids && results) {
        // Merge retried rows back into the existing result set.
        const byUuid = new Map(res.results.map((r) => [r.uuid, r]));
        setResults(results.map((r) => byUuid.get(r.uuid) ?? r));
      } else {
        setResults(res.results);
      }
    } catch (e) {
      setFormError(toApiError(e).message);
    } finally {
      setSubmitting(false);
    }
  };

  const failedRows = results?.filter((r) => r.status === 'failed') ?? [];

  const resetForm = () => {
    setResults(null);
    setOperationId(null);
    setFormError('');
  };

  /* ------------------------------------------------------------------ */
  const createdCount = results?.filter((r) => r.status === 'created').length ?? 0;
  const skippedCount = results?.filter((r) => r.status === 'skipped').length ?? 0;

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <div>
        <div className="flex items-center gap-2 text-[12.5px] text-ink-muted">
          <Link to="/websites" className="hover:text-brand-600">Websites</Link>
          <span>/</span>
          <span className="text-ink-body">Add User to Websites</span>
        </div>
        <h1 className="mt-1 text-[22px] font-semibold text-ink">Add a WordPress user to multiple websites</h1>
        <p className="mt-1 text-[13.5px] text-ink-muted">
          Enter the account details once, choose which websites to create it on, and (optionally) override
          the role per site. Failures can be retried without duplicating the accounts that already succeeded.
        </p>
      </div>

      {results ? (
        <ResultsPanel
          results={results}
          username={username}
          operationId={operationId}
          createdCount={createdCount}
          skippedCount={skippedCount}
          failedCount={failedRows.length}
          submitting={submitting}
          onRetryFailed={() =>
            submit({ retryUuids: failedRows.map((r) => r.uuid), opId: operationId ?? undefined })
          }
          onDone={resetForm}
        />
      ) : (
        <>
          {formError && (
            <div className="rounded-lg bg-danger/10 px-3.5 py-2.5 text-[13px] font-medium text-danger">
              {formError}
            </div>
          )}

          {/* ---- 1. User details --------------------------------------- */}
          <section className="card p-5">
            <h2 className="mb-4 text-[15px] font-semibold text-ink">1. User details</h2>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Username" required>
                <input className={inputCls} value={username} onChange={(e) => setUsername(e.target.value)} placeholder="jsmith" autoComplete="off" />
              </Field>
              <Field label="Email" required>
                <input className={inputCls} type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="john@example.com" autoComplete="off" />
              </Field>
              <Field label="First name">
                <input className={inputCls} value={firstName} onChange={(e) => setFirstName(e.target.value)} />
              </Field>
              <Field label="Last name">
                <input className={inputCls} value={lastName} onChange={(e) => setLastName(e.target.value)} />
              </Field>
              <Field label="Display name">
                <input className={inputCls} value={displayName} onChange={(e) => setDisplayName(e.target.value)} placeholder="John Smith" />
              </Field>
              <Field label="Password" hint="Leave blank to auto-generate a strong password on each site.">
                <input className={inputCls} type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password" />
              </Field>
              <Field label="Default role" required hint="Applied to every selected website unless overridden below.">
                <select className={inputCls} value={defaultRole} onChange={(e) => setDefaultRole(e.target.value)}>
                  {STANDARD_ROLES.map((r) => (
                    <option key={r.slug} value={r.slug}>{r.name}</option>
                  ))}
                </select>
              </Field>
            </div>
          </section>

          {/* ---- 2. Website selection ---------------------------------- */}
          <section className="card p-5">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
              <h2 className="text-[15px] font-semibold text-ink">
                2. Select websites {selectedCount > 0 && <span className="text-ink-muted">· {selectedCount} selected</span>}
              </h2>
              <input
                className={`${inputCls} sm:w-64`}
                value={siteSearch}
                onChange={(e) => setSiteSearch(e.target.value)}
                placeholder="Search websites…"
              />
            </div>

            {sitesQuery.isLoading ? (
              <LoadingState />
            ) : sitesQuery.isError ? (
              <ErrorState message="Could not load your websites." onRetry={sitesQuery.refetch} />
            ) : filteredSites.length === 0 ? (
              <EmptyState title="No websites found" description="Try a different search, or connect a website first." />
            ) : (
              <div className="overflow-hidden rounded-lg border border-line">
                <div className="flex items-center gap-3 border-b border-line bg-surface-soft px-4 py-2.5">
                  <input type="checkbox" checked={allFilteredSelected} onChange={toggleSelectAll} className="h-4 w-4 accent-brand-600" />
                  <span className="text-[12.5px] font-medium text-ink-body">Select all {siteSearch ? '(filtered)' : ''}</span>
                  <span className="ml-auto text-[11.5px] text-ink-muted">Per-site role override</span>
                </div>
                <div className="max-h-[360px] divide-y divide-line overflow-y-auto">
                  {filteredSites.map((site) => {
                    const sel = selected[site.uuid];
                    return (
                      <div key={site.uuid} className="flex items-center gap-3 px-4 py-2.5 hover:bg-surface-soft">
                        <input
                          type="checkbox"
                          checked={!!sel}
                          onChange={() => toggleSite(site.uuid)}
                          className="h-4 w-4 accent-brand-600"
                        />
                        <div className="min-w-0 flex-1">
                          <div className="truncate text-[13.5px] font-medium text-ink">{site.domain}</div>
                          <div className="text-[11.5px] text-ink-muted">
                            Connector {site.plugin_version ?? '—'}
                          </div>
                        </div>
                        {sel && (
                          <SiteRoleSelect
                            uuid={site.uuid}
                            value={sel.role}
                            defaultRole={defaultRole}
                            onChange={(role) => setSiteRole(site.uuid, role)}
                          />
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>
            )}
          </section>

          <div className="flex items-center justify-end gap-3">
            <span className="text-[12.5px] text-ink-muted">
              {selectedCount > 0 ? `Will create “${username || 'user'}” on ${selectedCount} website${selectedCount === 1 ? '' : 's'}.` : 'No websites selected.'}
            </span>
            <button
              onClick={() => submit()}
              disabled={submitting}
              className="rounded-lg bg-brand-gradient px-5 py-2.5 text-[13.5px] font-semibold text-white shadow-brand disabled:opacity-60"
            >
              {submitting ? 'Creating…' : 'Create user'}
            </button>
          </div>
        </>
      )}
    </div>
  );
}

/* -------------------------------------------------------------------------- */
/* Per-site role select — lazily loads that site's real roles (custom incl.)   */
/* -------------------------------------------------------------------------- */

function SiteRoleSelect({
  uuid,
  value,
  defaultRole,
  onChange,
}: {
  uuid: string;
  value: string;
  defaultRole: string;
  onChange: (role: string) => void;
}) {
  const { data } = useQuery({
    queryKey: ['wp-roles', uuid],
    queryFn: async () => (await api.get<{ data: WpRole[] }>(`/api/dashboard/sites/${uuid}/wp-roles`)).data.data,
    staleTime: 5 * 60 * 1000,
    retry: false,
  });
  const roles = data ?? STANDARD_ROLES;
  const defaultLabel = roles.find((r) => r.slug === defaultRole)?.name ?? defaultRole;

  return (
    <select
      className="w-40 shrink-0 rounded-lg border border-line bg-surface px-2 py-1.5 text-[12.5px] text-ink focus:border-brand-600 focus:outline-none"
      value={value}
      onChange={(e) => onChange(e.target.value)}
    >
      <option value="">Default ({defaultLabel})</option>
      {roles.map((r) => (
        <option key={r.slug} value={r.slug}>{r.name}</option>
      ))}
    </select>
  );
}

/* -------------------------------------------------------------------------- */
/* Results panel (§9) — per-site rows + retry-failed-only                       */
/* -------------------------------------------------------------------------- */

function ResultsPanel({
  results,
  username,
  operationId,
  createdCount,
  skippedCount,
  failedCount,
  submitting,
  onRetryFailed,
  onDone,
}: {
  results: BulkUserResult[];
  username: string;
  operationId: string | null;
  createdCount: number;
  skippedCount: number;
  failedCount: number;
  submitting: boolean;
  onRetryFailed: () => void;
  onDone: () => void;
}) {
  return (
    <section className="card p-5">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-[15px] font-semibold text-ink">Results for “{username}”</h2>
          <p className="mt-0.5 text-[12.5px] text-ink-muted">
            {createdCount} created · {failedCount} failed · {skippedCount} skipped
            {operationId && <span className="ml-2 font-mono text-[11px] text-ink-muted/70">op {operationId.slice(0, 8)}</span>}
          </p>
        </div>
        <div className="flex gap-2">
          {failedCount > 0 && (
            <button
              onClick={onRetryFailed}
              disabled={submitting}
              className="rounded-lg border border-line-strong bg-surface px-4 py-2 text-[13px] font-semibold text-ink-body hover:border-brand-600 hover:text-brand-600 disabled:opacity-60"
            >
              {submitting ? 'Retrying…' : `Retry ${failedCount} failed`}
            </button>
          )}
          <button
            onClick={onDone}
            className="rounded-lg bg-brand-gradient px-4 py-2 text-[13px] font-semibold text-white shadow-brand"
          >
            Done
          </button>
        </div>
      </div>

      <div className="overflow-hidden rounded-lg border border-line">
        <table className="w-full text-left text-[13px]">
          <thead>
            <tr className="border-b border-line bg-surface-soft text-[11.5px] uppercase tracking-wide text-ink-muted">
              <th className="px-4 py-2.5 font-semibold">Website</th>
              <th className="px-4 py-2.5 font-semibold">Role</th>
              <th className="px-4 py-2.5 font-semibold">Result</th>
              <th className="px-4 py-2.5 font-semibold">Detail</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {results.map((r) => (
              <tr key={r.uuid} className="hover:bg-surface-soft">
                <td className="px-4 py-2.5 font-medium text-ink">{r.domain ?? r.uuid}</td>
                <td className="px-4 py-2.5 text-ink-body">{r.role}</td>
                <td className="px-4 py-2.5">
                  <Pill tone={statusTone(r.status)}>
                    {r.status === 'created' ? 'Created' : r.status === 'failed' ? 'Failed' : 'Skipped'}
                  </Pill>
                </td>
                <td className="px-4 py-2.5 text-[12.5px] text-ink-muted">{r.message ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
