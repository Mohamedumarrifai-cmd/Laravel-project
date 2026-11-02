<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Branch;

class TenantContext
{
    protected ?Tenant $tenant = null;
    protected ?Branch $branch = null;

    public function setTenant(?Tenant $tenant): self
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getTenantId(): ?int
    {
        return $this->tenant?->id;
    }

    public function setBranch(?Branch $branch): self
    {
        $this->branch = $branch;
        return $this;
    }

    public function getBranch(): ?Branch
    {
        return $this->branch;
    }

    public function getBranchId(): ?int
    {
        return $this->branch?->id;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->branch = null;
    }
}
