import { useState, type ReactNode } from 'react';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, toApiError } from '@/lib/api';
import type { WpReassignCandidate, WpRole, WpUser, WpUserListResponse } from '@/types';
import { EmptyState, ErrorState, LoadingState, Pill } from '@/components/ui';
import Modal from '@/components/Modal';

/* -------------------------------------------------------------------------- */
/* Small local primitives (kept here so the tab is self-contained).            */
/* -------------------------------------------------------------------------- */

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

const inputCls =
  'w-full rounded-lg border border-line bg-surface px-3 py-2 text-[13.5px] text-ink placeholder:text-ink-muted focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600';

function Notice({ tone, children }: { tone: 'error' | 'success'; children: ReactNode }) {
  return (
    <div
      className={
        tone === 'error'
          ? 'rounded-lg bg-danger/10 px-3 py-2 text-[12.5px] font-medium text-danger'
          : 'rounded-lg bg-success/10 px-3 py-2 text-[12.5px] font-medium text-success-text'
      }
    >
      {children}
    </div>
  );
}

function roleTone(slug: string): 'brand' | 'neutral' {
  return slug.toLowerCase() === 'administrator' ? 'brand' : 'neutral';
}

/* -------------------------------------------------------------------------- */
/* Main tab                                                                    */
/* -------------------------------------------------------------------------- */

export default function WpUsersTab({ uuid }: { uuid: string }) {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [role, setRole] = useState('');
  const [flash, setFlash] = useState('');

  const [createOpen, setCreateOpen] = useState(false);
  const [editUser, setEditUser] = useState<WpUser | null>(null);
  const [deleteUser, setDeleteUser] = useState<WpUser | null>(null);

  const rolesQuery = useQuery({
    queryKey: ['wp-roles', uuid],
    queryFn: async () => (await api.get<{ data: WpRole[] }>(`/api/dashboard/sites/${uuid}/wp-roles`)).data.data,
    staleTime: 5 * 60 * 1000,
  });
  const roles = rolesQuery.data ?? [];

  const usersQuery = useQuery({
    queryKey: ['wp-users', uuid, page, search, role],
    queryFn: async () => {
      const params = new URLSearchParams({ page: String(page), per_page: '25' });
      if (search) params.set('search', search);
      if (role) params.set('role', role);
      return (await api.get<WpUserListResponse>(`/api/dashboard/sites/${uuid}/wp-users?${params.toString()}`)).data;
    },
    placeholderData: keepPreviousData,
  });

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['wp-users', uuid] });
  };

  const flashMessage = (msg: string) => {
    setFlash(msg);
    window.setTimeout(() => setFlash(''), 4000);
  };

  // Distinguish "connector unsupported / offline" (structured 4xx from proxy)
  // from a transient error so the empty state is honest.
  if (usersQuery.isError) {
    const err = toApiError(usersQuery.error);
    return (
      <ErrorState
        message={err.message || 'Could not load users from this website.'}
        onRetry={() => usersQuery.refetch()}
      />
    );
  }

  const data = usersQuery.data;
  const meta = data?.meta;
  const users = data?.data ?? [];

  return (
    <div className="space-y-[18px]">
      {flash && <Notice tone="success">{flash}</Notice>}

      {/* Toolbar */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <form
          className="flex flex-1 flex-wrap items-center gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            setPage(1);
            setSearch(searchInput.trim());
          }}
        >
          <input
            className={`${inputCls} max-w-[260px]`}
            placeholder="Search name, username or email…"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
          />
          <select
            className={`${inputCls} max-w-[190px]`}
            value={role}
            onChange={(e) => {
              setRole(e.target.value);
              setPage(1);
            }}
          >
            <option value="">All roles</option>
            {roles.map((r) => (
              <option key={r.slug} value={r.slug}>
                {r.name}
              </option>
            ))}
          </select>
          <button type="submit" className="btn-secondary btn-sm">
            Search
          </button>
          {(search || role) && (
            <button
              type="button"
              className="btn-ghost btn-sm"
              onClick={() => {
                setSearch('');
                setSearchInput('');
                setRole('');
                setPage(1);
              }}
            >
              Clear
            </button>
          )}
        </form>

        <button type="button" className="btn-primary btn-sm shrink-0" onClick={() => setCreateOpen(true)}>
          + Add User
        </button>
      </div>

      {/* Table */}
      <div className="card">
        {usersQuery.isLoading ? (
          <div className="p-8">
            <LoadingState />
          </div>
        ) : users.length === 0 ? (
          <EmptyState
            title={search || role ? 'No matching users' : 'No users found'}
            description={
              search || role
                ? 'Try a different search term or role filter.'
                : 'Create the first WordPress user for this website.'
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full border-collapse text-[13.5px]">
              <thead>
                <tr>
                  {['User', 'Email', 'Roles', 'Registered', 'Actions'].map((h) => (
                    <th
                      key={h}
                      className="border-b border-line px-[18px] py-3 text-left text-[10.5px] font-semibold uppercase tracking-wider text-ink-muted whitespace-nowrap"
                    >
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {users.map((u) => (
                  <tr key={u.id} className="transition hover:bg-surface-soft">
                    <td className="border-b border-line px-[18px] py-[13px]">
                      <div className="font-semibold text-ink">{u.display_name || u.username}</div>
                      <div className="text-[11.5px] text-ink-muted">{u.username}</div>
                    </td>
                    <td className="border-b border-line px-[18px] py-[13px] font-mono text-[12.5px] text-ink-body">
                      {u.email || '—'}
                    </td>
                    <td className="border-b border-line px-[18px] py-[13px]">
                      {u.roles.length > 0 ? (
                        <div className="flex flex-wrap gap-1">
                          {u.roles.map((r, idx) => (
                            <Pill key={idx} tone={roleTone(r)}>
                              {u.role_names[idx] || r}
                            </Pill>
                          ))}
                        </div>
                      ) : (
                        '—'
                      )}
                    </td>
                    <td className="whitespace-nowrap border-b border-line px-[18px] py-[13px] text-ink-body">
                      {u.registered_at ? new Date(u.registered_at).toLocaleDateString() : '—'}
                    </td>
                    <td className="whitespace-nowrap border-b border-line px-[18px] py-[13px]">
                      <div className="flex items-center gap-2">
                        <button className="btn-ghost btn-sm" onClick={() => setEditUser(u)}>
                          Edit
                        </button>
                        <button
                          className="btn-ghost btn-sm text-danger hover:bg-danger/10"
                          onClick={() => setDeleteUser(u)}
                        >
                          Delete
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-[12.5px] text-ink-muted">
          <span>
            Page {meta.current_page} of {meta.last_page} · {meta.total} users
          </span>
          <div className="flex gap-2">
            <button className="btn-secondary btn-sm" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
              Previous
            </button>
            <button
              className="btn-secondary btn-sm"
              disabled={page >= meta.last_page}
              onClick={() => setPage((p) => p + 1)}
            >
              Next
            </button>
          </div>
        </div>
      )}

      {createOpen && (
        <CreateUserModal
          uuid={uuid}
          roles={roles}
          onClose={() => setCreateOpen(false)}
          onCreated={(name) => {
            setCreateOpen(false);
            refresh();
            flashMessage(`User "${name}" created.`);
          }}
        />
      )}

      {editUser && (
        <EditUserModal
          uuid={uuid}
          user={editUser}
          roles={roles}
          onClose={() => setEditUser(null)}
          onSaved={(name) => {
            setEditUser(null);
            refresh();
            flashMessage(`User "${name}" updated.`);
          }}
        />
      )}

      {deleteUser && (
        <DeleteUserModal
          uuid={uuid}
          user={deleteUser}
          onClose={() => setDeleteUser(null)}
          onDeleted={(name) => {
            setDeleteUser(null);
            refresh();
            flashMessage(`User "${name}" deleted.`);
          }}
        />
      )}
    </div>
  );
}

/* -------------------------------------------------------------------------- */
/* Create                                                                      */
/* -------------------------------------------------------------------------- */

function CreateUserModal({
  uuid,
  roles,
  onClose,
  onCreated,
}: {
  uuid: string;
  roles: WpRole[];
  onClose: () => void;
  onCreated: (name: string) => void;
}) {
  const [form, setForm] = useState({
    username: '',
    email: '',
    password: '',
    first_name: '',
    last_name: '',
    display_name: '',
    website: '',
    bio: '',
    role: roles.find((r) => r.slug === 'subscriber')?.slug ?? roles[0]?.slug ?? 'subscriber',
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
    setForm((f) => ({ ...f, [k]: e.target.value }));

  const submit = async () => {
    setError('');
    setBusy(true);
    try {
      const payload: Record<string, unknown> = { ...form };
      if (!payload.password) delete payload.password;
      await api.post(`/api/dashboard/sites/${uuid}/wp-users`, payload);
      onCreated(form.username);
    } catch (err) {
      setError(toApiError(err).message || 'Could not create the user.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open title="Add WordPress User" onClose={onClose} maxWidth="max-w-2xl">
      <div className="space-y-4">
        {error && <Notice tone="error">{error}</Notice>}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Username" required>
            <input className={inputCls} value={form.username} onChange={set('username')} autoFocus />
          </Field>
          <Field label="Email" required>
            <input className={inputCls} type="email" value={form.email} onChange={set('email')} />
          </Field>
          <Field label="First name">
            <input className={inputCls} value={form.first_name} onChange={set('first_name')} />
          </Field>
          <Field label="Last name">
            <input className={inputCls} value={form.last_name} onChange={set('last_name')} />
          </Field>
          <Field label="Display name">
            <input className={inputCls} value={form.display_name} onChange={set('display_name')} />
          </Field>
          <Field label="Role" required>
            <select className={inputCls} value={form.role} onChange={set('role')}>
              {roles.map((r) => (
                <option key={r.slug} value={r.slug}>
                  {r.name}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Password" hint="Leave blank to auto-generate a strong password.">
            <input className={inputCls} type="text" value={form.password} onChange={set('password')} />
          </Field>
          <Field label="Website">
            <input className={inputCls} type="url" value={form.website} onChange={set('website')} placeholder="https://" />
          </Field>
        </div>
        <Field label="Bio / description">
          <textarea className={inputCls} rows={2} value={form.bio} onChange={set('bio')} />
        </Field>
        <div className="flex justify-end gap-2 pt-1">
          <button className="btn-secondary" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button className="btn-primary" onClick={submit} disabled={busy || !form.username || !form.email}>
            {busy ? 'Creating…' : 'Create User'}
          </button>
        </div>
      </div>
    </Modal>
  );
}

/* -------------------------------------------------------------------------- */
/* Edit (profile + password + role)                                            */
/* -------------------------------------------------------------------------- */

function EditUserModal({
  uuid,
  user,
  roles,
  onClose,
  onSaved,
}: {
  uuid: string;
  user: WpUser;
  roles: WpRole[];
  onClose: () => void;
  onSaved: (name: string) => void;
}) {
  const [form, setForm] = useState({
    email: user.email,
    first_name: user.first_name ?? '',
    last_name: user.last_name ?? '',
    display_name: user.display_name,
    website: user.website ?? '',
    bio: user.bio ?? '',
    role: user.roles[0] ?? '',
    password: '',
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
    setForm((f) => ({ ...f, [k]: e.target.value }));

  const submit = async () => {
    setError('');
    setBusy(true);
    try {
      const payload: Record<string, unknown> = {
        email: form.email,
        first_name: form.first_name,
        last_name: form.last_name,
        display_name: form.display_name,
        website: form.website,
        bio: form.bio,
        role: form.role,
      };
      if (form.password) payload.password = form.password;
      await api.put(`/api/dashboard/sites/${uuid}/wp-users/${user.id}`, payload);
      onSaved(form.display_name || user.username);
    } catch (err) {
      setError(toApiError(err).message || 'Could not update the user.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open title={`Edit ${user.username}`} onClose={onClose} maxWidth="max-w-2xl">
      <div className="space-y-4">
        {error && <Notice tone="error">{error}</Notice>}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Email" required>
            <input className={inputCls} type="email" value={form.email} onChange={set('email')} />
          </Field>
          <Field label="Role" required>
            <select className={inputCls} value={form.role} onChange={set('role')}>
              {roles.map((r) => (
                <option key={r.slug} value={r.slug}>
                  {r.name}
                </option>
              ))}
            </select>
          </Field>
          <Field label="First name">
            <input className={inputCls} value={form.first_name} onChange={set('first_name')} />
          </Field>
          <Field label="Last name">
            <input className={inputCls} value={form.last_name} onChange={set('last_name')} />
          </Field>
          <Field label="Display name">
            <input className={inputCls} value={form.display_name} onChange={set('display_name')} />
          </Field>
          <Field label="Website">
            <input className={inputCls} type="url" value={form.website} onChange={set('website')} placeholder="https://" />
          </Field>
        </div>
        <Field label="New password" hint="Leave blank to keep the current password. Existing passwords can never be viewed.">
          <input className={inputCls} type="text" value={form.password} onChange={set('password')} placeholder="••••••••" />
        </Field>
        <Field label="Bio / description">
          <textarea className={inputCls} rows={2} value={form.bio} onChange={set('bio')} />
        </Field>
        <div className="flex justify-end gap-2 pt-1">
          <button className="btn-secondary" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button className="btn-primary" onClick={submit} disabled={busy || !form.email}>
            {busy ? 'Saving…' : 'Save Changes'}
          </button>
        </div>
      </div>
    </Modal>
  );
}

/* -------------------------------------------------------------------------- */
/* Delete (with content reassignment)                                          */
/* -------------------------------------------------------------------------- */

function DeleteUserModal({
  uuid,
  user,
  onClose,
  onDeleted,
}: {
  uuid: string;
  user: WpUser;
  onClose: () => void;
  onDeleted: (name: string) => void;
}) {
  const [mode, setMode] = useState<'reassign' | 'delete'>('reassign');
  const [reassignTo, setReassignTo] = useState<number | ''>('');
  const [candSearch, setCandSearch] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const candidatesQuery = useQuery({
    queryKey: ['wp-reassign', uuid, user.id, candSearch],
    queryFn: async () => {
      const params = new URLSearchParams({ exclude: String(user.id) });
      if (candSearch) params.set('search', candSearch);
      return (
        await api.get<{ data: WpReassignCandidate[] }>(
          `/api/dashboard/sites/${uuid}/wp-users/reassign-candidates?${params.toString()}`,
        )
      ).data.data;
    },
  });
  const candidates = candidatesQuery.data ?? [];

  const submit = async () => {
    setError('');
    if (mode === 'reassign' && !reassignTo) {
      setError('Choose a user to receive this user’s content.');
      return;
    }
    setBusy(true);
    try {
      const params = new URLSearchParams();
      if (mode === 'reassign') {
        params.set('reassign_to', String(reassignTo));
      } else {
        params.set('force_delete', 'true');
      }
      await api.delete(`/api/dashboard/sites/${uuid}/wp-users/${user.id}?${params.toString()}`);
      onDeleted(user.display_name || user.username);
    } catch (err) {
      setError(toApiError(err).message || 'Could not delete the user.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open title={`Delete ${user.username}`} onClose={onClose} maxWidth="max-w-xl">
      <div className="space-y-4">
        {error && <Notice tone="error">{error}</Notice>}

        <p className="text-[13.5px] text-ink-body">
          You are about to delete <strong className="text-ink">{user.display_name || user.username}</strong>
          {typeof user.post_count === 'number' && user.post_count > 0 && (
            <>
              {' '}
              who owns <strong className="text-ink">{user.post_count}</strong> item(s) of content.
            </>
          )}
          . Choose what should happen to their content:
        </p>

        <div className="space-y-2">
          <label className="flex items-start gap-2 rounded-lg border border-line p-3">
            <input
              type="radio"
              className="mt-1"
              checked={mode === 'reassign'}
              onChange={() => setMode('reassign')}
            />
            <span>
              <span className="block text-[13.5px] font-semibold text-ink">Reassign content (recommended)</span>
              <span className="block text-[12px] text-ink-muted">
                Their posts and pages are transferred to another user and stay published.
              </span>
            </span>
          </label>

          {mode === 'reassign' && (
            <div className="ml-6 space-y-2">
              <input
                className={inputCls}
                placeholder="Search users…"
                value={candSearch}
                onChange={(e) => setCandSearch(e.target.value)}
              />
              <select className={inputCls} value={reassignTo} onChange={(e) => setReassignTo(Number(e.target.value) || '')}>
                <option value="">Select a replacement user…</option>
                {candidates.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.display_name} ({c.user_login})
                  </option>
                ))}
              </select>
            </div>
          )}

          <label className="flex items-start gap-2 rounded-lg border border-line p-3">
            <input type="radio" className="mt-1" checked={mode === 'delete'} onChange={() => setMode('delete')} />
            <span>
              <span className="block text-[13.5px] font-semibold text-danger">Delete all their content</span>
              <span className="block text-[12px] text-ink-muted">
                Permanently removes every post and page owned by this user. This cannot be undone.
              </span>
            </span>
          </label>
        </div>

        <div className="flex justify-end gap-2 pt-1">
          <button className="btn-secondary" onClick={onClose} disabled={busy}>
            Cancel
          </button>
          <button className="btn-danger" onClick={submit} disabled={busy}>
            {busy ? 'Deleting…' : 'Delete User'}
          </button>
        </div>
      </div>
    </Modal>
  );
}
