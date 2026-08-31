<?php

namespace App\Models;

use App\Enums\InspectionPoint;
use App\Enums\InspectionStatus;
use App\Support\ServiceHistory;
use Database\Factories\InspectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A multi-point condition report on one vehicle.
 *
 * `inspected_by` is deliberately absent from $fillable: it is stamped from the
 * session in the controller, so a forged user_id in the request body can never
 * put someone else's name on a report.
 */
class Inspection extends Model
{
    /** @use HasFactory<InspectionFactory> */
    use HasFactory;

    protected $fillable = [
        'sale_id', 'customer_name', 'phone', 'vehicle_plate',
        'vehicle_model', 'mileage', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mileage' => 'integer',
            'inspected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Inspection $inspection): void {
            $inspection->inspected_at ??= now();
        });
    }

    /** @return HasMany<InspectionItem, $this> */
    public function points(): HasMany
    {
        return $this->hasMany(InspectionItem::class)->orderBy('position')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Replace the whole set of check-points in one go.
     *
     * An inspection is a snapshot, so an edit rewrites the sheet rather than
     * merging two walk-arounds that happened at different mileages.
     *
     * @param  array<string, array{status: string, note?: string|null}>  $points
     */
    public function syncPoints(array $points): void
    {
        $rows = [];

        foreach ($points as $key => $values) {
            $point = InspectionPoint::tryFrom((string) $key);
            $status = InspectionStatus::tryFrom((string) ($values['status'] ?? ''));

            // Validation has already rejected these; belt and braces so a bad
            // key can never reach the table via another caller.
            if ($point === null || $status === null) {
                continue;
            }

            $rows[] = [
                'point' => $point->value,
                'status' => $status->value,
                'note' => blank($values['note'] ?? null) ? null : trim((string) $values['note']),
                'position' => $point->position(),
            ];
        }

        $this->points()->delete();
        $this->points()->createMany($rows);
        $this->unsetRelation('points');
    }

    /**
     * The recorded check-points keyed by their point value, for re-rendering
     * the form and the report.
     *
     * @return array<string, InspectionItem>
     */
    public function statusMap(): array
    {
        return $this->points->mapWithKeys(fn (InspectionItem $item) => [
            $item->point?->value => $item,
        ])->all();
    }

    /** How many check-points came back as anything other than OK. */
    public function concernCount(): int
    {
        return $this->points->reject(
            fn (InspectionItem $item) => $item->status === InspectionStatus::Ok
        )->count();
    }

    public function hasUrgentConcern(): bool
    {
        return $this->points->contains(
            fn (InspectionItem $item) => $item->status === InspectionStatus::Urgent
        );
    }

    /**
     * Find by plate the way it is actually typed: "leb 4477" and "LEB-4477"
     * are the same car.
     *
     * @param  Builder<Inspection>  $query
     */
    public function scopeForPlate(Builder $query, ?string $plate): void
    {
        $needle = ServiceHistory::normalisePlate($plate);

        // A term that is nothing but punctuation ("%", "_") normalises away to
        // an empty string; that must match nothing, not everything.
        if (filled((string) $plate) && $needle === '') {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->when($needle !== '', fn (Builder $q) => $q->whereRaw(
            ServiceHistory::plateExpression('vehicle_plate').' like ? escape ?',
            ['%'.$needle.'%', '\\'],
        ));
    }

    /** @param  Builder<Inspection>  $query */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('inspected_at')->orderByDesc('id');
    }
}
