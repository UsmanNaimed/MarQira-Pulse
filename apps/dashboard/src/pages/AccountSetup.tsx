import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api, ensureCsrfCookie, toApiError, type ApiErrorShape } from '@/lib/api';
import { Spinner } from '@/components/ui';
import Brand from '@/components/Brand';

interface SetupInfo {
  valid: boolean;
  name?: string;
  email?: string;
  error?: string;
}

/**
 * Public page an invited Subscriber lands on from their setup link
 * ({frontend_url}/account-setup/{token}). Validates the token, then lets them
 * choose their own password. No password is ever emailed (§6/§22).
 */
export default function AccountSetup() {
  const { token = '' } = useParams();
  const navigate = useNavigate();

  const [checking, setChecking] = useState(true);
  const [info, setInfo] = useState<SetupInfo | null>(null);
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);
  const [done, setDone] = useState(false);

  useEffect(() => {
    let active = true;
    (async () => {
      try {
        const { data } = await api.get<SetupInfo>(`/api/account-setup/${token}`);
        if (active) setInfo(data);
      } catch (err) {
        const apiErr = toApiError(err);
        if (active) setInfo({ valid: false, error: apiErr.message });
      } finally {
        if (active) setChecking(false);
      }
    })();
    return () => {
      active = false;
    };
  }, [token]);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setFieldErrors({});
    setSubmitting(true);
    try {
      await ensureCsrfCookie();
      await api.post(`/api/account-setup/${token}`, {
        password,
        password_confirmation: confirm,
      });
      setDone(true);
      setTimeout(() => navigate('/login', { replace: true }), 2000);
    } catch (err) {
      const apiErr = err as ApiErrorShape;
      const normalized = 'message' in (err as object) ? apiErr : toApiError(err);
      setError(normalized.message);
      if (normalized.errors) setFieldErrors(normalized.errors);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-ink px-4">
      <div className="w-full max-w-md">
        <div className="mb-8 flex flex-col items-center text-center">
          <Brand tone="light" showWordmark={false} className="mb-4 scale-125" />
          <h1 className="text-2xl font-semibold text-white">
            MarQira <span className="brand-accent">Pulse</span>
          </h1>
          <p className="mt-1 text-sm text-white/50">Set up your account</p>
        </div>

        <div className="card space-y-4 p-6">
          {checking && (
            <div className="flex items-center justify-center py-6 text-slate-500">
              <Spinner className="h-5 w-5" />
            </div>
          )}

          {!checking && info && !info.valid && (
            <div className="space-y-4">
              <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
                {info.error ?? 'This setup link is invalid or has expired.'}
              </div>
              <Link to="/login" className="btn-secondary w-full justify-center">
                Go to sign in
              </Link>
            </div>
          )}

          {!checking && info?.valid && done && (
            <div className="space-y-4">
              <div className="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-700" role="status">
                Password set. Redirecting you to sign in…
              </div>
            </div>
          )}

          {!checking && info?.valid && !done && (
            <form onSubmit={handleSubmit} className="space-y-4">
              <p className="text-sm text-slate-600">
                Welcome{info.name ? `, ${info.name}` : ''}. Choose a password for{' '}
                <span className="font-medium text-slate-900">{info.email}</span>.
              </p>

              {error && (
                <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
                  {error}
                </div>
              )}

              <div>
                <label htmlFor="password" className="label">
                  Password
                </label>
                <input
                  id="password"
                  type="password"
                  autoComplete="new-password"
                  required
                  minLength={12}
                  className="input"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                />
                <p className="mt-1 text-xs text-slate-500">At least 12 characters.</p>
                {fieldErrors.password?.map((m) => (
                  <p key={m} className="mt-1 text-xs text-red-600">
                    {m}
                  </p>
                ))}
              </div>

              <div>
                <label htmlFor="password_confirmation" className="label">
                  Confirm password
                </label>
                <input
                  id="password_confirmation"
                  type="password"
                  autoComplete="new-password"
                  required
                  minLength={12}
                  className="input"
                  value={confirm}
                  onChange={(e) => setConfirm(e.target.value)}
                />
              </div>

              <button type="submit" className="btn-primary w-full" disabled={submitting}>
                {submitting && <Spinner className="h-4 w-4 text-white" />}
                Set password
              </button>
            </form>
          )}
        </div>

        <p className="mt-6 text-center text-xs text-slate-500">
          Protected area. Unauthorized access is prohibited and logged.
        </p>
      </div>
    </div>
  );
}
