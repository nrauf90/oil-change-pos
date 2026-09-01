<?php

namespace App\Enums;

enum ShopStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Failed = 'failed';
}
