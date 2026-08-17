<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

/**
 * Server-side authorization for site actions.
 *
 * The Owner may act on any website; a Subscriber may only act on the websites
 * they own. This is the authoritative check for view/remove/command actions —
 * controllers additionally scope list queries via Site::scopeVisibleTo, but any
 * single-site action must pass through here (see §2 / §30).
 */
class SitePolicy
{
    /**
     * Owner sees all; Subscriber sees only owned sites.
     */
    public function view(User $user, Site $site): bool
    {
        return $this->owns($user, $site);
    }

    /**
     * Remove/revoke a website.
     */
    public function delete(User $user, Site $site): bool
    {
        return $this->owns($user, $site);
    }

    /**
     * Issue a remote command (e.g. WordPress core update) to a website.
     */
    public function command(User $user, Site $site): bool
    {
        return $this->owns($user, $site);
    }

    private function owns(User $user, Site $site): bool
    {
        if ($user->isOwner()) {
            return true;
        }

        return $site->owner_user_id !== null
            && (int) $site->owner_user_id === (int) $user->id;
    }
}
