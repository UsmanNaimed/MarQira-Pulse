import type { ReactNode } from 'react';

export default function Modal({
  open,
  title,
  onClose,
  children,
  maxWidth = 'max-w-lg',
}: {
  open: boolean;
  title: string;
  onClose: () => void;
  children: ReactNode;
  maxWidth?: string;
}) {
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center px-4">
      <div className="absolute inset-0 bg-slate-900/50" onClick={onClose} />
      <div className={`relative w-full ${maxWidth} rounded-xl bg-white shadow-xl`}>
        <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
          <h2 className="text-base font-semibold text-slate-900">{title}</h2>
          <button onClick={onClose} className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close">
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <div className="px-5 py-5">{children}</div>
      </div>
    </div>
  );
}

export function CopyableSecret({ value, note }: { value: string; note?: string }) {
  const copy = () => {
    navigator.clipboard?.writeText(value).catch(() => {});
  };
  return (
    <div>
      <div className="flex items-stretch gap-2">
        <code className="flex-1 overflow-x-auto rounded-lg bg-slate-100 px-3 py-2 font-mono text-sm text-slate-800">{value}</code>
        <button className="btn-secondary shrink-0" onClick={copy}>
          Copy
        </button>
      </div>
      {note && <p className="mt-2 text-xs text-amber-600">{note}</p>}
    </div>
  );
}
