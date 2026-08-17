<?php

namespace App\Domain\Tenancy;

use App\Models\Tenant;
use LogicException;

class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): Tenant
    {
        if ($this->tenant === null) {
            throw new LogicException('Tenant context has not been initialized.');
        }

        return $this->tenant;
    }

    public function id(): int
    {
        return $this->get()->id;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }
}
