<?php

namespace App\Http\Controllers;

use App\Enums\ItemType;
use App\Http\Requests\ItemRequest;
use App\Models\Category;
use App\Models\Item;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ItemController extends Controller
{
    public function index(Request $request): View
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
        ]);
    }

    public function store(ItemRequest $request): RedirectResponse
    {
        $item = Item::create($request->validated());

        return to_route('items.index')->with('status', "\"{$item->name}\" added to inventory.");
    }

    public function edit(Item $item): View
    {
        return view('items.edit', [
            'item' => $item,
            'types' => ItemType::cases(),
            'categories' => Category::query()->orderBy('name')->get(),
        ]);
    }

    public function update(ItemRequest $request, Item $item): RedirectResponse
    {
        $item->update($request->validated());

        return to_route('items.index')->with('status', "\"{$item->name}\" updated.");
    }

    public function destroy(Item $item): RedirectResponse
    {
        $name = $item->name;
        $item->delete();

        return to_route('items.index')->with('status', "\"{$name}\" removed from inventory.");
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
