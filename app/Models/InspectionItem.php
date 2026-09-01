<?php

namespace App\Models;

use App\Enums\InspectionPoint;
use App\Enums\InspectionStatus;
use Database\Factories\InspectionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One check-point of one inspection: what was looked at, the verdict, and an
 * optional note. There is no price column here by design.
 */
class InspectionItem extends TenantModel
{
    /** @use HasFactory<InspectionItemFactory> */
    use HasFactory;

    protected $fillable = ['inspection_id', 'point', 'status', 'note', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'point' => InspectionPoint::class,
            'status' => InspectionStatus::class,
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Inspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    /** Falls back to the raw stored key if the shop's procedure ever renames one. */
    public function label(): string
    {
        return $this->point?->label() ?? (string) $this->getRawOriginal('point');
    }
}
