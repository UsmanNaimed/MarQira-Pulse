import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AccountUser } from '@/types';

interface AccountSelectorProps {
  /** Currently selected account uuid, or '' for "All Users". */
  value: string;
  onChange: (accountUuid: string) => void;
}

/**
 * Owner-only "Viewing websites for" account picker (§14/§15).
 *
 * Lets the platform Owner narrow every scoped view (overview cards, websites
 * list) to a single Subscriber's websites, or "All Users" for the aggregate.
 * The chosen uuid is passed to the API as `?account=<uuid>`; the server ignores
 * it for non-owners, so this is purely a convenience for the Owner and never a
 * security boundary. Render this only when `user.is_owner`.
 */
export default function AccountSelector({ value, onChange }: AccountSelectorProps) {
  const { data, isLoading } = useQuery({
    queryKey: ['accounts', 'selector'],
    queryFn: async () => {
      const res = await api.get<{ data: AccountUser[] }>('/api/dashboard/accounts');
      return res.data.data;
    },
    staleTime: 60_000,
  });

  const accounts = data ?? [];

  return (
    <label className="inline-flex items-center gap-2 rounded-xl border border-line bg-white px-3 py-1.5 shadow-card">
      <span className="whitespace-nowrap text-xs font-medium text-ink-muted">Viewing websites for</span>
      <select
        value={value}
        onChange={(e) => onChange(e.target.value)}
        disabled={isLoading}
        className="max-w-[12rem] cursor-pointer border-0 bg-transparent p-0 pr-6 text-sm font-semibold text-ink focus:outline-none focus:ring-0"
      >
        <option value="">All Users</option>
        {accounts.map((a) => (
          <option key={a.uuid} value={a.uuid}>
            {a.name} ({a.site_count})
          </option>
        ))}
      </select>
    </label>
  );
}
