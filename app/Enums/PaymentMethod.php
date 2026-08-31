<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Online = 'online';
    case Card = 'card';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
