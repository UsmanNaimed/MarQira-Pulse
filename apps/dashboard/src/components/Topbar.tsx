import { useState, type FormEvent } from 'react';
import { useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuth } from '@/context/AuthContext';
import { useTheme } from '@/context/ThemeContext';
import AccountSelector from '@/components/AccountSelector';
import type { OverviewResponse } from '@/types';

/**
 * Sticky, blurred application top bar (design redesign):
 *  - mobile menu button
 *  - global search (jumps to the Websites list filtered by the term)
 *  - owner-only account selector, shown on the dashboard views only
 *  - notifications bell (ping reflects REAL "needs attention"/updates count)
 *  - light/dark theme toggle
 */
export default function Topbar({ onOpenMenu }: { onOpenMenu: () => void }) {
  const { user } = useAuth();
  const { theme, toggleTheme } = useTheme();
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams, setSearchParams] = useSearchParams();
  const [term, setTerm] = useState('');

  // The account picker only applies to the scoped dashboard views.
  const onDashboardView = location.pathname === '/' || location.pathname.startsWith('/websites');
  const showAccount = Boolean(user?.is_owner) && onDashboardView;
  const account = searchParams.get('account') ?? '';

  // Ping the bell only when there is something real to look at. Cheap + cached.
  const { data: overview } = useQuery({
    queryKey: ['overview', account],
    queryFn: async () => {
      const res = await api.get<OverviewResponse>('/api/dashboard/overview', {
        params: account ? { account } : undefined,
      });
      return res.data;
    },
    staleTime: 20_000,
  });
  const attention = overview ? overview.cards.needs_attention + overview.cards.offline + overview.cards.updates_available : 0;

  const submitSearch = (e: FormEvent) => {
    e.preventDefault();
    const q = term.trim();
    navigate(q ? `/websites?q=${encodeURIComponent(q)}` : '/websites');
  };

  const setAccount = (uuid: string) => {
    const next = new URLSearchParams(searchParams);
    if (uuid) next.set('account', uuid);
    else next.delete('account');
    setSearchParams(next, { replace: true });
  };

  return (
    <header className="sticky top-0 z-30 flex items-center gap-4 border-b border-line bg-surface-soft/80 px-4 py-3 backdrop-blur-md sm:px-6 lg:px-7">
      <button
        onClick={onOpenMenu}
        className="text-2xl leading-none text-ink lg:hidden"
        aria-label="Open menu"
      >
        ☰
      </button>

      <form onSubmit={submitSearch} className="flex max-w-[420px] flex-1 items-center gap-2 rounded-pill border border-line bg-surface px-3 py-2 text-ink-muted focus-within:border-brand-600 focus-within:ring-4 focus-within:ring-brand-600/10">
        <svg className="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2}>
          <circle cx="11" cy="11" r="7" />
          <path d="m20 20-3-3" />
        </svg>
        <input
          value={term}
          onChange={(e) => setTerm(e.target.value)}
          placeholder="Search sites, users, tokens…"
          className="w-full border-none bg-transparent text-sm text-ink outline-none placeholder:text-ink-muted"
          aria-label="Search"
        />
      </form>

      <div className="ml-auto flex items-center gap-2.5">
        {showAccount && (
          <div className="hidden sm:block">
            <AccountSelector value={account} onChange={setAccount} />
          </div>
        )}

        <button
          onClick={() => navigate('/')}
          className="relative grid h-[38px] w-[38px] place-items-center rounded-[10px] border border-line bg-surface text-ink-body transition hover:border-line-strong hover:text-ink"
          aria-label="Notifications"
          title={attention > 0 ? `${attention} item(s) need attention` : 'No alerts'}
        >
          {attention > 0 && (
            <span className="absolute right-2 top-2 h-[7px] w-[7px] rounded-full bg-danger ring-2 ring-surface" />
          )}
          <svg className="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8}>
            <path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0" />
          </svg>
        </button>

        <button
          onClick={toggleTheme}
          className="grid h-[38px] w-[38px] place-items-center rounded-[10px] border border-line bg-surface text-ink-body transition hover:border-line-strong hover:text-ink"
          aria-label={theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
        >
          {theme === 'dark' ? (
            <svg className="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8}>
              <circle cx="12" cy="12" r="4" />
              <path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4m11.4-11.4 1.4-1.4" strokeLinecap="round" />
            </svg>
          ) : (
            <svg className="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8}>
              <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
            </svg>
          )}
        </button>
      </div>
    </header>
  );
}
