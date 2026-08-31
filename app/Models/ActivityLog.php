<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry in the audit trail.
 *
 * Written by the observers in App\Observers, never by a controller: hooking the
 * trail onto the model event means a second write path — a Filament action, an
 * artisan command, a future screen nobody has built yet — cannot quietly bypass
 * it.
 */
class ActivityLog extends Model
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;

    /** Entries are append-only, so there is nothing for an `updated_at` to mean. */
    public const UPDATED_AT = null;

    /**
     * Every action the shop records, in the order the filter should offer them.
     *
     * Declared rather than read back from the table so the dropdown is stable
     * on a fresh install, and so filtering to an action nobody has performed
     * yet shows an empty list instead of silently showing everything.
     */
    public const ACTIONS = [
        'sale.created',
        'sale.deleted',
        'item.created',
        'item.updated',
        'item.deleted',
        'expense.created',
        'expense.updated',
        'expense.deleted',
        'inspection.created',
        'supplier.created',
        'supplier.updated',
        'supplier.deleted',
        'supply.created',
        'supplier_payment.created',
        'user.created',
        'user.updated',
        'user.deleted',
    ];

    /**
     * Keys that must never reach the trail, at any depth of `properties`.
     *
     * The audit trail is the one table an owner reads casually and exports
     * without thinking. A credential — even a hashed one, which is an offline
     * cracking target — has no business in it. Enforced centrally in record()
     * rather than trusted to each observer, so a new observer written next year
     * is safe by default.
     */
    public const REDACTED_KEYS = [
        'password', 'password_hash', 'password_confirmation', 'remember_token', 'api_token',
    ];

    /**
     * Attributes a diff should never mention: churn that says nothing about
     * intent, plus the secrets above.
     */
    public const IGNORED_IN_DIFF = [
        'updated_at', 'created_at', 'last_login_at', ...self::REDACTED_KEYS,
    ];

    protected $fillable = [
        'user_id', 'user_name', 'action', 'subject_type', 'subject_id', 'description', 'properties',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // An audit trail that can be rewritten is not an audit trail, and one
        // that can be tidied up is worse. The HTTP surface is read-only; these
        // two close the in-process routes as well, so ActivityLog::first()
        // ->delete() in a console command or a stray Filament action fails
        // loudly instead of quietly erasing evidence. The record stands until
        // the database itself is dropped.
        static::updating(function (ActivityLog $entry): void {
            throw new LogicException('Activity log entries are append-only and cannot be modified.');
        });

        static::deleting(function (ActivityLog $entry): void {
            throw new LogicException('Activity log entries are append-only and cannot be deleted.');
        });
    }

    /**
     * Record something that happened, snapshotting the actor's name so the
     * entry still reads sensibly after the account is removed.
     *
     * @param  array<string, mixed>|null  $properties
     */
    public static function record(
        string $action,
        string $description,
        ?Model $subject = null,
        ?array $properties = null,
    ): self {
        $actor = auth()->user();

        return static::create([
            'user_id' => static::actorId($actor, $subject),
            // A write from the console or a queue has no signed-in actor.
            'user_name' => $actor?->name ?? 'System',
            'action' => $action,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties === null ? null : static::scrub($properties),
        ]);
    }

    /**
     * The foreign key to hang this entry on, or null when the actor's own row
     * has just gone.
     *
     * An owner removing their own account writes an entry a moment after the
     * users row is deleted; the constraint would refuse the insert and take the
     * audit entry down with it. `user_name` is the part that matters, so the
     * link is dropped and the name kept.
     */
    private static function actorId(mixed $actor, ?Model $subject): mixed
    {
        if (! $actor instanceof Model) {
            return null;
        }

        $gone = ! $actor->exists || ($subject instanceof User && $subject->is($actor));

        return $gone ? null : $actor->getKey();
    }

    /**
     * Strip credentials out of a property bag, however deeply they are nested.
     *
     * Both halves matter: the key list catches `password` wherever it appears,
     * and the value check catches a hash that arrived under some innocent name.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function scrub(array $properties): array
    {
        $clean = [];

        foreach ($properties as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = static::scrub($value);

                continue;
            }

            if (is_string($value) && preg_match('/^\$2[aby]\$\d{2}\$/', $value) === 1) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * What actually changed on a saved model, as `field => [from, to]`.
     *
     * A diff, not a snapshot: "unit_cost 1800 → 2400" is an audit trail, a copy
     * of the whole row is just noise the reader has to compare by eye. Raw
     * attribute values are used on both sides so the two halves are comparable.
     *
     * @param  array<int, string>  $ignore  extra attributes this model considers churn
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function changes(Model $model, array $ignore = []): array
    {
        $ignored = array_merge(self::IGNORED_IN_DIFF, $ignore);
        $diff = [];

        foreach ($model->getChanges() as $key => $new) {
            if (in_array($key, $ignored, true)) {
                continue;
            }

            $diff[$key] = ['from' => $model->getRawOriginal($key), 'to' => $new];
        }

        return $diff;
    }

    /**
     * Who did it. Null once the account has been removed — `user_name` is what
     * survives.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Narrow to one action. An unrecognised filter value is ignored rather than
     * silently returning an empty log.
     *
     * @param  Builder<ActivityLog>  $query
     */
    public function scopeOfAction(Builder $query, ?string $action): void
    {
        $query->when(
            filled($action) && in_array($action, self::ACTIONS, true),
            fn (Builder $q) => $q->where('action', $action),
        );
    }

    /**
     * Free-text over what happened. The wildcards in the term are escaped, so
     * a customer name containing `%` searches for itself rather than for
     * everything.
     *
     * @param  Builder<ActivityLog>  $query
     */
    public function scopeMatching(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        $query->when($term !== '', fn (Builder $q) => $q->where(
            'description',
            'like',
            '%'.addcslashes($term, '%_\\').'%',
        ));
    }

    /**
     * Both ends are inclusive whole days: an owner asking for "the 3rd" means
     * everything that happened on the 3rd, not everything up to midnight.
     *
     * @param  Builder<ActivityLog>  $query
     */
    public function scopeLoggedBetween(Builder $query, ?Carbon $from, ?Carbon $to): void
    {
        $query->when($from !== null, fn (Builder $q) => $q->where('created_at', '>=', $from?->startOfDay()))
            ->when($to !== null, fn (Builder $q) => $q->where('created_at', '<=', $to?->endOfDay()));
    }

    /**
     * The actions offered in the filter, keyed by machine name.
     *
     * @return array<string, string>
     */
    public static function filterableActions(): array
    {
        $labels = [];

        foreach (self::ACTIONS as $action) {
            $labels[$action] = static::labelFor($action);
        }

        return $labels;
    }

    /** A human label for an action key: `sale.deleted` reads as "Sale deleted". */
    public static function labelFor(string $action): string
    {
        return ucfirst(str_replace(['.', '_'], ' ', $action));
    }

    public function actionLabel(): string
    {
        return static::labelFor($this->action);
    }

    /**
     * Colour by what the action did, not by which module it came from: the
     * owner is scanning for the destructive rows.
     */
    public function actionTone(): string
    {
        return match (true) {
            str_ends_with($this->action, '.deleted') => 'bg-red-100 text-red-800',
            str_ends_with($this->action, '.updated') => 'bg-amber-100 text-amber-900',
            default => 'bg-emerald-100 text-emerald-800',
        };
    }

    /**
     * The `from → to` pairs on this entry, if it is a diff.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function changedValues(): array
    {
        $changed = $this->properties['changed'] ?? [];

        return is_array($changed) ? $changed : [];
    }

    /**
     * The flat snapshot values on this entry — everything but the diff.
     *
     * @return array<string, mixed>
     */
    public function snapshotValues(): array
    {
        return collect($this->properties ?? [])
            ->except('changed')
            ->reject(fn (mixed $value): bool => $value === null || $value === '' || is_array($value))
            ->all();
    }
}
