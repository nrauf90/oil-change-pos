<?php

namespace App\Tenancy;

interface DatabaseHostResolver
{
    /** @return list<string> */
    public function resolve(#[\SensitiveParameter] string $host): array;
}
