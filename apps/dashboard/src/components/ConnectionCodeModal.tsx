import { useState } from 'react';
import { api, toApiError } from '@/lib/api';
import Modal, { CopyableSecret } from './Modal';
import { Spinner } from './ui';

interface GeneratedCode {
  token: string;
  expires_in_minutes: number;
}

export default function ConnectionCodeModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [code, setCode] = useState<GeneratedCode | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const generate = async () => {
    setError(null);
    setLoading(true);
    try {
      const res = await api.post<GeneratedCode>('/api/dashboard/enrollment-tokens');
      setCode(res.data);
    } catch (err) {
      setError(toApiError(err).message);
    } finally {
      setLoading(false);
    }
  };

  const close = () => {
    setCode(null);
    setError(null);
    onClose();
  };

  return (
    <Modal open={open} title="Connect a website" onClose={close}>
      {!code ? (
        <div className="space-y-4">
          <p className="text-sm text-slate-600">
            Generate a one-time connection code, then paste it into the MarQira Connector plugin on the WordPress site you
            want to add. The code is valid for a limited time and can only be used once.
          </p>
          {error && <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}
          <div className="flex justify-end gap-2">
            <button className="btn-secondary" onClick={close}>
              Cancel
            </button>
            <button className="btn-primary" onClick={generate} disabled={loading}>
              {loading && <Spinner className="h-4 w-4 text-white" />}
              Generate code
            </button>
          </div>
        </div>
      ) : (
        <div className="space-y-4">
          <p className="text-sm text-slate-600">
            Copy this connection code and enter it in the plugin. It is shown <strong>only once</strong> and expires in{' '}
            {code.expires_in_minutes} minutes.
          </p>
          <CopyableSecret value={code.token} note="Anyone with this code can enrol a site into your organization until it expires. Do not share it publicly." />
          <ol className="list-decimal space-y-1 pl-5 text-xs text-slate-500">
            <li>In WordPress admin, open <strong>MarQira Connector → Connect</strong>.</li>
            <li>Paste the code and save.</li>
            <li>The site appears here within a minute of its first heartbeat.</li>
          </ol>
          <div className="flex justify-end">
            <button className="btn-primary" onClick={close}>
              Done
            </button>
          </div>
        </div>
      )}
    </Modal>
  );
}
