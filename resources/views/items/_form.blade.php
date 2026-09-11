@props(['item' => null, 'types', 'categories' => []])

@php
    use App\Enums\UnitOfMeasure;

    $units = UnitOfMeasure::cases();
    $selectedUnit = (string) old('unit_of_measure', $item?->unit_of_measure?->value ?? UnitOfMeasure::Piece->value);
    $selectedCase = UnitOfMeasure::tryFrom($selectedUnit) ?? UnitOfMeasure::Piece;
    $isMeasured = $selectedCase->isMeasured();

    $packLabel = old('pack_label', $item?->pack_label);
    $unitsPerPack = old('units_per_pack', $item?->units_per_pack);
    $measurePerUnit = old('measure_per_unit', $item?->measure_per_unit);
@endphp

<div class="grid gap-5 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="label" for="name">Item name</label>
        <input id="name" name="name" type="text" autofocus
               class="field @error('name') border-red-500 @enderror"
               placeholder="e.g. ZIC X7 10W-40 Full Synthetic"
               value="{{ old('name', $item?->name) }}">
        @error('name') <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <span class="label">Type</span>
        <div class="grid grid-cols-2 gap-2">
            @foreach ($types as $type)
                <label class="cursor-pointer">
                    <input type="radio" name="type" value="{{ $type->value }}" class="peer sr-only"
                           @checked(old('type', $item?->type?->value) === $type->value)>
                    <span class="block rounded-lg border-2 border-slate-300 bg-white px-3 py-2.5 text-center text-sm font-bold
                                 peer-checked:border-amber-500 peer-checked:bg-amber-100 peer-checked:text-amber-900">
                        {{ $type->label() }}
                    </span>
                </label>
            @endforeach
        </div>
        @error('type') <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="label" for="category_id">Category <span class="normal-case text-slate-400">(optional)</span></label>
        <select id="category_id" name="category_id" class="field @error('category_id') border-red-500 @enderror">
            <option value="">&mdash; No category &mdash;</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((string) old('category_id', $item?->category_id) === (string) $category->id)>
                    {{ $category->name }}
                </option>
            @endforeach
        </select>
        @error('category_id') <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="label" for="selling_price">Selling price <span class="normal-case text-slate-400">(optional)</span></label>
        <input id="selling_price" name="selling_price" type="text" inputmode="decimal"
               class="field-money @error('selling_price') border-red-500 @enderror"
               placeholder="0.00" value="{{ old('selling_price', $item?->selling_price) }}">
        <p class="mt-1 text-xs font-medium text-slate-500">
            What the counter charges for this item.
        </p>
        @error('selling_price') <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
    </div>

    @can('items.view_unit_cost')
    <div>
        <label class="label" for="unit_cost">Reference cost <span class="normal-case text-slate-400">(optional)</span></label>
        <input id="unit_cost" name="unit_cost" type="text" inputmode="decimal"
               class="field-money @error('unit_cost') border-red-500 @enderror"
               placeholder="0.00" value="{{ old('unit_cost', $item?->unit_cost) }}">
        <p class="mt-1 text-xs font-medium text-slate-500">
            A memory aid only. It is never added to an invoice &mdash; the counter always types the real price.
        </p>
        @error('unit_cost') <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
    </div>
    @endcan

    {{--
        How the item is counted, and — for anything poured or weighed — how it is
        bought. The packaging fields are meaningless on a filter, so they only
        appear once a measured unit is chosen. Alpine keeps that in step with the
        select without a round trip; the server picks the opening state so the
        form is right before Alpine boots.
    --}}
    <div class="sm:col-span-2 grid gap-5 sm:grid-cols-2"
         x-data="{
             unit: @js($selectedUnit),
             packLabel: @js((string) $packLabel),
             unitsPerPack: @js((string) $unitsPerPack),
             measurePerUnit: @js((string) $measurePerUnit),
             get measured() { return this.unit !== @js(UnitOfMeasure::Piece->value) },
             get abbreviation() { return { litre: 'L', kilogram: 'kg' }[this.unit] ?? '' },
             get packSummary() {
                 const units = parseFloat(this.unitsPerPack);
                 const per = parseFloat(this.measurePerUnit);
                 if (! this.measured || ! (units > 0) || ! (per > 0)) return '';
                 return 'One ' + (this.packLabel || 'pack') + ' = '
                     + (units * per).toFixed(3) + ' ' + this.abbreviation;
             },
         }">
        <div class="sm:col-span-2">
            <label class="label" for="unit_of_measure">Counted in</label>
            <select id="unit_of_measure" name="unit_of_measure" x-model="unit"
                    class="field @error('unit_of_measure') border-red-500 @enderror">
                @foreach ($units as $unit)
                    <option value="{{ $unit->value }}" @selected($selectedUnit === $unit->value)>{{ $unit->label() }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs font-medium text-slate-500">
                Filters and plugs are counted in pieces. Oil is poured by the litre; AC gas is charged by the kilogram.
            </p>
            @error('unit_of_measure') <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="sm:col-span-2 grid gap-5 rounded-xl border-2 border-dashed border-slate-300 p-4 sm:grid-cols-3"
             data-packaging="{{ $isMeasured ? 'shown' : 'hidden' }}"
             x-bind:hidden="! measured" @unless ($isMeasured) hidden @endunless>
            <div class="sm:col-span-3">
                <span class="label">How it is bought</span>
                <p class="text-xs font-medium text-slate-500">
                    Describe the pack the supplier delivers, so a delivery can be booked in without doing sums on paper.
                </p>
            </div>

            <div>
                <label class="label" for="pack_label">Pack is called</label>
                <input id="pack_label" name="pack_label" type="text" x-model="packLabel"
                       class="field @error('pack_label') border-red-500 @enderror"
                       placeholder="e.g. Carton" value="{{ $packLabel }}">
                @error('pack_label') <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="units_per_pack">Units per pack</label>
                <input id="units_per_pack" name="units_per_pack" type="text" inputmode="numeric" x-model="unitsPerPack"
                       class="field @error('units_per_pack') border-red-500 @enderror"
                       placeholder="e.g. 4" value="{{ $unitsPerPack }}">
                <p class="mt-1 text-xs font-medium text-slate-500">
                    Bottles in a carton, cylinders in a delivery.
                </p>
                @error('units_per_pack') <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="measure_per_unit">Amount per unit</label>
                <input id="measure_per_unit" name="measure_per_unit" type="text" inputmode="decimal" x-model="measurePerUnit"
                       class="field @error('measure_per_unit') border-red-500 @enderror"
                       placeholder="e.g. 4" value="{{ $measurePerUnit }}">
                <p class="mt-1 text-xs font-medium text-slate-500">
                    Litres in one bottle, kilograms in one cylinder.
                </p>
                @error('measure_per_unit') <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
            </div>

            <p class="sm:col-span-3 text-base font-bold text-slate-700" data-pack-summary x-text="packSummary">
                @if ($isMeasured && $item?->packContains() !== null)
                    One {{ $item->pack_label ?: 'pack' }} = {{ $item->packContains() }} {{ $selectedCase->abbreviation() }}
                @endif
            </p>
        </div>
    </div>

    <div>
        <label class="label" for="stock_level">Current stock level <span class="normal-case text-slate-400">(optional)</span></label>
        <input id="stock_level" name="stock_level" type="text" inputmode="decimal"
               class="field @error('stock_level') border-red-500 @enderror"
               placeholder="Leave blank to not track stock"
               value="{{ old('stock_level', $item?->stock_level) }}">
        <p class="mt-1 text-xs font-medium text-slate-500">
            Blank means this item is not stock-tracked. Counted in whatever unit you chose above.
        </p>
        @error('stock_level') <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="label" for="low_stock_alert">Low stock alert at <span class="normal-case text-slate-400">(optional)</span></label>
        <input id="low_stock_alert" name="low_stock_alert" type="text" inputmode="decimal"
               class="field @error('low_stock_alert') border-red-500 @enderror"
               placeholder="e.g. 5" value="{{ old('low_stock_alert', $item?->low_stock_alert) }}">
        <p class="mt-1 text-xs font-medium text-slate-500">
            The inventory list flags the item once stock drops to this amount.
        </p>
        @error('low_stock_alert') <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
    </div>

    <div class="sm:col-span-2">
        <span class="label">Availability</span>
        <label class="flex cursor-pointer items-center gap-3 rounded-lg border-2 border-slate-300 bg-white px-4 py-3">
            <input type="hidden" name="is_active" value="0">
            <input id="is_active" name="is_active" type="checkbox" value="1" class="size-5"
                   @checked((bool) old('is_active', $item?->is_active ?? true))>
            <span class="text-sm font-bold">Active &mdash; offer this item on the sale screen</span>
        </label>
        <p class="mt-1 text-xs font-medium text-slate-500">
            Unticking keeps the item and its sales history but hides it from the counter's picker.
        </p>
        @error('is_active') <p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p> @enderror
    </div>
</div>
