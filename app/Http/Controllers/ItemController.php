<?php

namespace App\Http\Controllers;

use App\Actions\AttachItemImage;
use App\Enums\ItemType;
use App\Http\Requests\ItemRequest;
use App\Models\Category;
use App\Models\Item;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Tenancy\TenantStoragePath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemController extends Controller
{
    public function index(Request $request): View|Response
    {
        // $request->string() on an array parameter (?q[]=a) warns and then
        // searches for the literal "Array". Take the query value only when it
        // really is a string.
        $search = $this->queryString($request, 'q');
        $type = $this->queryString($request, 'type');
        $status = $this->queryString($request, 'status');
        $categoryId = $this->queryInt($request, 'category');

        $items = Item::query()
            ->with('category')
            ->ofType($type)
            ->status($status)
            ->search($search)
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        // The live-search filter bar fetches just the results fragment so
        // typing never reloads the whole page. A normal request still gets
        // the full page, which itself includes the very same partial.
        if ($request->header('X-Inventory-Search') === '1') {
            return response(view('items._results', ['items' => $items])->render());
        }

        return view('items.index', [
            'items' => $items,
            'types' => ItemType::cases(),
            'categories' => Category::query()->orderBy('name')->get(),
            'activeType' => $type ?? '',
            'activeStatus' => $status ?? '',
            'activeCategory' => $categoryId ?? '',
            'search' => $search ?? '',
        ]);
    }

    public function create(): View
    {
        return view('items.create', [
            'types' => ItemType::cases(),
            'categories' => Category::query()->orderBy('name')->get(),
            'vehicleMakes' => VehicleMake::query()->orderBy('name')->get(),
            'vehicleModels' => VehicleModel::query()->orderBy('name')->get(['id', 'vehicle_make_id', 'name']),
        ]);
    }

    public function store(ItemRequest $request, AttachItemImage $attachImage): RedirectResponse
    {
        $item = Item::create($request->validated());

        $attachImage($item, $request->file('image'), $request->boolean('remove_image'));
        $this->syncVehicleCompatibilities($item, $request);

        return to_route('items.index')->with('status', "\"{$item->name}\" added to inventory.");
    }

    public function edit(Item $item): View
    {
        return view('items.edit', [
            'item' => $item->load('vehicleCompatibilities.vehicleModel.vehicleMake'),
            'types' => ItemType::cases(),
            'categories' => Category::query()->orderBy('name')->get(),
            'vehicleMakes' => VehicleMake::query()->orderBy('name')->get(),
            'vehicleModels' => VehicleModel::query()->orderBy('name')->get(['id', 'vehicle_make_id', 'name']),
        ]);
    }

    public function update(ItemRequest $request, Item $item, AttachItemImage $attachImage): RedirectResponse
    {
        $item->update($request->validated());

        $attachImage($item, $request->file('image'), $request->boolean('remove_image'));
        $this->syncVehicleCompatibilities($item, $request);

        return to_route('items.index')->with('status', "\"{$item->name}\" updated.");
    }

    public function destroy(Item $item, AttachItemImage $attachImage): RedirectResponse
    {
        $name = $item->name;

        // The file goes before the row, so nothing orphaned is left on disk.
        $attachImage($item, null, remove: true);
        $item->delete();

        return to_route('items.index')->with('status', "\"{$name}\" removed from inventory.");
    }

    /**
     * Streams an item's photo back from the private disk. Nothing is ever
     * served by URL — this route is the only way in.
     */
    public function image(Item $item, TenantStoragePath $storagePaths): StreamedResponse
    {
        $path = $storagePaths->readablePath($item->image_path, AttachItemImage::DIRECTORY);
        abort_if($path === null, 404);

        return Storage::disk('local')->response(
            $path,
            $item->image_original_name,
            ['X-Content-Type-Options' => 'nosniff', 'Content-Type' => $item->image_mime_type],
        );
    }

    /**
     * Replaces an item's vehicle compatibility rows wholesale — delete all,
     * then recreate from the submitted set. Only a non-universal Product
     * carries any; a blank trailing row from the form is skipped, not an
     * error. `ItemVehicleCompatibility::booted()` still enforces the year
     * range, duplicate, and product-only invariants on each insert.
     */
    private function syncVehicleCompatibilities(Item $item, Request $request): void
    {
        $item->vehicleCompatibilities()->delete();

        if ($item->type !== ItemType::Product || $item->is_universal) {
            return;
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $request->input('vehicle_compatibilities', []);

        foreach ($rows as $row) {
            $vehicleModelId = $row['vehicle_model_id'] ?? null;

            if ($vehicleModelId === null || $vehicleModelId === '') {
                continue;
            }

            $item->vehicleCompatibilities()->create([
                'vehicle_model_id' => $vehicleModelId,
                'year_from' => $row['year_from'] ?: null,
                'year_to' => $row['year_to'] ?: null,
            ]);
        }
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }

    private function queryInt(Request $request, string $key): ?int
    {
        $value = $this->queryString($request, $key);

        return $value !== null && $value !== '' && ctype_digit($value) ? (int) $value : null;
    }
}
