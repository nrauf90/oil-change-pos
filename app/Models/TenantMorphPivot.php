<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use App\Models\Contracts\TenantScoped;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

class TenantMorphPivot extends MorphPivot implements TenantScoped
{
    use UsesTenantConnection;
}
