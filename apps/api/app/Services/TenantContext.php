<?php

namespace App\Services;

use App\Models\Organization;
use RuntimeException;

/**
 * Holds the current tenant organization for the request lifecycle.
 *
 * Registered as a singleton. Fails closed: any attempt to read the tenant
 * before one has been explicitly established throws, so tenant-scoped data can
 * never be queried without a resolved context.
 */
class TenantContext
{
    private ?Organization $organization = null;

    public function setOrganization(Organization $org): void
    {
        $this->organization = $org;
    }

    public function organization(): Organization
    {
        if ($this->organization === null) {
            throw new RuntimeException(
                'TenantContext: no tenant organization established. ' .
                'This is a fail-closed guard — resolve the context before accessing tenant data.'
            );
        }

        return $this->organization;
    }

    public function organizationId(): int
    {
        return $this->organization()->id;
    }

    public function hasContext(): bool
    {
        return $this->organization !== null;
    }

    public function clear(): void
    {
        $this->organization = null;
    }
}
