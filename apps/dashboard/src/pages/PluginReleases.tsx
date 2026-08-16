import { EmptyState } from '@/components/ui';

export default function PluginReleases() {
  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-semibold text-slate-900">Plugin Releases</h1>
        <p className="mt-1 text-sm text-slate-500">Publish and track MarQira Connector versions across your fleet.</p>
      </div>

      <div className="card p-6">
        <EmptyState
          title="Coming in Phase 7"
          description="The release registry lets you upload signed connector builds, publish changelogs, and roll updates out to your sites. Once live, the Overview “Updates available” card and each site's Updates tab will light up here."
        />
      </div>
    </div>
  );
}
