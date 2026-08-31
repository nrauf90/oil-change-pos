<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\SaleTotalCalculator;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    /** How many fresh invoice numbers to try before giving up on a collision. */
    private const INVOICE_NUMBER_ATTEMPTS = 5;

    /** The columns a search term is matched against. */
    private const SEARCHABLE = [
        'invoice_number', 'customer_name', 'phone', 'vehicle_plate', 'vehicle_model',
    ];

    /**
     * `total_amount`, `invoice_number` and `cashier_id` are deliberately absent:
     * all three are facts the server establishes — the first two are derived, and
     * attribution comes from the session — so none may arrive in a payload.
     */
    protected $fillable = [
        'customer_name', 'phone', 'vehicle_model',
        'vehicle_plate', 'mileage', 'labor_charge', 'misc_charge', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mileage' => 'integer',
            'labor_charge' => 'decimal:2',
            'misc_charge' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Sale $sale): void {
            $sale->invoice_number ??= static::nextInvoiceNumber();
        });
    }

    /**
     * Insert, and on an invoice-number collision take a fresh number and try
     * again.
     *
     * Two counters pressing "Complete sale" in the same second can compute the
     * same number. Without this the loser gets a 500 and the whole hand-typed
     * bill is gone — far worse than a one-off retry.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        $attempt = 0;

        while (true) {
            try {
                return parent::save($options);
            } catch (UniqueConstraintViolationException $e) {
                $attempt++;

                if ($this->exists || $attempt >= self::INVOICE_NUMBER_ATTEMPTS) {
                    throw $e;
                }

                $this->invoice_number = static::nextInvoiceNumber();
            }
        }
    }

    /**
     * Who rang this sale up. Null for pre-attribution invoices and for sales
     * whose cashier has since been removed from the staff list.
     *
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /** @return HasMany<SaleItem, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * Sum of the line prices only. Read back from what was stored — never
     * recalculated from any inventory unit cost.
     */
    public function lineSubtotal(): string
    {
        return SaleTotalCalculator::lineSubtotal(
            $this->lines->pluck('manually_charged_price')->all()
        );
    }

    public function whatsappUrl(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->phone);

        if (blank($digits)) {
            return null;
        }

        $message = sprintf(
            "Thanks for visiting %s!\nInvoice %s — Total: %s\nVehicle: %s",
            config('app.name'),
            $this->invoice_number,
            number_format((float) $this->total_amount, 2),
            $this->vehicle_plate ?: ($this->vehicle_model ?: 'n/a'),
        );

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($message);
    }

    /**
     * Next number in today's series.
     *
     * Ordering is by length first, then by string: every number in a day shares
     * the same prefix, so a longer one is always a larger one. Plain string
     * ordering would leave "…-9999" above "…-10000" for ever, and the shop would
     * stop being able to issue invoices after the 9,999th of the day.
     */
    public static function nextInvoiceNumber(): string
    {
        $prefix = 'INV-'.now()->format('Ymd').'-';

        $lastSequence = (int) str(
            static::query()
                ->where('invoice_number', 'like', $prefix.'%')
                ->orderByRaw('length(invoice_number) desc')
                ->orderByDesc('invoice_number')
                ->value('invoice_number') ?? ''
        )->afterLast('-')->toString();

        return $prefix.str_pad((string) ($lastSequence + 1), 4, '0', STR_PAD_LEFT);
    }

    /** @param Builder<Sale> $query */
    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->whereBetween('created_at', [$from, $to]);
    }

    /**
     * Free-text lookup across the columns the counter would recognise.
     *
     * `%` and `_` are escaped, so searching for "50%" finds the customer whose
     * name contains it instead of returning the entire ledger.
     *
     * @param  Builder<Sale>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when(filled($term), function (Builder $q) use ($term): void {
            $like = '%'.addcslashes((string) $term, '%_\\').'%';

            $q->where(function (Builder $w) use ($like): void {
                $grammar = $w->getQuery()->getGrammar();

                foreach (self::SEARCHABLE as $column) {
                    $w->orWhereRaw($grammar->wrap($column).' like ? escape ?', [$like, '\\']);
                }
            });
        });
    }
}
