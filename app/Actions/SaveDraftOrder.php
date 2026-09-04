<?php

namespace App\Actions;

use App\Enums\SaleLineType;
use App\Exceptions\StaleDraftOrderException;
use App\Models\Item;
use App\Models\Order;
use App\Models\User;
use App\Support\SaleTotalCalculator;
use Illuminate\Support\Collection;

/**
 * Writes a bill the counter is still building.
 *
 * No stock moves and no money is recorded here. A draft is a note of what the
 * counter intends to charge; {@see CompleteOrder} is where it becomes real.
 *
 * Lines are replaced wholesale on every save rather than diffed. A bill has a
 * handful of lines, the browser always sends the full cart, and reconciling
 * a partial diff would be a far better place for a bug to hide.
 */
class SaveDraftOrder
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $rawLines
     *
     * @throws StaleDraftOrderException when someone else has saved since this copy was opened
     */
    public function __invoke(
        array $payload,
        array $rawLines,
        ?User $user,
        ?Order $order = null,
        ?int $submittedVersion = null,
    ): Order {
        $lines = $this->resolveLines($rawLines);

        return (new Order)->getConnection()->transaction(function () use ($payload, $lines, $user, $order, $submittedVersion): Order {
            if ($order === null) {
                $order = new Order($payload);
                // Who opened the bill comes from the session, never the payload.
                $order->user()->associate($user);
                $order->save();
            } else {
                $this->guardAgainstStaleSave($order, $submittedVersion);

                $order->fill($payload);
                $order->version = $order->version + 1;
                $order->save();

                $order->lines()->delete();
            }

            if ($lines !== []) {
                $order->lines()->createMany($lines);
            }

            return $order->load('lines');
        });
    }

    /**
     * Two counter staff with the same bay open would otherwise silently
     * overwrite one another, and the one who lost their work would never know.
     *
     * @throws StaleDraftOrderException
     */
    private function guardAgainstStaleSave(Order $order, ?int $submittedVersion): void
    {
        // A fresh reload inside the transaction: the check is worthless against
        // the copy this request already had in memory.
        $current = $order->newQuery()->lockForUpdate()->find($order->getKey());

        if ($current === null || $submittedVersion === null || $current->version === $submittedVersion) {
            return;
        }

        throw new StaleDraftOrderException(
            $current->load('user')->user?->name,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawLines
     * @return array<int, array<string, mixed>>
     */
    private function resolveLines(array $rawLines): array
    {
        $itemIds = array_filter(array_column($rawLines, 'item_id'));

        /** @var Collection<int, Item> $items */
        $items = $itemIds === []
            ? collect()
            : Item::whereIn('id', $itemIds)->get()->keyBy('id');

        $lines = [];

        foreach ($rawLines as $line) {
            $type = SaleLineType::from($line['type']);

            // A custom line is free text by definition and never links to stock,
            // even if the browser sent an item_id alongside it.
            $item = $type === SaleLineType::Custom ? null : $items->get($line['item_id'] ?? null);

            $lines[] = [
                'item_id' => $item?->id,
                // Trust the catalogue for the name when one is linked, so a stale
                // form field cannot rewrite what the bill will say was sold.
                'item_name' => $item?->name ?? trim((string) $line['item_name']),
                'type' => $type,
                'quantity' => (int) ($line['quantity'] ?? 1),
                'dispensed_quantity' => $item?->isMeasured()
                    ? $this->blankToNull($line['dispensed_quantity'] ?? null)
                    : null,
                'manually_charged_price' => SaleTotalCalculator::amount($line['manually_charged_price'] ?? null),
            ];
        }

        return $lines;
    }

    private function blankToNull(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }
}
