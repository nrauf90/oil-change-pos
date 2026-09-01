<?php

namespace App\Models;

/**
 * Persisted on/off state for one App\Modules\Module.
 */
class ModuleSetting extends TenantModel
{
    protected $table = 'modules';

    protected $fillable = ['key', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
