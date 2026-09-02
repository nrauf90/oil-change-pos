<?php

namespace App\Tenancy;

use App\Models\Central\Shop;
use Illuminate\Http\Request;

interface TenantResolver
{
    public function resolve(Request $request): ?Shop;
}
