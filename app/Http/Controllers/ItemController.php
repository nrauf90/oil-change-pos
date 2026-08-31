<?php

namespace App\Http\Controllers;

use App\Enums\ItemType;
use App\Http\Requests\ItemRequest;
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

        $items = Item::query()
            ->ofType($type)
            ->status($status)
            ->search($search)
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('items.index', [
            'items' => $items,
            'types' => ItemType::cases(),
            'activeType' => $type ?? '',
            'activeStatus' => $status ?? '',
            'search' => $search ?? '',
        ]);
    }

    public function create(): View
    {
        return view('items.create', ['types' => ItemType::cases()]);
    }

    public function store(ItemRequest $request): RedirectResponse
    {
        $item = Item::create($request->validated());

        return to_route('items.index')->with('status', "\"{$item->name}\" added to inventory.");
    }

    public function edit(Item $item): View
    {
        return view('items.edit', ['item' => $item, 'types' => ItemType::cases()]);
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
}
