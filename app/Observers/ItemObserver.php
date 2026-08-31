<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Item;

class ItemObserver
{
    public function created(Item $item): void
    {
        ActivityLog::record(
            action: 'item.created',
            description: sprintf('Added %s to inventory as a %s', $item->name, $item->type?->label() ?? 'item'),
            subject: $item,
            properties: [
                'name' => $item->name,
                'type' => $item->type?->value,
                'unit_cost' => $item->unit_cost,
                'stock_level' => $item->stock_level,
                'low_stock_alert' => $item->low_stock_alert,
            ],
        );
    }

    public function updated(Item $item): void
    {
        $changed = ActivityLog::changes($item);

        // Stock deduction at checkout goes through a relative UPDATE on the
        // query builder, which fires no model event, so this only ever sees a
        // human editing the catalogue. An edit that changed nothing — a form
        // re-submitted untouched — is not news.
        if ($changed === []) {
            return;
        }

        ActivityLog::record(
            action: 'item.updated',
            description: sprintf('Edited %s (%s)', $item->name, implode(', ', array_keys($changed))),
            subject: $item,
            properties: ['name' => $item->name, 'changed' => $changed],
        );
    }

    public function deleted(Item $item): void
    {
        ActivityLog::record(
            action: 'item.deleted',
            description: sprintf('Removed %s from inventory', $item->name),
            subject: $item,
            properties: [
                'name' => $item->name,
                'type' => $item->type?->value,
                'unit_cost' => $item->unit_cost,
                'stock_level' => $item->stock_level,
                'low_stock_alert' => $item->low_stock_alert,
                'is_active' => $item->is_active,
            ],
        );
    }
}
