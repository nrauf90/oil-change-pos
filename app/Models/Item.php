<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    /** A new item is counted in pieces until the shop says otherwise. */
    protected $attributes = ['unit_of_measure' => 'piece'];

    protected $fillable = [
        'name', 'type', 'is_universal', 'unit_cost', 'unit_of_measure', 'pack_label',
        'units_per_pack', 'measure_per_unit', 'stock_level', 'low_stock_alert', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ItemType::class,
            'is_universal' => 'boolean',
            'unit_of_measure' => UnitOfMeasure::class,
            'units_per_pack' => 'integer',
            'measure_per_unit' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'stock_level' => 'decimal:3',
            'low_stock_alert' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<SaleItem, $this> */
    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /** @return HasMany<ItemVehicleCompatibility, $this> */
    public function vehicleCompatibilities(): HasMany
    {
        return $this->hasMany(ItemVehicleCompatibility::class);
    }

    /** Oil and gas are drawn by the litre or kilo; filters come off the shelf whole. */
    public function isMeasured(): bool
    {
        return $this->unit_of_measure->isMeasured();
    }

    /**
     * How much one purchased pack holds, in the item's own unit — a carton of
     * 4 bottles at 4 litres is 16 litres. Null when the shop has not described
     * its packaging.
     */
    public function packContains(): ?string
    {
        if ($this->units_per_pack === null || $this->measure_per_unit === null) {
            return null;
        }

        return $this->formatMeasure((float) $this->units_per_pack * (float) $this->measure_per_unit);
    }

    /** Book in whole packs: two cartons of oil, one cylinder of gas. */
    public function receivePacks(float $packs): void
    {
        $contains = $this->packContains();

        if ($contains === null) {
            return;
        }

        $this->receiveMeasure((string) ($packs * (float) $contains));
    }

    /** Book in a loose amount, or correct a count, in the item's own unit. */
    public function receiveMeasure(string $amount): void
    {
        // Relative UPDATE, so two people booking stock in at once both land.
        $this->newQuery()->whereKey($this->getKey())->increment('stock_level', (float) $amount);
    }

    /** "32.000 L", "13.000 kg", or plain "7" for pieces. */
    public function stockLabel(): ?string
    {
        if ($this->stock_level === null) {
            return null;
        }

        $amount = $this->formatMeasure((float) $this->stock_level);

        return trim($amount.' '.$this->unit_of_measure->abbreviation());
    }

    private function formatMeasure(float $amount): string
    {
        return number_format($amount, $this->unit_of_measure->precision(), '.', '');
    }

    /**
     * True only when this item is both stock-tracked and has a threshold to
     * fall below. An item with no threshold has nothing to be "low" against.
     */
    public function isLowOnStock(): bool
    {
        return $this->stock_level !== null
            && $this->low_stock_alert !== null
            && $this->stock_level <= $this->low_stock_alert;
    }

    /** @param Builder<Item> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<Item> $query */
    public function scopeLowStock(Builder $query): void
    {
        $query->whereNotNull('stock_level')
            ->whereNotNull('low_stock_alert')
            ->whereColumn('stock_level', '<=', 'low_stock_alert');
    }

    /** @param Builder<Item> $query */
    public function scopeOfType(Builder $query, ?string $type): void
    {
        $query->when(
            in_array($type, ItemType::values(), true),
            fn (Builder $q) => $q->where('type', $type)
        );
    }

    /**
     * Restrict the list to active or inactive items. Anything else means "both",
     * which is what the inventory screen shows by default.
     *
     * @param  Builder<Item>  $query
     */
    public function scopeStatus(Builder $query, ?string $status): void
    {
        $query->when($status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $q) => $q->where('is_active', false));
    }

    /**
     * Name lookup.
     *
     * `%` and `_` are escaped, so a counter typing "50%" finds the item named
     * "50% Synthetic Blend" instead of listing the entire catalogue.
     *
     * @param  Builder<Item>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when(filled($term), function (Builder $q) use ($term): void {
            $q->whereRaw(
                $q->getQuery()->getGrammar()->wrap('name').' like ? escape ?',
                ['%'.addcslashes((string) $term, '%_\\').'%', '\\'],
            );
        });
    }

    /** @param Builder<Item> $query */
    public function scopeCompatibleWith(Builder $query, ?int $makeId, ?int $modelId, ?int $year): void
    {
        if ($makeId === null && $modelId === null && $year === null) {
            return;
        }

        $query->where(function (Builder $items) use ($makeId, $modelId, $year): void {
            $items->where('is_universal', true)
                ->orWhereHas('vehicleCompatibilities', function (Builder $compatibilities) use ($makeId, $modelId, $year): void {
                    $compatibilities
                        ->when($makeId !== null, fn (Builder $matching) => $matching->whereHas(
                            'vehicleModel',
                            fn (Builder $models) => $models->where('vehicle_make_id', $makeId),
                        ))
                        ->when($modelId !== null, fn (Builder $matching) => $matching->where('vehicle_model_id', $modelId))
                        ->when($year !== null, fn (Builder $matching) => $matching
                            ->where(fn (Builder $bounds) => $bounds->whereNull('year_from')->orWhere('year_from', '<=', $year))
                            ->where(fn (Builder $bounds) => $bounds->whereNull('year_to')->orWhere('year_to', '>=', $year)));
                });
        });
    }
}
