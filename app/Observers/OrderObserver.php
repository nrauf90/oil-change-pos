<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Order;

/**
 * A draft is not financial history, but it is work somebody did — so opening,
 * completing and discarding one are all worth a line in the log.
 */
class OrderObserver
{
    public function created(Order $order): void
    {
        ActivityLog::record(
            action: 'draft_sale.created',
            description: sprintf('Opened draft bill %s', $order->displayLabel()),
            subject: $order,
            properties: [
                'label' => $order->label,
                'vehicle_plate' => $order->vehicle_plate,
                'opened_by_id' => $order->user_id,
            ],
        );
    }

    public function updated(Order $order): void
    {
        // Completing writes its own, clearer line below; this one would only
        // repeat it in the language of changed columns.
        if ($order->wasChanged('status')) {
            ActivityLog::record(
                action: 'draft_sale.completed',
                description: sprintf('Completed draft bill %s', $order->displayLabel()),
                subject: $order,
                properties: [
                    'label' => $order->label,
                    'sale_id' => $order->sale_id,
                ],
            );

            return;
        }

        $changed = ActivityLog::changes($order);

        if ($changed === []) {
            return;
        }

        ActivityLog::record(
            action: 'draft_sale.updated',
            description: sprintf(
                'Amended draft bill %s (%s)',
                $order->displayLabel(),
                implode(', ', array_keys($changed)),
            ),
            subject: $order,
            properties: ['changed' => $changed],
        );
    }

    public function deleted(Order $order): void
    {
        ActivityLog::record(
            action: 'draft_sale.deleted',
            description: sprintf('Discarded draft bill %s', $order->displayLabel()),
            subject: $order,
            properties: [
                'label' => $order->label,
                'vehicle_plate' => $order->vehicle_plate,
                'opened_by_id' => $order->user_id,
            ],
        );
    }
}
