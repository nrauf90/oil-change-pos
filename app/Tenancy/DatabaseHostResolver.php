<?php

namespace App\Tenancy;

interface DatabaseHostResolver
{
    /** @return list<string> */
    public function resolve(string $host): array;
}
