<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;

abstract class CentralModel extends Model
{
    protected $connection = 'central';
}
