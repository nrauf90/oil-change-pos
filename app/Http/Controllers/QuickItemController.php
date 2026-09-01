<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ItemType;
use App\Enums\Permission;
use App\Http\Requests\QuickItemRequest;
use App\Models\Item;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The "on-the-fly" inventory endpoints the sale screen calls over fetch.
 *
 * Both return JSON and never redirect: the checkout page must not navigate
 * away mid-bill, or the counter loses everything they have typed.
 */
class QuickItemController extends Controller
{
    /** Keeps the picker fast; the sale screen narrows with ?q= for a big catalogue. */
    private const MAX_RESULTS = 50;

    public function index(Request $request): JsonResponse
    {
        $q = is_string($request->query('q')) ? $request->query('q') : null;
        $type = is_string($request->query('type')) ? $request->query('type') : null;

        $items = Item::query()
            ->with('vehicleCompatibilities.vehicleModel')
            ->active()
            ->ofType($type)
            ->search($q)
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get();

        return response()->json([
            'data' => $items->map(fn (Item $item) => $this->payload($item))->values(),
        ]);
    }

    public function store(QuickItemRequest $request): JsonResponse
    {
        $item = DB::transaction(function () use ($request): Item {
            $item = Item::create($request->safe()->only([
                'name',
                'type',
                'is_universal',
                'unit_cost',
                'stock_level',
                'low_stock_alert',
            ]) + ['is_active' => true, 'is_universal' => false]);

            if ($item->type === ItemType::Product && ! $item->is_universal) {
                foreach ($request->validatedCompatibilities() as $index => $compatibility) {
                    $this->persistCompatibility($item, $compatibility, $index);
                }
            }

            $item->load('vehicleCompatibilities.vehicleModel');

            return $item;
        });

        return response()->json(['data' => $this->payload($item)], 201);
    }

    /**
     * Everything the front-end needs to drop the item straight onto the tile
     * grid and into the ticket.
     *
     * Delegates to the sale screen's own presenter so a quick-added item is
     * shaped exactly like one that came down with the page - same category,
     * same glyph, same stock label. Anything the tile grid does not need but
     * inventory callers do is merged on top.
     *
     * @return array<string, mixed>
     */
    private function payload(Item $item): array
    {
        return PosController::present($item, $this->maySeeCost()) + [
            'low_stock_alert' => $item->low_stock_alert,
        ];
    }

    /**
     * @param  array{vehicle_make_id: int|null, vehicle_make_name: ?string, vehicle_model_id: int|null, vehicle_model_name: ?string, year_from: int|null, year_to: int|null}  $compatibility
     */
    private function persistCompatibility(Item $item, array $compatibility, int $index): void
    {
        $vehicleMake = $this->resolveVehicleMake($compatibility);
        $vehicleModel = $this->resolveVehicleModel($compatibility, $vehicleMake, $index);

        try {
            $item->vehicleCompatibilities()->create([
                'vehicle_model_id' => $vehicleModel->id,
                'year_from' => $compatibility['year_from'],
                'year_to' => $compatibility['year_to'],
            ]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($this->scopedErrors($exception, $index));
        }
    }

    /**
     * @param  array{vehicle_make_id: int|null, vehicle_make_name: ?string, vehicle_model_id: int|null, vehicle_model_name: ?string, year_from: int|null, year_to: int|null}  $compatibility
     */
    private function resolveVehicleMake(array $compatibility): ?VehicleMake
    {
        if ($compatibility['vehicle_make_id'] !== null) {
            return VehicleMake::query()->findOrFail($compatibility['vehicle_make_id']);
        }

        if ($compatibility['vehicle_make_name'] === null) {
            return null;
        }

        $name = $this->normaliseName($compatibility['vehicle_make_name']);
        $existing = VehicleMake::query()
            ->whereRaw('lower(name) = ?', [Str::lower($name)])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return VehicleMake::query()->firstOrCreate(['name' => $name]);
    }

    /**
     * @param  array{vehicle_make_id: int|null, vehicle_make_name: ?string, vehicle_model_id: int|null, vehicle_model_name: ?string, year_from: int|null, year_to: int|null}  $compatibility
     */
    private function resolveVehicleModel(array $compatibility, ?VehicleMake $vehicleMake, int $index): VehicleModel
    {
        if ($compatibility['vehicle_model_id'] !== null) {
            $vehicleModel = VehicleModel::query()->findOrFail($compatibility['vehicle_model_id']);

            if ($vehicleMake !== null && $vehicleModel->vehicle_make_id !== $vehicleMake->id) {
                throw ValidationException::withMessages([
                    "compatibilities.{$index}.vehicle_model_id" => 'The selected model does not belong to the selected make.',
                ]);
            }

            return $vehicleModel;
        }

        if ($vehicleMake === null) {
            throw ValidationException::withMessages([
                "compatibilities.{$index}.vehicle_make_id" => 'Choose a make or type a new one.',
            ]);
        }

        $name = $this->normaliseName((string) $compatibility['vehicle_model_name']);
        $existing = VehicleModel::query()
            ->where('vehicle_make_id', $vehicleMake->id)
            ->whereRaw('lower(name) = ?', [Str::lower($name)])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return VehicleModel::query()->firstOrCreate([
            'vehicle_make_id' => $vehicleMake->id,
            'name' => $name,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function scopedErrors(ValidationException $exception, int $index): array
    {
        return collect($exception->errors())
            ->mapWithKeys(fn (array $messages, string $field): array => ["compatibilities.{$index}.{$field}" => $messages])
            ->all();
    }

    private function normaliseName(string $name): string
    {
        return Str::squish($name);
    }

    private function maySeeCost(): bool
    {
        return request()->user()?->can(Permission::ViewItemUnitCost->value) ?? false;
    }
}
