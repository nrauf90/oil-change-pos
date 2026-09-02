<?php

namespace App\Models\Concerns;

use App\Models\TenantMorphPivot;
use App\Models\TenantPivot;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use LogicException;

trait UsesTenantConnection
{
    private ?string $originatingShopId = null;

    public function initializeUsesTenantConnection(): void
    {
        if (app()->bound(TenantContext::class)) {
            $context = resolve(TenantContext::class);

            if ($context->initialized()) {
                $this->originatingShopId = $context->id();
            }
        }
    }

    public function getConnectionName(): string
    {
        $this->assertCurrentTenantOwnsModel();

        return 'tenant';
    }

    public function setConnection($name)
    {
        if ($name !== 'tenant') {
            throw new LogicException('Operational models cannot override the tenant connection.');
        }

        $this->assertCurrentTenantOwnsModel();

        return parent::setConnection('tenant');
    }

    public function getRelationValue($key)
    {
        $this->assertCurrentTenantOwnsModel();

        return parent::getRelationValue($key);
    }

    protected function newRelatedInstance($class)
    {
        $this->assertCurrentTenantOwnsModel();

        return parent::newRelatedInstance($class);
    }

    protected function newRelatedThroughInstance($class)
    {
        $this->assertCurrentTenantOwnsModel();

        return parent::newRelatedThroughInstance($class);
    }

    public function replicate(?array $except = null)
    {
        $this->assertCurrentTenantOwnsModel();

        return parent::replicate($except);
    }

    public function replicateQuietly(?array $except = null)
    {
        $this->assertCurrentTenantOwnsModel();

        return parent::replicateQuietly($except);
    }

    protected function newBelongsToMany(
        Builder $query,
        Model $parent,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
    ): BelongsToMany {
        $this->assertCurrentTenantOwnsModel();

        return parent::newBelongsToMany(
            $query,
            $parent,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName,
        )->using(TenantPivot::class);
    }

    protected function newMorphToMany(
        Builder $query,
        Model $parent,
        $name,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
        $inverse = false,
    ): MorphToMany {
        $this->assertCurrentTenantOwnsModel();

        return parent::newMorphToMany(
            $query,
            $parent,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName,
            $inverse,
        )->using(TenantMorphPivot::class);
    }

    private function assertCurrentTenantOwnsModel(): void
    {
        $currentShopId = resolve(TenantContext::class)->id();

        if ($this->originatingShopId === null) {
            $this->originatingShopId = $currentShopId;

            return;
        }

        if (! hash_equals($this->originatingShopId, $currentShopId)) {
            throw new LogicException('Operational models cannot cross tenant contexts.');
        }
    }
}
