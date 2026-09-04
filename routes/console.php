<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Support grants expire on the operator's next request, but an operator who
 * closes the tab never makes one — so the audit row would read "Active"
 * forever. Sweeping hourly keeps the audit trail an honest answer to "who has
 * access to this shop right now".
 */
Schedule::command('support:sweep-stale-access')->hourly();
