import { useEffect, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, toApiError } from '@/lib/api';
import type { SettingsResponse } from '@/types';
import { useAuth } from '@/context/AuthContext';
import { useTheme } from '@/context/ThemeContext';
import { ErrorState, LoadingState, Spinner } from '@/components/ui';
import { formatDate } from '@/lib/format';

export default function Settings() {
  const { refresh } = useAuth();
  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['settings'],
    queryFn: async () => (await api.get<SettingsResponse>('/api/dashboard/settings')).data,
  });

  if (isLoading) return <LoadingState />;
  if (isError) return <ErrorState message={(error as Error)?.message ?? 'Could not load settings.'} onRetry={refetch} />;

  return (
    <div className="max-w-2xl">
      <div className="mb-6">
        <h1 className="font-disp text-2xl font-semibold text-ink">Settings</h1>
        <p className="mt-1 text-sm text-ink-muted">Manage your profile, password, appearance and organization.</p>
      </div>

      <AppearanceSection />
      <ProfileSection data={data!} onSaved={refetch} refreshAuth={refresh} />
      <PasswordSection />
      <OrganizationSection data={data!} />
    </div>
  );
}

// Appearance — light/dark theme picker wired to the app-wide ThemeContext.
// The choice is persisted to localStorage ('mqp-theme') by the provider.
function AppearanceSection() {
  const { theme, setTheme } = useTheme();
  const options: { value: 'light' | 'dark'; label: string; preview: string }[] = [
    { value: 'light', label: 'Light', preview: 'bg-gradient-to-br from-surface-soft to-brand-100' },
    { value: 'dark', label: 'Dark', preview: 'bg-gradient-to-br from-[#0a0f1e] to-[#16203a]' },
  ];
  return (
    <div className="card mb-6 p-6">
      <h2 className="font-disp text-sm font-semibold text-ink">Appearance</h2>
      <p className="mt-1 text-xs text-ink-muted">Choose how the dashboard looks.</p>
      <div className="mt-3 grid grid-cols-2 gap-3">
        {options.map((o) => (
          <button
            key={o.value}
            type="button"
            onClick={() => setTheme(o.value)}
            className={`rounded-[13px] border p-3.5 text-center transition ${
              theme === o.value
                ? 'border-brand-600 shadow-[0_0_0_4px_rgba(59,91,255,0.12)]'
                : 'border-line hover:border-line-strong'
            }`}
            aria-pressed={theme === o.value}
          >
            <div className={`mb-2.5 h-[54px] rounded-[9px] border border-line ${o.preview}`} />
            <div className="text-[13px] font-semibold text-ink">{o.label}</div>
          </button>
        ))}
      </div>
    </div>
  );
}

function ProfileSection({ data, onSaved, refreshAuth }: { data: SettingsResponse; onSaved: () => void; refreshAuth: () => Promise<void> }) {
  const [name, setName] = useState(data.user.name);
  const [email, setEmail] = useState(data.user.email);
  const [msg, setMsg] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    setName(data.user.name);
    setEmail(data.user.email);
  }, [data]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setMsg(null);
    setError(null);
    setFieldErrors({});
    setSaving(true);
    try {
      await api.patch('/api/dashboard/settings/profile', { name, email });
      setMsg('Profile updated.');
      onSaved();
      await refreshAuth();
    } catch (err) {
      const e2 = toApiError(err);
      setError(e2.message);
      if (e2.errors) setFieldErrors(e2.errors);
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={submit} className="card mb-6 space-y-4 p-6">
      <h2 className="font-disp text-sm font-semibold text-ink">Profile</h2>
      {msg && <div className="rounded-lg bg-success/10 px-3 py-2 text-sm text-success-text">{msg}</div>}
      {error && <div className="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{error}</div>}
      <div>
        <label className="label">Name</label>
        <input className="input" value={name} onChange={(e) => setName(e.target.value)} required />
        {fieldErrors.name?.map((m) => <p key={m} className="mt-1 text-xs text-danger">{m}</p>)}
      </div>
      <div>
        <label className="label">Email</label>
        <input type="email" className="input" value={email} onChange={(e) => setEmail(e.target.value)} required />
        {fieldErrors.email?.map((m) => <p key={m} className="mt-1 text-xs text-danger">{m}</p>)}
      </div>
      <div className="text-right">
        <button className="btn-primary" disabled={saving}>
          {saving && <Spinner className="h-4 w-4 text-white" />}
          Save profile
        </button>
      </div>
    </form>
  );
}

function PasswordSection() {
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [msg, setMsg] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [saving, setSaving] = useState(false);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setMsg(null);
    setError(null);
    setFieldErrors({});
    setSaving(true);
    try {
      await api.patch('/api/dashboard/settings/password', {
        current_password: current,
        password,
        password_confirmation: confirm,
      });
      setMsg('Password updated.');
      setCurrent('');
      setPassword('');
      setConfirm('');
    } catch (err) {
      const e2 = toApiError(err);
      setError(e2.message);
      if (e2.errors) setFieldErrors(e2.errors);
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={submit} className="card mb-6 space-y-4 p-6">
      <h2 className="font-disp text-sm font-semibold text-ink">Change password</h2>
      <p className="text-xs text-ink-muted">Use at least 12 characters. You must confirm your current password.</p>
      {msg && <div className="rounded-lg bg-success/10 px-3 py-2 text-sm text-success-text">{msg}</div>}
      {error && <div className="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{error}</div>}
      <div>
        <label className="label">Current password</label>
        <input type="password" autoComplete="current-password" className="input" value={current} onChange={(e) => setCurrent(e.target.value)} required />
        {fieldErrors.current_password?.map((m) => <p key={m} className="mt-1 text-xs text-danger">{m}</p>)}
      </div>
      <div>
        <label className="label">New password</label>
        <input type="password" autoComplete="new-password" className="input" value={password} onChange={(e) => setPassword(e.target.value)} required />
        {fieldErrors.password?.map((m) => <p key={m} className="mt-1 text-xs text-danger">{m}</p>)}
      </div>
      <div>
        <label className="label">Confirm new password</label>
        <input type="password" autoComplete="new-password" className="input" value={confirm} onChange={(e) => setConfirm(e.target.value)} required />
      </div>
      <div className="text-right">
        <button className="btn-primary" disabled={saving}>
          {saving && <Spinner className="h-4 w-4 text-white" />}
          Update password
        </button>
      </div>
    </form>
  );
}

function OrganizationSection({ data }: { data: SettingsResponse }) {
  return (
    <div className="card p-6">
      <h2 className="font-disp text-sm font-semibold text-ink">Organization</h2>
      <dl className="mt-3 space-y-2 text-sm">
        <div className="flex justify-between">
          <dt className="text-ink-muted">Name</dt>
          <dd className="text-ink">{data.organization.name}</dd>
        </div>
        <div className="flex justify-between">
          <dt className="text-ink-muted">Slug</dt>
          <dd className="font-mono text-xs text-ink-body">{data.organization.slug}</dd>
        </div>
        <div className="flex justify-between">
          <dt className="text-ink-muted">Your role</dt>
          <dd className="text-ink capitalize">{data.user.organization?.role ?? '—'}</dd>
        </div>
        <div className="flex justify-between">
          <dt className="text-ink-muted">Created</dt>
          <dd className="text-ink">{formatDate(data.organization.created_at)}</dd>
        </div>
      </dl>
    </div>
  );
}
