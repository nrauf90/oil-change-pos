<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use App\Models\Contracts\TenantScoped;
use Illuminate\Database\Eloquent\Relations\Pivot;

class TenantPivot extends Pivot implements TenantScoped
{
    use UsesTenantConnection;
}
