<?php

namespace App\Models;

use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

abstract class TenantModel extends Model
{
    use UsesTenantConnection;
}
