<?php

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function __construct(
        private TenantContext $context
    ) {
    }

    public function apply(Builder $builder, Model $model): void
    {
        if (! $this->context->has()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('tenant_id'),
            $this->context->id()
        );
    }
}
