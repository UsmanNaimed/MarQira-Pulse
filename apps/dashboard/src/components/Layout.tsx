import { useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import { useAuth } from '@/context/AuthContext';
import Brand from '@/components/Brand';
import Topbar from '@/components/Topbar';

interface NavItem {
  to: string;
  label: string;
  icon: JSX.Element;
  ownerOnly?: boolean;
}

interface NavGroup {
  label: string;
  items: NavItem[];
}

const NAV_GROUPS: NavGroup[] = [
  {
    label: 'Monitor',
    items: [
      {
        to: '/',
        label: 'Overview',
        icon: (
          <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 018.25 20.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25A2.25 2.25 0 0113.5 8.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
        ),
      },
      {
        to: '/websites',
        label: 'Websites',
        icon: (
          <path strokeLinecap="round" strokeLinejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m-8.716 6h17.432M3.284 15h17.432" />
        ),
      },
      {
        to: '/plugin-releases',
        label: 'Plugin Releases',
        ownerOnly: true,
        icon: (
          <path strokeLinecap="round" strokeLinejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
        ),
      },
    ],
  },
  {
    label: 'Manage',
    items: [
      {
        to: '/wp-users/add-to-websites',
        label: 'Add User to Sites',
        icon: (
          <path strokeLinecap="round" strokeLinejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM3 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 019.374 21c-2.331 0-4.512-.645-6.374-1.766z" />
        ),
      },
      {
        to: '/users',
        label: 'Users',
        ownerOnly: true,
        icon: (
          <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
        ),
      },
      {
        to: '/api-tokens',
        label: 'API Tokens',
        icon: (
          <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H9v1.5H7.5v1.5H6v1.5H3.75a.75.75 0 01-.75-.75v-2.69c0-.199.079-.39.22-.53l6.638-6.639c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
        ),
      },
      {
        to: '/settings',
        label: 'Settings',
        icon: (
          <>
            <path strokeLinecap="round" strokeLinejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
            <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          </>
        ),
      },
    ],
  },
];

function Icon({ children }: { children: JSX.Element }) {
  return (
    <svg className="h-[19px] w-[19px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.7}>
      {children}
    </svg>
  );
}

/** Two-letter initials for the user avatar tile. */
function initials(name?: string): string {
  if (!name) return 'MP';
  return name
    .split(/\s+/)
    .map((w) => w[0])
    .filter(Boolean)
    .slice(0, 2)
    .join('')
    .toUpperCase();
}

export default function Layout() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [mobileOpen, setMobileOpen] = useState(false);

  const handleLogout = async () => {
    await logout();
    navigate('/login', { replace: true });
  };

  const sidebar = (
    <div className="flex h-full flex-col">
      <div className="px-5 py-[22px]">
        <Brand tone="light" caption="WordPress Command Center" />
      </div>
      <nav className="flex-1 space-y-0.5 overflow-y-auto px-3 py-1.5">
        {NAV_GROUPS.map((group) => {
          const items = group.items.filter((item) => !item.ownerOnly || user?.is_owner);
          if (items.length === 0) return null;
          return (
            <div key={group.label}>
              <p className="px-3 pb-1.5 pt-4 text-[10px] font-semibold uppercase tracking-[0.13em] text-white/45">
                {group.label}
              </p>
              {items.map((item) => (
                <NavLink
                  key={item.to}
                  to={item.to}
                  end={item.to === '/'}
                  onClick={() => setMobileOpen(false)}
                  className={({ isActive }) =>
                    clsx(
                      'flex items-center gap-3 rounded-pill px-3 py-2.5 text-sm font-medium transition-all',
                      isActive
                        ? 'bg-brand-gradient text-white shadow-brand'
                        : 'text-white/70 hover:bg-white/10 hover:text-white',
                    )
                  }
                >
                  <Icon>{item.icon}</Icon>
                  {item.label}
                </NavLink>
              ))}
            </div>
          );
        })}
      </nav>
      <div className="border-t border-white/10 p-4">
        <div className="flex items-center gap-3">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-[10px] bg-brand-gradient font-disp text-sm font-bold text-white">
            {initials(user?.name)}
          </span>
          <div className="min-w-0">
            <p className="truncate text-[13.5px] font-semibold text-white">{user?.name}</p>
            <p className="truncate text-[11.5px] text-white/45">
              {user?.organization?.name ?? 'No organization'}
              {user?.is_owner ? ' · Owner' : ''}
            </p>
          </div>
        </div>
        <button
          onClick={handleLogout}
          className="mt-3 w-full rounded-[10px] border border-white/10 px-3 py-1.5 text-xs font-medium text-white/80 transition hover:bg-white/10 hover:text-white"
        >
          Sign out
        </button>
        <p className="mt-3 text-center text-[11px] font-medium text-white/45">
          Powered by <span className="brand-accent font-disp font-bold">MarQira</span>
          <sup className="text-[8px]">™</sup>
        </p>
      </div>
    </div>
  );

  return (
    <div className="min-h-screen lg:flex">
      {/* Desktop sidebar */}
      <aside className="sticky top-0 hidden h-screen w-[250px] shrink-0 bg-nav lg:block">{sidebar}</aside>

      {/* Mobile sidebar */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={() => setMobileOpen(false)} />
          <aside className="absolute left-0 top-0 h-full w-[250px] bg-nav shadow-2xl">{sidebar}</aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar onOpenMenu={() => setMobileOpen(true)} />
        <main className="flex-1 px-4 py-6 sm:px-6 lg:px-7">
          <div className="mx-auto max-w-[1200px]">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  );
}
