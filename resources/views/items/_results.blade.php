<div class="card overflow-hidden">
    <table class="w-full text-left">
        <thead class="border-b-2 border-slate-200 bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3">Item</th>
                <th class="px-4 py-3">Type</th>
                <th class="px-4 py-3">Category</th>
                <th class="px-4 py-3 text-right">Selling price</th>
                <th class="px-4 py-3 text-right">Stock</th>
                <th class="px-4 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($items as $item)
                <tr class="hover:bg-amber-50/60">
                    <td class="px-4 py-3 text-base font-bold">
                        <div class="flex items-center gap-3">
                            @if ($item->image_path)
                                <img src="{{ route('items.image', $item) }}" alt="{{ $item->name }}"
                                     class="size-10 shrink-0 rounded-lg border border-slate-200 object-cover">
                            @else
                                <span class="flex size-10 shrink-0 items-center justify-center rounded-lg border border-dashed border-slate-300 text-slate-300">—</span>
                            @endif
                            <span>
                                {{ $item->name }}
                                @unless ($item->is_active)
                                    <span data-status-badge="inactive" class="pill ml-1 bg-slate-200 text-slate-600"
                                          title="Hidden from the sale screen">Inactive</span>
                                @endunless
                            </span>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="pill {{ $item->type === \App\Enums\ItemType::Product ? 'bg-sky-100 text-sky-800' : 'bg-violet-100 text-violet-800' }}">
                            {{ $item->type->label() }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-sm font-medium text-slate-600">
                        {{ $item->category?->name ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-right font-mono tabular-nums text-slate-500">
                        {{ $item->selling_price !== null ? number_format((float) $item->selling_price, 2) : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if ($item->stockLabel() === null)
                            <span class="text-slate-400">Not tracked</span>
                        @else
                            {{-- Stock reads back in the item's own unit: "32.000 L", "13.000 kg", "7". --}}
                            <span class="font-mono text-base font-bold tabular-nums">{{ $item->stockLabel() }}</span>
                            @if ($item->isLowOnStock())
                                <span class="pill ml-1 bg-red-100 text-red-800">Low stock</span>
                            @endif
                        @endif
                        @if ($item->packContains() !== null)
                            {{-- The derived pack size, so a mistyped bottle count is obvious at a glance. --}}
                            <p class="mt-0.5 text-xs font-medium text-slate-500">
                                One {{ $item->pack_label ?: 'pack' }} = {{ $item->packContains() }} {{ $item->unit_of_measure->abbreviation() }}
                            </p>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex justify-end gap-2">
                            <a href="{{ route('items.edit', $item) }}" class="btn-ghost !px-3 !py-1.5">Edit</a>
                            <x-confirm-delete :action="route('items.destroy', $item)" :subject="$item->name" />
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-12 text-center font-semibold text-slate-400">
                        No items yet. Add one, or quick-add from the sale screen.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $items->links() }}</div>
