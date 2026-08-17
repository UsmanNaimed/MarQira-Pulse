import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, toApiError } from '@/lib/api';
import type { AccountCreateResponse, AccountDetail, AccountUser } from '@/types';
import { Badge, EmptyState, ErrorState, LoadingState, Spinner, StatusBadge } from '@/components/ui';
import Modal, { CopyableSecret } from '@/components/Modal';
import { formatDate, timeAgo } from '@/lib/format';

/** Owner-only Users dashboard (§5): create, search, and manage subscriber accounts. */
export default function Users() {
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const [q, setQ] = useState('');
  const [createOpen, setCreateOpen] = useState(false);
  const [openUuid, setOpenUuid] = useState<string | null>(null);

  const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey: ['accounts', q],
    queryFn: async () =>
      (await api.get<{ data: AccountUser[] }>(`/api/dashboard/accounts${q ? `?q=${encodeURIComponent(q)}` : ''}`)).data
        .data,
  });

  const submitSearch = (e: React.FormEvent) => {
    e.preventDefault();
    setQ(search.trim());
  };

  const invalidate = () => qc.invalidateQueries({ queryKey: ['accounts'] });

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Users</h1>
          <p className="mt-1 text-sm text-slate-500">Create and manage subscriber accounts and their website limits.</p>
        </div>
        <button className="btn-primary" onClick={() => setCreateOpen(true)}>
          Add user
        </button>
      </div>

      <form onSubmit={submitSearch} className="mb-4 flex items-center gap-2">
        <div className="relative flex-1 min-w-[220px]">
          <svg className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
          </svg>
          <input
            className="input pl-9"
            placeholder="Search by name or email…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <button type="submit" className="btn-secondary">
          Search
        </button>
        {q && (
          <button
            type="button"
            className="btn-secondary"
            onClick={() => {
              setSearch('');
              setQ('');
            }}
          >
            Clear
          </button>
        )}
      </form>

      <div className="card overflow-hidden">
        {isLoading ? (
          <LoadingState />
        ) : isError ? (
          <ErrorState message={(error as Error)?.message ?? 'Could not load users.'} onRetry={refetch} />
        ) : data && data.length === 0 ? (
          <EmptyState
            title="No users found"
            description={q ? 'Try a different search.' : 'Create your first subscriber account to get started.'}
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50">
                <tr>
                  {['Name', 'Email', 'Status', 'Websites', 'Limit', 'Created', 'Last Login', ''].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className={`divide-y divide-slate-100 ${isFetching ? 'opacity-60' : ''}`}>
                {data?.map((u) => (
                  <tr key={u.uuid} className="hover:bg-slate-50">
                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-800">{u.name}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-slate-600">{u.email}</td>
                    <td className="px-4 py-3">
                      {u.is_active ? <Badge tone="green">Active</Badge> : <Badge tone="red">Suspended</Badge>}
                    </td>
                    <td className="px-4 py-3 text-slate-600">{u.site_count}</td>
                    <td className="px-4 py-3 text-slate-600">{u.website_limit === null ? 'Unlimited' : u.website_limit}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{u.created_at ? formatDate(u.created_at) : '—'}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{u.last_login_at ? timeAgo(u.last_login_at) : 'Never'}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-right">
                      <button className="text-sm font-medium text-brand-700 hover:underline" onClick={() => setOpenUuid(u.uuid)}>
                        Manage
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <CreateUserModal open={createOpen} onClose={() => setCreateOpen(false)} onCreated={invalidate} />
      {openUuid && (
        <UserDetailModal
          uuid={openUuid}
          onClose={() => setOpenUuid(null)}
          onChanged={invalidate}
        />
      )}
    </div>
  );
}

function CreateUserModal({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: () => void }) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [limit, setLimit] = useState('');
  const [isActive, setIsActive] = useState(true);
  const [error, setError] = useState('');
  const [setupUrl, setSetupUrl] = useState<string | null>(null);

  const reset = () => {
    setName('');
    setEmail('');
    setLimit('');
    setIsActive(true);
    setError('');
    setSetupUrl(null);
  };

  const close = () => {
    reset();
    onClose();
  };

  const mutation = useMutation({
    mutationFn: async () => {
      const body: Record<string, unknown> = { name, email, is_active: isActive };
      if (limit.trim() !== '') body.website_limit = Number(limit);
      return (await api.post<AccountCreateResponse>('/api/dashboard/accounts', body)).data;
    },
    onSuccess: (res) => {
      setSetupUrl(res.setup_url);
      setError('');
      onCreated();
    },
    onError: (err) => setError(toApiError(err).message),
  });

  return (
    <Modal open={open} title="Add user" onClose={close}>
      {setupUrl ? (
        <div className="space-y-4">
          <p className="text-sm text-slate-600">
            Account created. Share this one-time setup link so the user can choose their own password. It expires in 48
            hours.
          </p>
          <CopyableSecret value={setupUrl} note="Anyone with this link can set the account password until it expires or is used." />
          <div className="flex justify-end">
            <button className="btn-primary" onClick={close}>
              Done
            </button>
          </div>
        </div>
      ) : (
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault();
            mutation.mutate();
          }}
        >
          {error && <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}
          <div>
            <label className="mb-1 block text-sm font-medium text-slate-700">Name</label>
            <input className="input" value={name} onChange={(e) => setName(e.target.value)} required />
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium text-slate-700">Email</label>
            <input className="input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium text-slate-700">Website limit</label>
            <input
              className="input"
              type="number"
              min={0}
              placeholder="Leave blank for unlimited"
              value={limit}
              onChange={(e) => setLimit(e.target.value)}
            />
            <p className="mt-1 text-xs text-slate-500">Leave blank for unlimited. Common tiers: 1, 5, 25.</p>
          </div>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              checked={isActive}
              onChange={(e) => setIsActive(e.target.checked)}
            />
            Account active
          </label>
          <p className="text-xs text-slate-500">
            The user receives a single-use setup link to choose their own password — no password is ever generated or
            emailed in plaintext.
          </p>
          <div className="flex justify-end gap-2">
            <button type="button" className="btn-secondary" onClick={close}>
              Cancel
            </button>
            <button type="submit" className="btn-primary" disabled={mutation.isPending}>
              {mutation.isPending && <Spinner className="h-4 w-4 text-white" />}
              Create user
            </button>
          </div>
        </form>
      )}
    </Modal>
  );
}

function UserDetailModal({ uuid, onClose, onChanged }: { uuid: string; onClose: () => void; onChanged: () => void }) {
  const qc = useQueryClient();
  const [error, setError] = useState('');
  const [setupUrl, setSetupUrl] = useState<string | null>(null);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [limit, setLimit] = useState('');
  const [edited, setEdited] = useState(false);

  const { data, isLoading, isError, error: qError, refetch } = useQuery({
    queryKey: ['account', uuid],
    queryFn: async () => {
      const detail = (await api.get<{ data: AccountDetail }>(`/api/dashboard/accounts/${uuid}`)).data.data;
      setName(detail.name);
      setEmail(detail.email);
      setLimit(detail.website_limit === null ? '' : String(detail.website_limit));
      setEdited(false);
      return detail;
    },
  });

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['account', uuid] });
    onChanged();
  };

  const save = useMutation({
    mutationFn: async () => {
      const body: Record<string, unknown> = { name, email };
      body.website_limit = limit.trim() === '' ? null : Number(limit);
      return (await api.patch(`/api/dashboard/accounts/${uuid}`, body)).data;
    },
    onSuccess: () => {
      setError('');
      refresh();
      refetch();
    },
    onError: (err) => setError(toApiError(err).message),
  });

  const toggleActive = useMutation({
    mutationFn: async (activate: boolean) =>
      (await api.post(`/api/dashboard/accounts/${uuid}/${activate ? 'activate' : 'deactivate'}`)).data,
    onSuccess: () => {
      setError('');
      refresh();
      refetch();
    },
    onError: (err) => setError(toApiError(err).message),
  });

  const resend = useMutation({
    mutationFn: async () => (await api.post<{ setup_url: string }>(`/api/dashboard/accounts/${uuid}/resend-setup`)).data,
    onSuccess: (res) => {
      setSetupUrl(res.setup_url);
      setError('');
    },
    onError: (err) => setError(toApiError(err).message),
  });

  return (
    <Modal open title="Manage user" onClose={onClose} maxWidth="max-w-2xl">
      {isLoading ? (
        <LoadingState />
      ) : isError ? (
        <ErrorState message={(qError as Error)?.message ?? 'Could not load this user.'} onRetry={refetch} />
      ) : data ? (
        <div className="space-y-5">
          {error && <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              {data.is_active ? <Badge tone="green">Active</Badge> : <Badge tone="red">Suspended</Badge>}
              <span className="text-xs text-slate-500">
                Created {data.created_at ? formatDate(data.created_at) : '—'} · Last login{' '}
                {data.last_login_at ? timeAgo(data.last_login_at) : 'never'}
              </span>
            </div>
            <button
              className="btn-secondary"
              onClick={() => toggleActive.mutate(!data.is_active)}
              disabled={toggleActive.isPending}
            >
              {data.is_active ? 'Suspend' : 'Activate'}
            </button>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label className="mb-1 block text-sm font-medium text-slate-700">Name</label>
              <input
                className="input"
                value={name}
                onChange={(e) => {
                  setName(e.target.value);
                  setEdited(true);
                }}
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-slate-700">Email</label>
              <input
                className="input"
                type="email"
                value={email}
                onChange={(e) => {
                  setEmail(e.target.value);
                  setEdited(true);
                }}
              />
            </div>
            <div>
              <label className="mb-1 block text-sm font-medium text-slate-700">Website limit</label>
              <input
                className="input"
                type="number"
                min={0}
                placeholder="Unlimited"
                value={limit}
                onChange={(e) => {
                  setLimit(e.target.value);
                  setEdited(true);
                }}
              />
              <p className="mt-1 text-xs text-slate-500">
                {data.site_count} website{data.site_count === 1 ? '' : 's'} in use. Blank = unlimited.
              </p>
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <button className="btn-primary" onClick={() => save.mutate()} disabled={!edited || save.isPending}>
              {save.isPending && <Spinner className="h-4 w-4 text-white" />}
              Save changes
            </button>
            <button className="btn-secondary" onClick={() => resend.mutate()} disabled={resend.isPending}>
              {resend.isPending && <Spinner className="h-4 w-4" />}
              Resend setup link
            </button>
          </div>

          {setupUrl && (
            <CopyableSecret value={setupUrl} note="Fresh setup link — this invalidates any previous unused link." />
          )}

          <div>
            <h3 className="mb-2 text-sm font-semibold text-slate-900">Websites ({data.sites.length})</h3>
            {data.sites.length === 0 ? (
              <p className="text-sm text-slate-500">This user does not own any websites yet.</p>
            ) : (
              <div className="overflow-hidden rounded-lg border border-slate-200">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                  <tbody className="divide-y divide-slate-100">
                    {data.sites.map((s) => (
                      <tr key={s.uuid} className="hover:bg-slate-50">
                        <td className="px-4 py-2">
                          <Link to={`/websites/${s.uuid}`} className="font-medium text-brand-700 hover:underline">
                            {s.domain}
                          </Link>
                        </td>
                        <td className="px-4 py-2">
                          <StatusBadge status={s.status} />
                        </td>
                        <td className="whitespace-nowrap px-4 py-2 text-slate-500">
                          {s.last_heartbeat_at ? timeAgo(s.last_heartbeat_at) : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      ) : null}
    </Modal>
  );
}
