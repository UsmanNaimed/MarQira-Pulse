import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, toApiError } from '@/lib/api';
import type { ApiToken, ApiTokenListResponse } from '@/types';
import { Badge, EmptyState, ErrorState, LoadingState, Spinner } from '@/components/ui';
import Modal, { CopyableSecret } from '@/components/Modal';
import { formatDate, timeAgo } from '@/lib/format';

export default function ApiTokens() {
  const qc = useQueryClient();
  const [creating, setCreating] = useState(false);
  const [newToken, setNewToken] = useState<string | null>(null);

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['api-tokens'],
    queryFn: async () => (await api.get<ApiTokenListResponse>('/api/dashboard/api-tokens')).data,
  });

  const revoke = useMutation({
    mutationFn: async (uuid: string) => api.delete(`/api/dashboard/api-tokens/${uuid}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['api-tokens'] }),
  });

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">API Tokens</h1>
          <p className="mt-1 text-sm text-slate-500">
            Tokens for external automation (e.g. n8n) to read your website data via the API.
          </p>
        </div>
        <button className="btn-primary" onClick={() => setCreating(true)}>
          Create token
        </button>
      </div>

      <div className="card overflow-hidden">
        {isLoading ? (
          <LoadingState />
        ) : isError ? (
          <ErrorState message={(error as Error)?.message ?? 'Could not load tokens.'} onRetry={refetch} />
        ) : data && data.data.length === 0 ? (
          <EmptyState
            title="No API tokens yet"
            description="Create a token to let n8n or other automation read your site data."
            action={
              <button className="btn-secondary" onClick={() => setCreating(true)}>
                Create your first token
              </button>
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50">
                <tr>
                  {['Name', 'Abilities', 'Allowed IPs', 'Last used', 'Expires', 'Status', ''].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data?.data.map((t: ApiToken) => (
                  <tr key={t.uuid} className="hover:bg-slate-50">
                    <td className="px-4 py-3 font-medium text-slate-800">{t.name}</td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-1">
                        {t.abilities.map((a) => (
                          <Badge key={a}>{a}</Badge>
                        ))}
                      </div>
                    </td>
                    <td className="px-4 py-3 font-mono text-xs text-slate-600">
                      {t.allowed_ips.length ? t.allowed_ips.join(', ') : <span className="text-slate-400">Any</span>}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{t.last_used_at ? timeAgo(t.last_used_at) : 'Never'}</td>
                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{t.expires_at ? formatDate(t.expires_at) : 'Never'}</td>
                    <td className="px-4 py-3">
                      {t.is_active ? <Badge tone="green">Active</Badge> : <Badge tone="red">{t.revoked_at ? 'Revoked' : 'Expired'}</Badge>}
                    </td>
                    <td className="px-4 py-3 text-right">
                      {t.is_active && (
                        <button
                          className="btn-danger px-3 py-1 text-xs"
                          disabled={revoke.isPending}
                          onClick={() => {
                            if (confirm(`Revoke "${t.name}"? Automation using it will stop working.`)) revoke.mutate(t.uuid);
                          }}
                        >
                          Revoke
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <CreateTokenModal
        open={creating}
        abilities={data?.available_abilities ?? []}
        onClose={() => setCreating(false)}
        onCreated={(raw) => {
          setCreating(false);
          setNewToken(raw);
          qc.invalidateQueries({ queryKey: ['api-tokens'] });
        }}
      />

      <Modal open={!!newToken} title="Token created" onClose={() => setNewToken(null)}>
        <p className="mb-3 text-sm text-slate-600">
          Copy this token now — for security it is shown <strong>only once</strong> and cannot be retrieved again.
        </p>
        {newToken && <CopyableSecret value={newToken} note="Store it in your automation's secret manager. If you lose it, revoke and create a new one." />}
        <div className="mt-5 text-right">
          <button className="btn-primary" onClick={() => setNewToken(null)}>
            Done
          </button>
        </div>
      </Modal>
    </div>
  );
}

function CreateTokenModal({
  open,
  abilities,
  onClose,
  onCreated,
}: {
  open: boolean;
  abilities: string[];
  onClose: () => void;
  onCreated: (rawToken: string) => void;
}) {
  const [name, setName] = useState('');
  const [selected, setSelected] = useState<string[]>([]);
  const [ips, setIps] = useState('');
  const [expiresAt, setExpiresAt] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const reset = () => {
    setName('');
    setSelected([]);
    setIps('');
    setExpiresAt('');
    setError(null);
  };

  const toggle = (a: string) => setSelected((s) => (s.includes(a) ? s.filter((x) => x !== a) : [...s, a]));

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    if (selected.length === 0) {
      setError('Select at least one ability.');
      return;
    }
    setSubmitting(true);
    try {
      const payload: Record<string, unknown> = { name, abilities: selected };
      const ipList = ips.split(/[\n,]/).map((s) => s.trim()).filter(Boolean);
      if (ipList.length) payload.allowed_ips = ipList;
      if (expiresAt) payload.expires_at = new Date(expiresAt).toISOString();
      const res = await api.post<{ token: string }>('/api/dashboard/api-tokens', payload);
      reset();
      onCreated(res.data.token);
    } catch (err) {
      setError(toApiError(err).message);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Modal open={open} title="Create API token" onClose={onClose}>
      <form onSubmit={submit} className="space-y-4">
        {error && <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

        <div>
          <label className="label">Name</label>
          <input className="input" required value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. n8n production" />
        </div>

        <div>
          <label className="label">Abilities</label>
          <div className="space-y-2">
            {abilities.map((a) => (
              <label key={a} className="flex items-center gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                  checked={selected.includes(a)}
                  onChange={() => toggle(a)}
                />
                <code className="text-xs">{a}</code>
              </label>
            ))}
          </div>
        </div>

        <div>
          <label className="label">Allowed IPs / CIDR (optional)</label>
          <textarea
            className="input font-mono text-xs"
            rows={2}
            value={ips}
            onChange={(e) => setIps(e.target.value)}
            placeholder="187.77.136.105, 10.0.0.0/8"
          />
          <p className="mt-1 text-xs text-slate-400">Comma or newline separated. Leave blank to allow any IP.</p>
        </div>

        <div>
          <label className="label">Expires at (optional)</label>
          <input type="datetime-local" className="input" value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)} />
        </div>

        <div className="flex justify-end gap-2 pt-2">
          <button type="button" className="btn-secondary" onClick={onClose}>
            Cancel
          </button>
          <button type="submit" className="btn-primary" disabled={submitting}>
            {submitting && <Spinner className="h-4 w-4 text-white" />}
            Create token
          </button>
        </div>
      </form>
    </Modal>
  );
}
