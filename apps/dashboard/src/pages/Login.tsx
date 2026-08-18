import { useState, type FormEvent } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { useAuth } from '@/context/AuthContext';
import type { ApiErrorShape } from '@/lib/api';
import { Spinner } from '@/components/ui';
import Brand from '@/components/Brand';

export default function Login() {
  const { user, loading, login } = useAuth();
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  if (!loading && user) {
    return <Navigate to="/" replace />;
  }

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setFieldErrors({});
    setSubmitting(true);
    try {
      await login(email, password);
      navigate('/', { replace: true });
    } catch (err) {
      const apiErr = err as ApiErrorShape;
      setError(apiErr.message);
      if (apiErr.errors) setFieldErrors(apiErr.errors);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-nav px-4">
      <div className="w-full max-w-md">
        <div className="mb-8 flex flex-col items-center text-center">
          <Brand tone="light" showWordmark={false} className="mb-4 scale-125" />
          <h1 className="text-2xl font-semibold text-white">
            MarQira <span className="brand-accent">Pulse</span>
          </h1>
          <p className="mt-1 text-sm text-white/50">Sign in to your dashboard</p>
        </div>

        <form onSubmit={handleSubmit} className="card space-y-4 p-6">
          {error && (
            <div className="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger" role="alert">
              {error}
            </div>
          )}

          <div>
            <label htmlFor="email" className="label">
              Email
            </label>
            <input
              id="email"
              type="email"
              autoComplete="username"
              required
              className="input"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
            {fieldErrors.email?.map((m) => (
              <p key={m} className="mt-1 text-xs text-danger">
                {m}
              </p>
            ))}
          </div>

          <div>
            <label htmlFor="password" className="label">
              Password
            </label>
            <input
              id="password"
              type="password"
              autoComplete="current-password"
              required
              className="input"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
            {fieldErrors.password?.map((m) => (
              <p key={m} className="mt-1 text-xs text-danger">
                {m}
              </p>
            ))}
          </div>

          <button type="submit" className="btn-primary w-full" disabled={submitting}>
            {submitting && <Spinner className="h-4 w-4 text-white" />}
            Sign in
          </button>
        </form>

        <p className="mt-6 text-center text-xs text-ink-muted">
          Protected area. Unauthorized access is prohibited and logged.
        </p>
      </div>
    </div>
  );
}
