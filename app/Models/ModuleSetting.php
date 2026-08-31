<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persisted on/off state for one App\Modules\Module.
 */
class ModuleSetting extends Model
{
    protected $table = 'modules';

    protected $fillable = ['key', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
