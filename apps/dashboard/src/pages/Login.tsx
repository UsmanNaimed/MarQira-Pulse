import { useState, type FormEvent } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { useAuth } from '@/context/AuthContext';
import type { ApiErrorShape } from '@/lib/api';
import { Spinner } from '@/components/ui';
import './Login.css';

export default function Login() {
  const { user, loading, login } = useAuth();
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [rememberMe, setRememberMe] = useState(true);
  const [showPassword, setShowPassword] = useState(false);
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
    <div className="wpc-login-page">
      {/* Floating site cards */}
      <div className="wpc-floaters">
        <div className="wpc-floater wpc-f1">
          <span className="wpc-dot wpc-dot-ok"></span>
          <div>
            <b>northridge-dental.com</b>
            <br />
            <span>WP 6.7 · healthy</span>
          </div>
        </div>
        <div className="wpc-floater wpc-f2">
          <span className="wpc-dot wpc-dot-warn"></span>
          <div>
            <b>theharborco.com</b>
            <br />
            <span>3 updates queued</span>
          </div>
        </div>
        <div className="wpc-floater wpc-f3">
          <span className="wpc-dot wpc-dot-ok"></span>
          <div>
            <b>bellamyfitness.io</b>
            <br />
            <span>99.99% uptime</span>
          </div>
        </div>
        <div className="wpc-floater wpc-f4">
          <span className="wpc-dot wpc-dot-ok"></span>
          <div>
            <b>studio-arclight.com</b>
            <br />
            <span>WP 6.7 · healthy</span>
          </div>
        </div>
      </div>

      <div className="wpc-stage">
        {/* Brand */}
        <div className="wpc-brand">
          <div className="wpc-brand-mark">
            <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
              <path
                d="M45.5 18.8A20 20 0 1 0 45.5 45.2"
                stroke="#fff"
                strokeWidth="7"
                strokeLinecap="round"
              />
              <circle cx="32" cy="32" r="5.5" fill="#fff" />
              <path d="M32 26.5V17.5M37.5 32H47M32 37.5V46.5" stroke="#fff" strokeWidth="4.2" strokeLinecap="round" />
              <circle cx="32" cy="15" r="2.7" fill="#fff" />
              <circle cx="49.5" cy="32" r="2.7" fill="#fff" />
              <circle cx="32" cy="49" r="2.7" fill="#fff" />
            </svg>
          </div>
          <div className="wpc-brand-word">
            WP<span>Centrify</span>
          </div>
        </div>

        {/* Hero copy */}
        <div className="wpc-hero-copy">
          <h1>One Dashboard for All Your WordPress Management.</h1>
          <p>Manage, secure, monitor and automate all your WordPress sites from one place.</p>
        </div>

        <div className="wpc-login-wrap">
          {/* Eyebrow badge */}
          <div className="wpc-eyebrow">
            <span className="wpc-live-dot"></span> All monitored sites operational
          </div>

          {/* Login card */}
          <div className="wpc-card">
            <h2>Welcome back</h2>
            <p className="wpc-sub">Log in to your WPCentrify command center.</p>

            <form onSubmit={handleSubmit}>
              {error && (
                <div className="wpc-error-banner" role="alert">
                  {error}
                </div>
              )}

              <div className="wpc-field">
                <label htmlFor="user">Username or email</label>
                <div className="wpc-input-shell">
                  <input
                    id="user"
                    type="text"
                    placeholder="you@agency.com"
                    autoComplete="username"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                  />
                </div>
                {fieldErrors.email?.map((m) => (
                  <p key={m} className="wpc-field-error">
                    {m}
                  </p>
                ))}
              </div>

              <div className="wpc-field">
                <label htmlFor="pass">Password</label>
                <div className="wpc-input-shell">
                  <input
                    id="pass"
                    type={showPassword ? 'text' : 'password'}
                    placeholder="••••"
                    autoComplete="current-password"
                    required
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                  />
                  <button
                    type="button"
                    className="wpc-toggle-vis"
                    onClick={() => setShowPassword(!showPassword)}
                  >
                    {showPassword ? 'HIDE' : 'SHOW'}
                  </button>
                </div>
                {fieldErrors.password?.map((m) => (
                  <p key={m} className="wpc-field-error">
                    {m}
                  </p>
                ))}
              </div>

              <div className="wpc-row-between">
                <label className="wpc-remember">
                  <input
                    type="checkbox"
                    checked={rememberMe}
                    onChange={(e) => setRememberMe(e.target.checked)}
                  />
                  Remember me
                </label>
                <a href="#" className="wpc-link">
                  Forgot password?
                </a>
              </div>

              <button type="submit" className="wpc-btn-primary" disabled={submitting}>
                {submitting ? (
                  <>
                    <Spinner className="h-4 w-4 text-white" />
                    Logging in...
                  </>
                ) : (
                  <>
                    Log in
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                      <path
                        d="M5 12h14M13 6l6 6-6 6"
                        stroke="currentColor"
                        strokeWidth="2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                    </svg>
                  </>
                )}
              </button>
            </form>

            <div className="wpc-card-foot">
              <span className="wpc-lock">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none">
                  <rect x="3" y="11" width="18" height="10" rx="2" stroke="currentColor" strokeWidth="1.8" />
                  <path d="M7 11V8a5 5 0 0110 0v3" stroke="currentColor" strokeWidth="1.8" />
                </svg>
              </span>
              Product by CentriQor —{' '}
              <a href="https://centriqor.com" target="_blank" rel="noopener noreferrer">
                learn more
              </a>
            </div>
          </div>

          {/* Stats line */}
          <div className="wpc-stat-line">
            <span>
              <b>1,240+</b> sites
            </span>
            <span>
              <b>24/7</b> checks
            </span>
            <span>
              <b>99.98%</b> uptime
            </span>
          </div>
        </div>
      </div>
    </div>
  );
}
