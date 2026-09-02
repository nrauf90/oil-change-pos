<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use App\Models\Contracts\TenantScoped;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission implements TenantScoped
{
    use UsesTenantConnection;
}
