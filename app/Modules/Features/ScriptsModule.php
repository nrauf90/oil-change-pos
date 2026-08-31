<?php

namespace App\Modules\Features;

use App\Enums\Permission;
use App\Modules\Module;

class ScriptsModule extends Module
{
    public function key(): string
    {
        return 'scripts';
    }

    public function title(): string
    {
        return 'Counter Scripts';
    }

    public function description(): string
    {
        return 'Check-in prompts, oil upsell talk-tracks and objection handlers for counter staff.';
    }

    public function icon(): string
    {
        return '&#128172;';
    }

    public function permissions(): array
    {
        return [Permission::ViewScripts];
    }

    public function navigation(): array
    {
        return [
            ['route' => 'scripts.index', 'pattern' => 'scripts*', 'label' => 'Scripts', 'permission' => Permission::ViewScripts, 'icon' => '&#128172;'],
        ];
    }
}
