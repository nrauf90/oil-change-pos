<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use App\Models\Contracts\TenantScoped;
use Illuminate\Database\Eloquent\Model;

abstract class TenantModel extends Model implements TenantScoped
{
    use UsesTenantConnection;
}
