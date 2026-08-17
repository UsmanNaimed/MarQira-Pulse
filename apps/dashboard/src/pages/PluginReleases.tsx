import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, toApiError } from '@/lib/api';
import type { PluginRelease, PluginReleaseListResponse } from '@/types';
import { Badge, EmptyState, ErrorState, LoadingState, Spinner } from '@/components/ui';
import Modal from '@/components/Modal';
import { timeAgo } from '@/lib/format';

export default function PluginReleases() {
  const qc = useQueryClient();
  const [creating, setCreating] = useState(false);

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['plugin-releases'],
    queryFn: async () => (await api.get<PluginReleaseListResponse>('/api/dashboard/plugin-releases')).data,
  });

  const activate = useMutation({
    mutationFn: async (id: number) => api.post(`/api/dashboard/plugin-releases/${id}/activate`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['plugin-releases'] }),
  });

  const deleteRelease = useMutation({
    mutationFn: async (id: number) => api.delete(`/api/dashboard/plugin-releases/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['plugin-releases'] }),
  });

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Plugin Releases</h1>
          <p className="mt-1 text-sm text-slate-500">Publish and track MarQira Connector versions across your fleet.</p>
        </div>
        <button className="btn-primary" onClick={() => setCreating(true)}>
          Publish release
        </button>
      </div>

      <div className="card overflow-hidden">
        {isLoading ? (
          <LoadingState />
        ) : isError ? (
          <ErrorState message={(error as Error)?.message ?? 'Could not load releases.'} onRetry={refetch} />
        ) : data && data.data.length === 0 ? (
          <EmptyState
            title="No plugin releases yet"
            description="Publish your first MarQira Connector release to enable automatic updates across your sites."
            action={
              <button className="btn-secondary" onClick={() => setCreating(true)}>
                Publish your first release
              </button>
            }
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50">
                <tr>
                  {['Version', 'Released', 'Requirements', 'File size', 'Status', ''].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data?.data.map((r: PluginRelease) => (
                  <tr key={r.id} className="hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <div className="font-mono text-sm font-semibold text-slate-800">{r.version}</div>
                      {r.changelog && (
                        <div className="mt-1 max-w-md truncate text-xs text-slate-500" title={r.changelog}>
                          {r.changelog.split('\n')[0]}
                        </div>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-slate-600">
                      {r.released_at ? timeAgo(r.released_at) : 'Draft'}
                      {r.released_by && <div className="text-xs text-slate-400">{r.released_by.name}</div>}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-600">
                      <div>WP {r.requires_wp ?? '?'}</div>
                      <div>PHP {r.requires_php ?? '?'}</div>
                      {r.tested_up_to && <div className="text-slate-400">Tested: {r.tested_up_to}</div>}
                    </td>
                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">
                      {r.file_size ? formatBytes(r.file_size) : '—'}
                    </td>
                    <td className="px-4 py-3">
                      {r.is_active ? (
                        <Badge tone="green">Active</Badge>
                      ) : (
                        <Badge tone="slate">Inactive</Badge>
                      )}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <div className="flex items-center justify-end gap-2">
                        {!r.is_active && (
                          <button
                            className="btn-secondary px-3 py-1 text-xs"
                            disabled={activate.isPending}
                            onClick={() => {
                              if (confirm(`Activate version ${r.version}? This will become the update server's current stable release.`))
                                activate.mutate(r.id);
                            }}
                          >
                            Activate
                          </button>
                        )}
                        {!r.is_active && (
                          <button
                            className="btn-danger px-3 py-1 text-xs"
                            disabled={deleteRelease.isPending}
                            onClick={() => {
                              if (confirm(`Delete version ${r.version}? This cannot be undone.`)) deleteRelease.mutate(r.id);
                            }}
                          >
                            Delete
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <CreateReleaseModal
        open={creating}
        onClose={() => setCreating(false)}
        onCreated={() => {
          setCreating(false);
          qc.invalidateQueries({ queryKey: ['plugin-releases'] });
        }}
      />
    </div>
  );
}

function CreateReleaseModal({
  open,
  onClose,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: () => void;
}) {
  const [mode, setMode] = useState<'upload' | 'url'>('upload');
  const [version, setVersion] = useState('');
  const [changelog, setChangelog] = useState('');
  const [downloadUrl, setDownloadUrl] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [requiresWp, setRequiresWp] = useState('5.6');
  const [requiresPhp, setRequiresPhp] = useState('7.4');
  const [testedUpTo, setTestedUpTo] = useState('');
  const [isActive, setIsActive] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const reset = () => {
    setMode('upload');
    setVersion('');
    setChangelog('');
    setDownloadUrl('');
    setFile(null);
    setRequiresWp('5.6');
    setRequiresPhp('7.4');
    setTestedUpTo('');
    setIsActive(true);
    setError(null);
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);

    if (mode === 'upload' && !file) {
      setError('Please choose a .zip file to upload.');
      return;
    }
    if (mode === 'url' && !downloadUrl) {
      setError('Please enter a download URL.');
      return;
    }

    setSubmitting(true);
    try {
      if (mode === 'upload' && file) {
        // Multipart upload: the API stores the zip, computes the hash/size, and
        // (when active) serves it from downloads.marqira.com automatically.
        const form = new FormData();
        form.append('version', version);
        if (changelog) form.append('changelog', changelog);
        form.append('file', file);
        if (requiresWp) form.append('requires_wp', requiresWp);
        if (requiresPhp) form.append('requires_php', requiresPhp);
        if (testedUpTo) form.append('tested_up_to', testedUpTo);
        form.append('is_active', isActive ? '1' : '0');
        await api.post('/api/dashboard/plugin-releases', form, {
          headers: { 'Content-Type': 'multipart/form-data' },
        });
      } else {
        const payload: Record<string, unknown> = {
          version,
          changelog: changelog || null,
          download_url: downloadUrl,
          requires_wp: requiresWp || null,
          requires_php: requiresPhp || null,
          tested_up_to: testedUpTo || null,
          is_active: isActive,
        };
        await api.post('/api/dashboard/plugin-releases', payload);
      }
      reset();
      onCreated();
    } catch (err) {
      setError(toApiError(err).message);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Modal open={open} title="Publish plugin release" onClose={onClose}>
      <form onSubmit={submit} className="space-y-4">
        {error && <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

        {/* Source toggle: upload a zip (recommended) or point to an external URL. */}
        <div className="inline-flex rounded-lg border border-slate-200 p-0.5 text-sm">
          <button
            type="button"
            onClick={() => setMode('upload')}
            className={`rounded-md px-3 py-1.5 font-medium transition ${
              mode === 'upload' ? 'bg-brand-600 text-white' : 'text-slate-600 hover:text-slate-800'
            }`}
          >
            Upload .zip
          </button>
          <button
            type="button"
            onClick={() => setMode('url')}
            className={`rounded-md px-3 py-1.5 font-medium transition ${
              mode === 'url' ? 'bg-brand-600 text-white' : 'text-slate-600 hover:text-slate-800'
            }`}
          >
            External URL
          </button>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="label">Version *</label>
            <input
              className="input font-mono"
              required
              value={version}
              onChange={(e) => setVersion(e.target.value)}
              placeholder="1.2.3"
            />
          </div>
          <div>
            <label className="label">Tested up to</label>
            <input
              className="input font-mono"
              value={testedUpTo}
              onChange={(e) => setTestedUpTo(e.target.value)}
              placeholder="6.4"
            />
          </div>
        </div>

        {mode === 'upload' ? (
          <div>
            <label className="label">Plugin zip *</label>
            <input
              type="file"
              accept=".zip,application/zip"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              className="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100"
            />
            <p className="mt-1 text-xs text-slate-500">
              The SHA-256 hash and file size are computed automatically. Once active, this version becomes the default
              download and is offered to every connected site.
            </p>
          </div>
        ) : (
          <div>
            <label className="label">Download URL *</label>
            <input
              className="input font-mono text-xs"
              value={downloadUrl}
              onChange={(e) => setDownloadUrl(e.target.value)}
              placeholder="https://downloads.marqira.com/marqira-connector-1.2.3.zip"
            />
          </div>
        )}

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="label">Requires WordPress</label>
            <input
              className="input font-mono"
              value={requiresWp}
              onChange={(e) => setRequiresWp(e.target.value)}
              placeholder="5.6"
            />
          </div>
          <div>
            <label className="label">Requires PHP</label>
            <input
              className="input font-mono"
              value={requiresPhp}
              onChange={(e) => setRequiresPhp(e.target.value)}
              placeholder="7.4"
            />
          </div>
        </div>

        <div>
          <label className="label">Changelog</label>
          <textarea
            className="input"
            rows={4}
            value={changelog}
            onChange={(e) => setChangelog(e.target.value)}
            placeholder="What's new in this version..."
          />
        </div>

        <div>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              checked={isActive}
              onChange={(e) => setIsActive(e.target.checked)}
            />
            Mark as active release (deactivates all others)
          </label>
        </div>

        <div className="flex justify-end gap-2 pt-2">
          <button type="button" className="btn-secondary" onClick={onClose}>
            Cancel
          </button>
          <button type="submit" className="btn-primary" disabled={submitting}>
            {submitting && <Spinner className="h-4 w-4 text-white" />}
            Publish release
          </button>
        </div>
      </form>
    </Modal>
  );
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
