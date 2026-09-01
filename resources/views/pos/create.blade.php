@extends('layouts.app')
@section('title', 'New Sale')

{{-- The counter runs edge to edge. It is a till, not a document. --}}
@section('main-class', 'flex w-full flex-1 flex-col px-3 pt-3 pb-3 lg:px-4')

@php
    // Rebuild the cart from old input so a failed server-side validation never
    // wipes a bill the counter already typed out.
    $initialLines = collect(old('lines', []))
        ->map(fn ($line, $index) => [
            'uid' => 'restored-'.$index,
            'mode' => $line['type'] ?? 'product',
            'item_id' => (string) ($line['item_id'] ?? ''),
            'item_name' => $line['item_name'] ?? '',
            'qty' => (string) ($line['quantity'] ?? '1'),
            'dispensed' => (string) ($line['dispensed_quantity'] ?? ''),
            'price' => (string) ($line['manually_charged_price'] ?? ''),
        ])
        ->values()
        ->all();

    $customerFields = ['customer_name', 'phone', 'vehicle_model', 'vehicle_plate', 'mileage', 'notes'];

    $customer = collect($customerFields)
        ->mapWithKeys(fn (string $field) => [$field => (string) old($field, '')])
        ->all();

    // The customer strip opens itself when there is something in it to read, or
    // something in it to fix. Otherwise it stays out of the counter's way.
    $customerOpen = collect($customer)->contains(fn ($value) => filled($value))
        || collect($customerFields)->contains(fn (string $field) => $errors->has($field));
@endphp

@section('content')
<form method="POST" action="{{ route('sales.store') }}"
      x-data="posCounter(@js([
          'items' => $items,
          'groups' => $groups,
          'vehicleMakes' => $vehicle_makes,
          'vehicleYears' => range(2000, now()->year),
          'currentYear' => now()->year,
          'lines' => $initialLines,
          'labor' => (string) old('labor_charge', ''),
          'misc' => (string) old('misc_charge', ''),
          'customer' => $customer,
          'customerVehicles' => $customerVehicles,
          'customerVehicleSearchUrl' => route('customer-vehicles.index'),
          'customerOpen' => $customerOpen,
          'quickAddUrl' => route('quick-items.store'),
          'saleUrl' => route('pos.create'),
      ]))"
      x-ref="shell"
      x-on:submit="submitting = true"
      class="flex min-h-0 flex-1 flex-col gap-3">
    @csrf

    @if ($errors->any())
        <div class="shrink-0 rounded-xl border border-red-300 bg-red-50 px-4 py-3">
            <p class="text-sm font-bold text-red-800">This bill could not be saved:</p>
            <ul class="mt-1.5 list-inside list-disc text-sm font-semibold text-red-700">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <p class="mt-1.5 text-xs font-medium text-red-600">Your typed-in bill is still here — fix the above and charge again.</p>
        </div>
    @endif

    {{-- ================================================================== --}}
    {{-- A. Customer & vehicle — one line until it is needed                --}}
    {{-- ================================================================== --}}
    <section class="shrink-0 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <button type="button" @click="customerOpen = ! customerOpen"
                class="flex w-full items-center gap-3 px-4 py-2.5 text-left transition hover:bg-slate-50"
                :aria-expanded="customerOpen ? 'true' : 'false'">
            <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-slate-900 text-sm text-amber-400">&#128663;</span>

            <span class="pos-pane-title hidden shrink-0 sm:block">Customer &amp; vehicle</span>
            <span class="hidden h-7 w-px shrink-0 bg-slate-200 sm:block" aria-hidden="true"></span>

            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-bold text-slate-900" x-text="customerHeadline"></span>
                <span class="block truncate text-xs font-medium text-slate-400" x-text="vehicleHeadline"></span>
            </span>

            <span class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-bold text-slate-600"
                  x-text="customerOpen ? 'Hide details' : 'Add details'"></span>
        </button>

        <div x-show="customerOpen" x-cloak class="border-t border-slate-200 bg-slate-50/70 px-4 py-4">
            <div class="grid gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
                <div class="sm:col-span-2 lg:col-span-3 2xl:col-span-6" x-show="recentCustomerVehicles.length">
                    <label class="label" for="saved_customer_vehicle">Saved customer / vehicle</label>
                    <div class="relative" @click.outside="customerVehicleResultsOpen = false">
                        <input id="saved_customer_vehicle" type="search" class="field !py-2"
                               x-model="customerVehicleSearch"
                               @input.debounce.300ms="searchCustomerVehicles()"
                               @focus="customerVehicleResultsOpen = customerVehicleSearch.trim().length >= 3"
                               @keydown.escape="customerVehicleResultsOpen = false"
                               placeholder="Type 3+ characters: name, phone, plate, or vehicle"
                               autocomplete="off"
                               role="combobox"
                               :aria-expanded="customerVehicleResultsOpen ? 'true' : 'false'"
                               aria-controls="saved_customer_vehicle_results">

                        <div id="saved_customer_vehicle_results"
                             x-show="customerVehicleResultsOpen"
                             x-cloak
                             class="absolute z-40 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl"
                             role="listbox">
                            <p x-show="searchingCustomerVehicles" class="px-3 py-2 text-sm font-semibold text-slate-500">
                                Searching saved records&hellip;
                            </p>
                            <template x-for="profile in customerVehicles" :key="profile.id">
                                <button type="button" role="option"
                                        class="block w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-amber-50 hover:text-slate-950"
                                        @click="applyCustomerVehicle(profile)"
                                        x-text="customerVehicleLabel(profile)"></button>
                            </template>
                            <p x-show="! searchingCustomerVehicles && customerVehicles.length === 0"
                               class="px-3 py-2 text-sm font-semibold text-slate-500">
                                No saved customer or vehicle matches that search.
                            </p>
                        </div>
                    </div>
                    <p x-show="customerVehicleSearch.length > 0 && customerVehicleSearch.length < 3"
                       x-cloak class="mt-1.5 text-xs font-semibold text-slate-500">
                        Type at least 3 characters to search saved records.
                    </p>
                </div>
                <div>
                    <label class="label" for="customer_name">Customer name</label>
                    <input id="customer_name" name="customer_name" type="text" class="field !py-2"
                           x-model="customer.customer_name" placeholder="Walk-in">
                </div>
                <div>
                    <label class="label" for="phone">Mobile / WhatsApp</label>
                    <input id="phone" name="phone" type="tel" inputmode="tel" class="field !py-2"
                           x-model="customer.phone" placeholder="03xx-xxxxxxx">
                </div>
                <div>
                    <label class="label" for="vehicle_model">Vehicle model &amp; year</label>
                    <input id="vehicle_model" name="vehicle_model" type="text" class="field !py-2"
                           x-model="customer.vehicle_model" placeholder="Toyota Corolla 2018">
                </div>
                <div>
                    <label class="label" for="vehicle_plate">License plate</label>
                    <input id="vehicle_plate" name="vehicle_plate" type="text" class="field !py-2 uppercase"
                           x-model="customer.vehicle_plate" placeholder="ABC-123">
                </div>
                <div>
                    <label class="label" for="mileage">Odometer mileage</label>
                    <input id="mileage" name="mileage" type="text" inputmode="numeric" class="field !py-2"
                           x-model="customer.mileage" placeholder="84500">
                </div>
                <div>
                    <label class="label" for="notes">Notes</label>
                    <input id="notes" name="notes" type="text" class="field !py-2"
                           x-model="customer.notes" placeholder="Next service due at 90,000">
                </div>
            </div>
        </div>
    </section>

    {{-- ================================================================== --}}
    {{-- B. The till: category rail | product wall | ticket                 --}}
    {{-- ================================================================== --}}
    <div class="flex min-h-0 flex-1 gap-3 pb-20 lg:pb-0">

        {{-- ------------------------------------------------------------ --}}
        {{-- B1. Category rail                                            --}}
        {{-- ------------------------------------------------------------ --}}
        <nav class="pos-pane hidden w-52 shrink-0 lg:flex xl:w-56" aria-label="Product categories">
            <div class="pos-pane-head">
                <span class="pos-pane-title">Categories</span>
            </div>

            <div class="pos-scroll space-y-1 p-2">
                <template x-for="group in railGroups" :key="group.key">
                    <button type="button"
                            class="pos-rail-btn"
                            :class="railClassFor(group)"
                            @click="activeGroup = group.key"
                            :aria-pressed="activeGroup === group.key ? 'true' : 'false'">
                        <span class="grid size-8 shrink-0 place-items-center rounded-lg text-base"
                              :class="activeGroup === group.key ? 'bg-white/15' : accentFor(group.key).chip"
                              x-text="group.glyph"></span>
                        <span class="min-w-0 flex-1 truncate" x-text="group.label"></span>
                        <span class="shrink-0 rounded-md px-1.5 py-0.5 font-mono text-xs tabular-nums"
                              :class="activeGroup === group.key ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-500'"
                              x-text="group.count"></span>
                    </button>
                </template>
            </div>

            <div class="shrink-0 space-y-1 border-t border-slate-200 p-2">
                <button type="button" class="pos-rail-btn"
                        :class="lowStockOnly ? 'border-red-500 bg-red-500 text-white hover:border-red-500 hover:bg-red-500' : ''"
                        @click="lowStockOnly = ! lowStockOnly"
                        :aria-pressed="lowStockOnly ? 'true' : 'false'">
                    <span class="grid size-8 shrink-0 place-items-center rounded-lg text-base"
                          :class="lowStockOnly ? 'bg-white/15' : 'bg-red-100 text-red-600'">&#9888;</span>
                    <span class="min-w-0 flex-1 truncate">Low stock</span>
                    <span class="shrink-0 rounded-md px-1.5 py-0.5 font-mono text-xs tabular-nums"
                          :class="lowStockOnly ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-500'"
                          x-text="lowStockCount"></span>
                </button>

                <button type="button" class="pos-rail-btn" @click="addCustomLine()">
                    <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-slate-200 text-base text-slate-600">&#9998;</span>
                    <span class="min-w-0 flex-1 truncate">Custom line</span>
                </button>

                {{-- Docked here rather than floating: a button hovering over the
                     bottom-right corner would sit on top of the Charge button. --}}
                <x-counter-scripts :floating="false" label="Counter scripts" class="block"
                                   trigger-class="pos-rail-btn"
                                   icon-class="grid size-8 shrink-0 place-items-center rounded-lg bg-sky-100 text-base text-sky-700" />
            </div>
        </nav>

        {{-- ------------------------------------------------------------ --}}
        {{-- B2. Product wall                                             --}}
        {{-- ------------------------------------------------------------ --}}
        <section class="pos-pane min-w-0 flex-1" aria-label="Products">
            <div class="pos-pane-head gap-3">
                <div class="relative min-w-0 flex-1">
                    <span class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-slate-400">&#128269;</span>
                    {{-- No name attribute: the search box is never posted with the bill. --}}
                    <input type="search" x-ref="search" x-model="query"
                           @keydown.enter.prevent="addFirstMatch()"
                           @keydown.escape.prevent="query = ''"
                           placeholder="Search products and services&hellip;"
                           aria-label="Search products and services"
                           class="w-full rounded-lg border border-slate-300 bg-white py-2 pr-3 pl-9 text-sm font-semibold
                                  placeholder:font-normal placeholder:text-slate-400
                                  focus:border-amber-500 focus:ring-2 focus:ring-amber-200 focus:outline-none">
                </div>

                <span class="hidden shrink-0 font-mono text-xs font-bold tabular-nums text-slate-400 md:inline"
                      x-text="visibleItems.length + ' shown'"></span>

                <button type="button" class="btn-dark shrink-0 !px-3 !py-2 !text-xs" @click="openQuickAdd(null)">
                    + New item
                </button>
            </div>

            <div class="shrink-0 border-b border-slate-200 bg-slate-50/80 px-3 py-3">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-32">
                        <label class="label" for="vehicle_make_filter">Vehicle filters</label>
                        <select id="vehicle_make_filter" class="field !py-2"
                                x-model="vehicleFilter.makeId"
                                @change="syncVehicleFilterModel()">
                            <option value="">Make</option>
                            <template x-for="make in vehicleMakes" :key="'vf-make-' + make.id">
                                <option :value="String(make.id)" x-text="make.name"></option>
                            </template>
                        </select>
                    </div>

                    <div class="min-w-32">
                        <label class="label" for="vehicle_model_filter">Model</label>
                        <select id="vehicle_model_filter" class="field !py-2"
                                x-model="vehicleFilter.modelId"
                                :disabled="vehicleFilter.makeId === ''">
                            <option value="" x-text="vehicleFilter.makeId === '' ? 'Choose make first' : 'Model'"></option>
                            <template x-for="model in vehicleModelsForFilter" :key="'vf-model-' + model.id">
                                <option :value="String(model.id)" x-text="model.name"></option>
                            </template>
                        </select>
                    </div>

                    <div class="min-w-28">
                        <label class="label" for="vehicle_year_filter">Year</label>
                        <select id="vehicle_year_filter" class="field !py-2" x-model="vehicleFilter.year">
                            <option value="">Year</option>
                            <template x-for="year in vehicleYearsDescending" :key="'vf-year-' + year">
                                <option :value="String(year)" x-text="year"></option>
                            </template>
                        </select>
                    </div>

                    <button type="button" class="btn-ghost !py-2"
                            @click="resetVehicleFilters()"
                            :disabled="! vehicleFilterActive">
                        All vehicles
                    </button>

                    <span class="text-xs font-semibold text-slate-500"
                          x-text="vehicleFilterActive ? 'Universal products stay visible alongside matching vehicle-specific parts.' : 'Showing every vehicle-compatible product and every service.'"></span>
                </div>
            </div>

            {{-- Categories collapse into a scrolling chip row on a narrow screen. --}}
            <div class="shrink-0 overflow-x-auto border-b border-slate-200 bg-white px-3 py-2 lg:hidden">
                <div class="flex w-max items-center gap-2">
                    <template x-for="group in railGroups" :key="'chip-' + group.key">
                        <button type="button"
                                class="shrink-0 rounded-full border border-slate-300 px-3 py-1.5 text-xs font-bold whitespace-nowrap text-slate-600"
                                :class="activeGroup === group.key ? 'border-slate-900 bg-slate-900 text-white' : ''"
                                @click="activeGroup = group.key">
                            <span x-text="group.glyph"></span>
                            <span x-text="group.label"></span>
                            <span class="ml-1 font-mono tabular-nums opacity-60" x-text="group.count"></span>
                        </button>
                    </template>
                    <button type="button"
                            class="shrink-0 rounded-full border border-slate-300 px-3 py-1.5 text-xs font-bold whitespace-nowrap text-slate-600"
                            :class="lowStockOnly ? 'border-red-500 bg-red-500 text-white' : ''"
                            @click="lowStockOnly = ! lowStockOnly">&#9888; Low stock</button>
                </div>
            </div>

            <div class="pos-scroll p-3">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                    <template x-for="item in visibleItems" :key="item.id">
                        <button type="button" class="pos-tile"
                                :class="poppedId === item.id ? 'pos-popped border-amber-500 ring-2 ring-amber-300' : ''"
                                @click="addItem(item)"
                                :aria-label="'Add ' + item.name + ' to the ticket'">
                            {{-- The category stripe: colour names a shelf before a word is read. --}}
                            <span class="absolute inset-x-0 top-0 h-1" :class="accentFor(item.group).bar"></span>

                            <div class="flex items-start justify-between gap-2 pt-1">
                                <span class="grid size-10 shrink-0 place-items-center rounded-lg text-xl"
                                      :class="accentFor(item.group).chip" x-text="item.glyph"></span>

                                <span class="flex flex-col items-end gap-1">
                                    <span x-show="countOf(item.id) > 0" x-cloak
                                          class="grid size-6 place-items-center rounded-full bg-slate-900 font-mono text-xs font-black tabular-nums text-white"
                                          x-text="countOf(item.id)"></span>
                                    <span x-show="item.is_low_on_stock" x-cloak
                                          class="rounded-md bg-red-100 px-1.5 py-0.5 text-[10px] font-black tracking-wide text-red-700 uppercase">Low</span>
                                </span>
                            </div>

                            <div class="min-w-0">
                                <p class="line-clamp-2 text-sm leading-snug font-bold text-slate-900" x-text="item.name"></p>
                                <p class="mt-1 truncate text-[11px] font-semibold tracking-wide text-slate-400 uppercase"
                                   x-text="tileMeta(item)"></p>
                            </div>
                        </button>
                    </template>
                </div>

                <div x-show="visibleItems.length === 0" x-cloak class="grid place-items-center px-6 py-16 text-center">
                    <p class="text-3xl">&#128269;</p>
                    <p class="mt-3 font-bold text-slate-500" x-text="emptyWallTitle"></p>
                    <p class="mt-1 text-sm font-medium text-slate-400">Clear the filters, or add it to inventory without leaving this bill.</p>
                    <div class="mt-4 flex gap-2">
                        <button type="button" class="btn-ghost !py-2" @click="resetFilters()">Clear filters</button>
                        <button type="button" class="btn-primary !py-2" @click="openQuickAdd(null)">+ New item</button>
                    </div>
                </div>
            </div>
        </section>

        {{-- ------------------------------------------------------------ --}}
        {{-- B3. The ticket                                               --}}
        {{-- ------------------------------------------------------------ --}}
        <aside class="pos-pane inset-0 z-40 w-full shrink-0 lg:static lg:z-auto lg:w-[23rem] xl:w-[26rem]"
               :class="ticketOpen ? 'fixed flex rounded-none lg:static lg:rounded-2xl' : 'hidden lg:flex'"
               aria-label="Current ticket">

            <div class="pos-pane-head justify-between">
                <div class="flex items-center gap-2">
                    <span class="pos-pane-title">Ticket</span>
                    <span class="rounded-md bg-slate-900 px-1.5 py-0.5 font-mono text-xs font-black tabular-nums text-white"
                          x-text="lines.length"></span>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button" x-show="lines.length > 0" x-cloak @click="clearTicket()"
                            class="rounded-md px-2 py-1 text-xs font-bold tracking-wide uppercase transition"
                            :class="clearArmed ? 'bg-red-600 text-white' : 'text-slate-400 hover:text-red-600'"
                            x-text="clearArmed ? 'Tap to confirm' : 'Clear'"></button>
                    <button type="button" class="pos-step lg:hidden" @click="ticketOpen = false"
                            aria-label="Back to products">&times;</button>
                </div>
            </div>

            {{-- Ticket lines --}}
            <div class="pos-scroll divide-y divide-slate-100">
                <template x-for="(line, index) in lines" :key="line.uid">
                    <div class="px-3 py-3 transition-colors" :class="line.uid === flashedUid ? 'bg-amber-50' : ''">

                        <div class="flex items-start gap-2.5">
                            <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg text-base"
                                  :class="accentFor(groupOf(line)).chip" x-text="glyphOf(line)"></span>

                            <div class="min-w-0 flex-1">
                                {{-- An inventory line names itself; a custom line is typed. --}}
                                <template x-if="line.mode !== 'custom'">
                                    <p class="text-sm leading-snug font-bold break-words text-slate-900" x-text="line.item_name"></p>
                                </template>

                                <template x-if="line.mode === 'custom'">
                                    <input type="text" class="pos-input" x-model="line.item_name"
                                           :name="`lines[${index}][item_name]`"
                                           placeholder="e.g. Fixed jammed door latch"
                                           :aria-label="'Line ' + (index + 1) + ' description'">
                                </template>

                                <p class="mt-0.5 truncate text-[11px] font-semibold tracking-wide uppercase"
                                   :class="stockIsLowFor(line) ? 'text-red-600' : 'text-slate-400'"
                                   x-text="lineMeta(line)"></p>
                            </div>

                            <button type="button"
                                    class="pos-step !size-8 border-transparent !text-slate-400 hover:!border-red-300 hover:!bg-red-50 hover:!text-red-600"
                                    @click="removeLine(line.uid)"
                                    :aria-label="'Remove line ' + (index + 1)">&times;</button>
                        </div>

                        {{-- Quantity and the typed line total, on one baseline. --}}
                        <div class="mt-2.5 flex items-center gap-2">
                            <div class="flex shrink-0 items-center gap-1">
                                <button type="button" class="pos-step" @click="stepQty(line, -1)"
                                        :disabled="Number(line.qty) <= 1"
                                        :aria-label="'One less of line ' + (index + 1)">&minus;</button>
                                <input type="text" inputmode="numeric" class="pos-input pos-no-spin w-12 !px-1 text-center"
                                       x-model="line.qty"
                                       :name="`lines[${index}][quantity]`"
                                       placeholder="1"
                                       :aria-label="'Line ' + (index + 1) + ' quantity'">
                                <button type="button" class="pos-step" @click="stepQty(line, 1)"
                                        :aria-label="'One more of line ' + (index + 1)">+</button>
                            </div>

                            <div class="ml-auto flex min-w-0 items-center gap-2">
                                <span class="shrink-0 text-[11px] font-bold tracking-wide text-slate-400 uppercase">Line total</span>
                                <input type="text" inputmode="decimal" class="pos-input-money w-28 shrink-0"
                                       x-model="line.price"
                                       :data-price-uid="line.uid"
                                       :name="`lines[${index}][manually_charged_price]`"
                                       placeholder="0.00"
                                       :aria-label="'Line ' + (index + 1) + ' price'">
                            </div>
                        </div>

                        {{-- Bulk draw: litres of oil, kilos of gas. A stock record only —
                             this figure never reaches the customer's invoice. --}}
                        <div class="mt-2 flex items-center gap-2 rounded-lg bg-slate-50 px-2.5 py-2"
                             x-show="isMeasured(line)" x-cloak>
                            <span class="shrink-0 text-[11px] font-bold tracking-wide text-slate-500 uppercase">Dispensed</span>
                            <span class="shrink-0 text-[11px] font-medium text-slate-400">stock only</span>
                            <input type="text" inputmode="decimal" class="pos-input ml-auto w-20 shrink-0 text-right"
                                   x-model="line.dispensed"
                                   :name="`lines[${index}][dispensed_quantity]`"
                                   placeholder="0.0"
                                   :aria-label="'Line ' + (index + 1) + ' amount used'">
                            <span class="w-5 shrink-0 text-xs font-bold text-slate-500" x-text="unitAbbrFor(line)"></span>
                        </div>

                        {{-- Posted for every line; only a custom line types its own name. --}}
                        <input type="hidden" :name="`lines[${index}][type]`" :value="line.mode">
                        <input type="hidden" :name="`lines[${index}][item_id]`" :value="line.item_id">
                        <template x-if="line.mode !== 'custom'">
                            <input type="hidden" :name="`lines[${index}][item_name]`" :value="line.item_name">
                        </template>
                    </div>
                </template>

                <div x-show="lines.length === 0" x-cloak class="grid place-items-center px-6 py-16 text-center">
                    <p class="text-3xl">&#129534;</p>
                    <p class="mt-3 font-bold text-slate-500">The ticket is empty.</p>
                    <p class="mt-1 text-sm font-medium text-slate-400">Tap a product to start the bill.</p>
                </div>
            </div>

            {{-- Add-ons and the running total --}}
            <div class="shrink-0 border-t border-slate-200 bg-slate-50">
                <div class="space-y-2 px-4 py-3">
                    <div class="flex h-9 items-center justify-between gap-3 text-sm font-semibold text-slate-500">
                        <span>Items &amp; repairs</span>
                        <span class="font-mono tabular-nums" x-text="money(lineSubtotalCents)"></span>
                    </div>

                    <div class="flex h-9 items-center justify-between gap-3">
                        <label class="text-sm font-semibold text-slate-500" for="labor_charge">Labor</label>
                        <input id="labor_charge" name="labor_charge" type="text" inputmode="decimal"
                               class="pos-input-money w-32" placeholder="0.00" x-model="labor">
                    </div>

                    <div class="flex h-9 items-center justify-between gap-3">
                        <label class="text-sm font-semibold text-slate-500" for="misc_charge">Miscellaneous</label>
                        <input id="misc_charge" name="misc_charge" type="text" inputmode="decimal"
                               class="pos-input-money w-32" placeholder="0.00" x-model="misc">
                    </div>
                </div>

                <div class="flex items-end justify-between gap-3 bg-slate-900 px-4 py-3 text-white">
                    <div class="min-w-0">
                        <p class="text-[11px] font-bold tracking-widest text-amber-400 uppercase">Total payable</p>
                        <p class="truncate text-[11px] font-medium text-slate-400">Exactly what was typed. Nothing else.</p>
                    </div>
                    <p class="shrink-0 font-mono text-3xl leading-none font-black tabular-nums" x-text="money(totalCents)"></p>
                </div>

                <div class="p-3">
                    <button type="submit" class="btn-primary w-full !py-3.5 !text-base" :disabled="submitting">
                        <span x-show="! submitting">Charge &amp; print invoice</span>
                        <span x-show="submitting" x-cloak>Saving&hellip;</span>
                    </button>
                </div>
            </div>
        </aside>
    </div>

    {{-- ================================================================== --}}
    {{-- C. Narrow-screen ticket bar                                        --}}
    {{-- ================================================================== --}}
    <button type="button" x-show="! ticketOpen" x-cloak @click="ticketOpen = true"
            class="no-print fixed inset-x-0 bottom-0 z-30 flex items-center gap-3 border-t-4 border-amber-500 bg-slate-900 px-4 py-2.5 text-left text-white lg:hidden">
        <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-amber-500 font-mono text-sm font-black tabular-nums text-slate-900"
              x-text="lines.length"></span>
        <span class="min-w-0 flex-1">
            <span class="block text-[11px] font-bold tracking-widest text-amber-400 uppercase">Total payable</span>
            <span class="block font-mono text-xl leading-tight font-black tabular-nums" x-text="money(totalCents)"></span>
        </span>
        <span class="shrink-0 rounded-lg bg-amber-500 px-4 py-2.5 text-sm font-black tracking-wide text-slate-900 uppercase">View ticket</span>
    </button>

    {{-- ================================================================== --}}
    {{-- D. "Quick add new item" — never leaves this page                   --}}
    {{-- ================================================================== --}}
    <div x-show="quickAdd.open" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/70 p-4"
         @keydown.escape.window="closeQuickAdd()">
        <div class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-2xl"
             @click.outside="closeQuickAdd()">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h3 class="text-lg font-black tracking-tight">Quick add item</h3>
                <button type="button" class="pos-step border-transparent !text-slate-400"
                        @click="closeQuickAdd()" aria-label="Close">&times;</button>
            </div>

            <div class="space-y-4 p-5">
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
                    Saves to inventory and drops straight onto this ticket. Nothing you have typed is lost.
                </p>

                <div>
                    <label class="label" for="qa_name">Item name</label>
                    <input id="qa_name" type="text" class="field" x-model="quickAdd.name"
                           x-ref="quickAddName" @keydown.enter.prevent="saveQuickAdd()"
                           placeholder="e.g. Shell Helix 5W-30">
                    <template x-for="message in quickAdd.errors.name || []" :key="message">
                        <p class="mt-1 text-sm font-semibold text-red-600" x-text="message"></p>
                    </template>
                </div>

                <div>
                    <span class="label">Type</span>
                    <div class="grid grid-cols-2 gap-2">
                        <template x-for="option in [{v:'product',l:'Product'},{v:'repair',l:'Repair / Service'}]" :key="option.v">
                            <button type="button"
                                    class="rounded-lg border-2 px-3 py-2.5 text-sm font-bold"
                                    :class="quickAdd.type === option.v
                                        ? 'border-amber-500 bg-amber-100 text-amber-900'
                                        : 'border-slate-300 bg-white text-slate-700'"
                                    @click="quickAdd.type = option.v"
                                    x-text="option.l"></button>
                        </template>
                    </div>
                    <template x-for="message in quickAdd.errors.type || []" :key="message">
                        <p class="mt-1 text-sm font-semibold text-red-600" x-text="message"></p>
                    </template>
                </div>

                @can('items.set_unit_cost')
                <div>
                    <label class="label" for="qa_cost">Unit cost <span class="text-slate-400 normal-case">(optional)</span></label>
                    <input id="qa_cost" type="text" inputmode="decimal" class="field-money"
                           x-model="quickAdd.unit_cost" placeholder="0.00">
                    <p class="mt-1 text-xs font-medium text-slate-500">A memory aid. It never sets the sale price.</p>
                    <template x-for="message in quickAdd.errors.unit_cost || []" :key="message">
                        <p class="mt-1 text-sm font-semibold text-red-600" x-text="message"></p>
                    </template>
                </div>
                @endcan

                {{-- Repairs never carry stock, so this only exists for products. --}}
                <div x-show="quickAdd.type === 'product'" x-cloak class="space-y-4">
                    <div>
                        <label class="label" for="qa_stock">Opening stock <span class="text-slate-400 normal-case">(optional)</span></label>
                        <input id="qa_stock" type="text" inputmode="numeric" class="field"
                               x-model="quickAdd.stock_level" placeholder="Leave blank to not track stock">
                        <template x-for="message in quickAdd.errors.stock_level || []" :key="message">
                            <p class="mt-1 text-sm font-semibold text-red-600" x-text="message"></p>
                        </template>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <span class="label">Compatibility</span>
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <button type="button"
                                    class="rounded-lg border-2 px-3 py-2.5 text-sm font-bold"
                                    :class="quickAdd.is_universal
                                        ? 'border-amber-500 bg-amber-100 text-amber-900'
                                        : 'border-slate-300 bg-white text-slate-700'"
                                    @click="setQuickAddScope(true)">
                                Universal
                            </button>
                            <button type="button"
                                    class="rounded-lg border-2 px-3 py-2.5 text-sm font-bold"
                                    :class="! quickAdd.is_universal
                                        ? 'border-amber-500 bg-amber-100 text-amber-900'
                                        : 'border-slate-300 bg-white text-slate-700'"
                                    @click="setQuickAddScope(false)">
                                Vehicle specific
                            </button>
                        </div>
                        <template x-for="message in quickAdd.errors.compatibilities || []" :key="message">
                            <p class="mt-2 text-sm font-semibold text-red-600" x-text="message"></p>
                        </template>
                    </div>

                    <div x-show="! quickAdd.is_universal" x-cloak class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-bold text-slate-900">Compatible vehicles</p>
                                <p class="text-xs font-medium text-slate-500">Pick an existing make/model or type a missing one right here.</p>
                            </div>
                            <button type="button" class="btn-ghost !py-2" @click="addCompatibilityRow()">+ Add vehicle</button>
                        </div>

                        <template x-for="(compatibility, index) in quickAdd.compatibilities" :key="compatibility.uid">
                            <div class="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-xs font-black tracking-wide text-slate-500 uppercase"
                                       x-text="'Vehicle ' + (index + 1)"></p>
                                    <button type="button" class="text-xs font-bold text-slate-500 hover:text-slate-900"
                                            x-show="quickAdd.compatibilities.length > 1"
                                            @click="removeCompatibilityRow(compatibility.uid)">
                                        Remove
                                    </button>
                                </div>

                                <div class="grid gap-3 md:grid-cols-2">
                                    <div>
                                        <label class="label" :for="'qa_vehicle_make_' + compatibility.uid">Make</label>
                                        <select class="field !py-2"
                                                :id="'qa_vehicle_make_' + compatibility.uid"
                                                x-model="compatibility.vehicle_make_id"
                                                @change="syncCompatibilityMake(compatibility)">
                                            <option value="">Choose existing make</option>
                                            <template x-for="make in vehicleMakes" :key="'qa-make-' + compatibility.uid + '-' + make.id">
                                                <option :value="String(make.id)" x-text="make.name"></option>
                                            </template>
                                        </select>
                                        <template x-for="message in compatibilityMessages(index, 'vehicle_make_id')" :key="'make-id-' + compatibility.uid + message">
                                            <p class="mt-1 text-sm font-semibold text-red-600" x-text="message"></p>
                                        </template>
                                    </div>

                                    <div>
                                        <label class="label" :for="'qa_vehicle_make_name_' + compatibility.uid">Or new make</label>
                                        <input type="text" class="field !py-2"
                                               :id="'qa_vehicle_make_name_' + compatibility.uid"
                                               x-model="compatibility.vehicle_make_name"
                                               @input="syncTypedCompatibilityMake(compatibility)"
                                               placeholder="Type a missing make">
                                        <template x-for="message in compatibilityMessages(index, 'vehicle_make_name')" :key="'make-name-' + compatibility.uid + message">
                                            <p class="mt-1 text-sm font-semibold text-red-600" x-text="message"></p>
                                        </template>
                                    </div>

                                    <div>
                                        <label class="label" :for="'qa_vehicle_model_' + compatibility.uid">Model</label>
                                        <select class="field !py-2"
                                                :id="'qa_vehicle_model_' + compatibility.uid"
                                                x-model="compatibility.vehicle_model_id"
                                                :disabled="quickAddVehicleModels(compatibility).length === 0">
                                            <option value="" x-text="quickAddVehicleModels(compatibility).length === 0 ? 'Type or choose a make first' : 'Choose existing model'"></option>
                                            <template x-for="model in quickAddVehicleModels(compatibility)" :key="'qa-model-' + compatibility.uid + '-' + model.id">
                                                <option :value="String(model.id)" x-text="model.name"></option>
                                            </template>
                                        </select>
                                        <template x-for="message in compatibilityMessages(index, 'vehicle_model_id')" :key="'model-id-' + compatibility.uid + message">
                                            <p class="mt-1 text-sm font-semibold text-red-600" x-text="message"></p>
                                        </template>
                                    </div>

                                    <div>
                                        <label class="label" :for="'qa_vehicle_model_name_' + compatibility.uid">Or new model</label>
                                        <input type="text" class="field !py-2"
                                               :id="'qa_vehicle_model_name_' + compatibility.uid"
                                               x-model="compatibility.vehicle_model_name"
                                               @input="compatibility.vehicle_model_id = ''"
                                               placeholder="Type a missing model">
                                        <template x-for="message in compatibilityMessages(index, 'vehicle_model_name')" :key="'model-name-' + compatibility.uid + message">
                                            <p class="mt-1 text-sm font-semibold text-red-600" x-text="message"></p>
                                        </template>
                                    </div>

                                    <div>
                                        <label class="label" :for="'qa_vehicle_year_from_' + compatibility.uid">Year from</label>
                                        <input type="text" inputmode="numeric" class="field !py-2"
                                               :id="'qa_vehicle_year_from_' + compatibility.uid"
                                               x-model="compatibility.year_from"
                                               placeholder="2000">
                                        <template x-for="message in compatibilityMessages(index, 'year_from')" :key="'year-from-' + compatibility.uid + message">
                                            <p class="mt-1 text-sm font-semibold text-red-600" x-text="message"></p>
                                        </template>
                                    </div>

                                    <div>
                                        <label class="label" :for="'qa_vehicle_year_to_' + compatibility.uid">Year to</label>
                                        <input type="text" inputmode="numeric" class="field !py-2"
                                               :id="'qa_vehicle_year_to_' + compatibility.uid"
                                               x-model="compatibility.year_to"
                                               :placeholder="String(currentYear)">
                                        <template x-for="message in compatibilityMessages(index, 'year_to')" :key="'year-to-' + compatibility.uid + message">
                                            <p class="mt-1 text-sm font-semibold text-red-600" x-text="message"></p>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <p x-show="quickAdd.failed" x-cloak class="rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">
                    Could not reach the server. Your bill is untouched — try again.
                </p>
            </div>

            <div class="flex gap-3 border-t border-slate-200 px-5 py-4">
                <button type="button" class="btn-primary flex-1" @click="saveQuickAdd()" :disabled="quickAdd.saving">
                    <span x-show="! quickAdd.saving">Save &amp; add to ticket</span>
                    <span x-show="quickAdd.saving" x-cloak>Saving&hellip;</span>
                </button>
                <button type="button" class="btn-ghost" @click="closeQuickAdd()">Cancel</button>
            </div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
    // Plain <script> (not a module) so it is defined before Alpine's deferred
    // bundle boots and evaluates x-data.
    window.posCounter = function (config) {
        return {
            items: config.items,
            groups: config.groups,
            vehicleMakes: config.vehicleMakes || [],
            vehicleYears: config.vehicleYears || [],
            currentYear: config.currentYear,
            lines: config.lines,
            labor: config.labor,
            misc: config.misc,
            customer: config.customer,
            customerVehicles: config.customerVehicles,
            recentCustomerVehicles: config.customerVehicles,
            customerVehicleSearchUrl: config.customerVehicleSearchUrl,
            saleUrl: config.saleUrl,
            customerVehicleSearch: '',
            customerVehicleResultsOpen: false,
            searchingCustomerVehicles: false,
            customerVehicleSearchController: null,
            customerOpen: config.customerOpen,

            query: '',
            vehicleFilter: { makeId: '', modelId: '', year: '' },
            activeGroup: 'all',
            lowStockOnly: false,
            ticketOpen: false,
            submitting: false,
            clearArmed: false,
            seq: 0,
            compatibilitySeq: 0,

            // Purely visual acknowledgements: the tile that was just tapped, and
            // the ticket line it landed on.
            poppedId: null,
            flashedUid: null,

            quickAdd: {
                open: false, name: '', type: 'product', unit_cost: '', stock_level: '',
                is_universal: true, compatibilities: [],
                saving: false, failed: false, errors: {}, targetUid: null,
            },

            /* ---------------- boot ---------------- */

            init() {
                this.fitToViewport();

                window.addEventListener('resize', () => this.fitToViewport());

                // "/" jumps to the search box the way every till in the world does.
                // Never while the counter is mid-word in another field.
                window.addEventListener('keydown', (event) => {
                    if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) return;
                    if (this.quickAdd.open) return;

                    const tag = (document.activeElement?.tagName || '').toLowerCase();
                    if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

                    event.preventDefault();
                    this.$refs.search?.focus();
                });
            },

            customerVehicleLabel(profile) {
                return [
                    profile.customer_name || 'Unnamed customer',
                    profile.vehicle_plate,
                    profile.vehicle_model,
                    profile.phone,
                ].filter(Boolean).join(' · ');
            },

            async searchCustomerVehicles() {
                const query = this.customerVehicleSearch.trim();

                this.customerVehicleSearchController?.abort();

                if (query.length < 3) {
                    this.customerVehicles = this.recentCustomerVehicles;
                    this.searchingCustomerVehicles = false;
                    this.customerVehicleResultsOpen = false;
                    return;
                }

                const controller = new AbortController();
                this.customerVehicleSearchController = controller;
                this.customerVehicles = [];
                this.searchingCustomerVehicles = true;
                this.customerVehicleResultsOpen = true;

                try {
                    const response = await fetch(
                        `${this.customerVehicleSearchUrl}?q=${encodeURIComponent(query)}`,
                        { headers: { Accept: 'application/json' }, signal: controller.signal },
                    );

                    if (! response.ok) throw new Error('Search failed');

                    this.customerVehicles = await response.json();
                } catch (error) {
                    if (error.name !== 'AbortError') this.customerVehicles = [];
                } finally {
                    if (this.customerVehicleSearchController === controller) {
                        this.searchingCustomerVehicles = false;
                    }
                }
            },

            applyCustomerVehicle(profile) {
                this.customer.customer_name = profile.customer_name || '';
                this.customer.phone = profile.phone || '';
                this.customer.vehicle_model = profile.vehicle_model || '';
                this.customer.vehicle_plate = profile.vehicle_plate || '';
                this.customer.mileage = profile.mileage === null ? '' : String(profile.mileage);
                this.customerVehicleSearch = this.customerVehicleLabel(profile);
                this.customerVehicleResultsOpen = false;
            },

            get vehicleFilterActive() {
                return this.vehicleFilter.makeId !== ''
                    || this.vehicleFilter.modelId !== ''
                    || this.vehicleFilter.year !== '';
            },

            get vehicleModelsForFilter() {
                const selectedMake = this.findVehicleMake(this.vehicleFilter.makeId);

                return selectedMake?.vehicle_models || [];
            },

            get vehicleYearsDescending() {
                return [...this.vehicleYears].reverse();
            },

            findVehicleMake(makeId) {
                return this.vehicleMakes.find(make => String(make.id) === String(makeId));
            },

            findVehicleMakeByName(name) {
                const needle = this.normaliseName(name).toLowerCase();

                if (needle === '') {
                    return null;
                }

                return this.vehicleMakes.find(make => make.name.toLowerCase() === needle) || null;
            },

            syncVehicleFilterModel() {
                const knownIds = this.vehicleModelsForFilter.map(model => String(model.id));

                if (! knownIds.includes(String(this.vehicleFilter.modelId))) {
                    this.vehicleFilter.modelId = '';
                }
            },

            resetVehicleFilters() {
                this.vehicleFilter.makeId = '';
                this.vehicleFilter.modelId = '';
                this.vehicleFilter.year = '';
            },

            /**
             * Size the till to the window so the three panes scroll inside
             * themselves and the page itself never does.
             *
             * Measured rather than hard-coded, because the header above it is
             * built from the module registry and changes height with the shop's
             * enabled modules — a magic number here would be wrong the moment a
             * module is switched on.
             */
            fitToViewport() {
                const shell = this.$refs.shell;
                if (! shell) return;

                // Below the desktop breakpoint the ticket is a sheet, so the page
                // is allowed to scroll normally.
                if (window.innerWidth < 1024) {
                    shell.style.flex = '';
                    shell.style.height = '';
                    return;
                }

                // Measure before the last height is applied, or every resize
                // would read back the size it was already given.
                shell.style.flex = '';
                shell.style.height = '';

                const top = shell.getBoundingClientRect().top + window.scrollY;
                const footer = document.querySelector('body > footer');
                const reserve = (footer ? footer.offsetHeight : 0) + 12;

                // flex-1 would stretch the shell past whatever height is set
                // here, so growing has to be switched off along with it.
                shell.style.flex = 'none';
                shell.style.height = Math.max(420, window.innerHeight - top - reserve) + 'px';
            },

            /* ---------------- the wall ---------------- */

            /**
             * Category rail entries. "All items" always leads, then the shelves
             * the shop actually has, each carrying how many of them survive the
             * current search — a zero tells the counter to stop looking there.
             */
            get railGroups() {
                const pool = this.vehicleFilteredItems;

                return [{ key: 'all', label: 'All items', glyph: '▦', count: pool.length }].concat(
                    this.groups.map(group => ({
                        key: group.key,
                        label: group.label,
                        glyph: group.glyph,
                        count: pool.filter(item => item.group === group.key).length,
                    }))
                );
            },

            get lowStockCount() {
                return this.vehicleFilteredItems.filter(item => item.is_low_on_stock).length;
            },

            get searchedItems() {
                const needle = this.query.trim().toLowerCase();

                return needle === ''
                    ? this.items
                    : this.items.filter(item => item.haystack.includes(needle));
            },

            get vehicleFilteredItems() {
                return this.searchedItems.filter(item => this.compatibleWithVehicle(item));
            },

            get visibleItems() {
                return this.vehicleFilteredItems.filter(item => {
                    if (this.activeGroup !== 'all' && item.group !== this.activeGroup) return false;
                    if (this.lowStockOnly && ! item.is_low_on_stock) return false;

                    return true;
                });
            },

            get emptyWallTitle() {
                if (this.query.trim() === '' && this.vehicleFilterActive) {
                    return 'No item fits this vehicle.';
                }

                return this.query.trim() === ''
                    ? 'Nothing on this shelf.'
                    : 'No item matches “' + this.query.trim() + '”.';
            },

            resetFilters() {
                this.query = '';
                this.resetVehicleFilters();
                this.activeGroup = 'all';
                this.lowStockOnly = false;
            },

            compatibleWithVehicle(item) {
                if (! this.vehicleFilterActive) return true;
                if (item.type !== 'product') return true;
                if (item.is_universal) return true;

                return item.compatibilities.some(compatibility => this.compatibilityMatchesVehicle(compatibility));
            },

            compatibilityMatchesVehicle(compatibility) {
                if (this.vehicleFilter.makeId !== '' && String(compatibility.vehicle_make_id) !== String(this.vehicleFilter.makeId)) {
                    return false;
                }

                if (this.vehicleFilter.modelId !== '' && String(compatibility.vehicle_model_id) !== String(this.vehicleFilter.modelId)) {
                    return false;
                }

                if (this.vehicleFilter.year !== '') {
                    const year = parseInt(this.vehicleFilter.year, 10);

                    if (compatibility.year_from !== null && compatibility.year_from > year) {
                        return false;
                    }

                    if (compatibility.year_to !== null && compatibility.year_to < year) {
                        return false;
                    }
                }

                return true;
            },

            railClassFor(group) {
                if (this.activeGroup === group.key) return 'pos-rail-btn-on';

                return group.count === 0 ? 'opacity-40' : '';
            },

            /**
             * Shelf colours. Written out as whole class strings rather than
             * assembled from parts, so Tailwind's scanner can see every one of
             * them in this file and keep them in the built stylesheet.
             */
            accentFor(key) {
                return {
                    oil: { chip: 'bg-amber-100 text-amber-700', bar: 'bg-amber-400' },
                    gas: { chip: 'bg-sky-100 text-sky-700', bar: 'bg-sky-400' },
                    part: { chip: 'bg-emerald-100 text-emerald-700', bar: 'bg-emerald-400' },
                    service: { chip: 'bg-violet-100 text-violet-700', bar: 'bg-violet-400' },
                    custom: { chip: 'bg-slate-200 text-slate-600', bar: 'bg-slate-400' },
                }[key] || { chip: 'bg-slate-100 text-slate-600', bar: 'bg-slate-300' };
            },

            /** The one line of small print under a tile's name. */
            tileMeta(item) {
                if (item.type === 'repair') return item.type_label;
                if (item.stock_label === null || item.stock_label === undefined) return 'Stock not tracked';

                return 'In stock ' + item.stock_label;
            },

            /* ---------------- the ticket ---------------- */

            newUid() {
                return 'line-' + (++this.seq) + '-' + this.lines.length;
            },

            /**
             * One tap bills the item. A second tap on the same tile adds another
             * unit to the line already on the ticket rather than opening a
             * duplicate — the price stays where the counter typed it, because it
             * is the total for the line, not a rate.
             */
            addItem(item) {
                this.pop(item.id);

                const existing = this.lines.find(line =>
                    line.mode !== 'custom' && String(line.item_id) === String(item.id)
                );

                if (existing) {
                    this.stepQty(existing, 1);
                    this.flash(existing.uid);

                    return;
                }

                const line = {
                    uid: this.newUid(),
                    mode: item.type,
                    item_id: String(item.id),
                    item_name: item.name,
                    qty: '1',
                    dispensed: '',
                    price: '',
                };

                this.lines.push(line);
                this.flash(line.uid);
                this.focusPrice(line.uid);
            },

            /** Work that is not in inventory: a description and a price, nothing else. */
            addCustomLine() {
                const line = {
                    uid: this.newUid(), mode: 'custom', item_id: '', item_name: '',
                    qty: '1', dispensed: '', price: '',
                };

                this.lines.push(line);
                this.flash(line.uid);
                this.$nextTick(() => this.scrollTicketToEnd());
            },

            /** Enter in the search box bills the top hit, so a barcode-speed typist never lifts a hand. */
            addFirstMatch() {
                const first = this.visibleItems[0];

                if (! first) return;

                this.addItem(first);
                this.query = '';
            },

            removeLine(uid) {
                this.lines = this.lines.filter(line => line.uid !== uid);
            },

            /**
             * Two taps to empty a ticket. A counter leaning on the screen must
             * not be able to wipe a half-typed bill with one stray touch.
             */
            clearTicket() {
                if (! this.clearArmed) {
                    this.clearArmed = true;
                    setTimeout(() => { this.clearArmed = false; }, 3000);

                    return;
                }

                this.lines = [];
                this.clearArmed = false;
            },

            stepQty(line, delta) {
                const next = Math.max(1, Math.min(9999, (parseInt(line.qty, 10) || 0) + delta));

                line.qty = String(next);
            },

            countOf(itemId) {
                return this.lines
                    .filter(line => line.mode !== 'custom' && String(line.item_id) === String(itemId))
                    .reduce((sum, line) => sum + (parseInt(line.qty, 10) || 0), 0);
            },

            /** The line's small print: what kind of thing it is, and what is left on the shelf. */
            lineMeta(line) {
                if (line.mode === 'custom') return 'Custom line';

                const option = this.optionFor(line);

                if (! option) return line.mode === 'repair' ? 'Repair / Service' : 'Product';
                if (option.stock_label === null || option.stock_label === undefined) return option.type_label;

                return option.type_label + ' · ' + option.stock_label + ' left';
            },

            groupOf(line) {
                return line.mode === 'custom' ? 'custom' : (this.optionFor(line)?.group || 'part');
            },

            glyphOf(line) {
                return line.mode === 'custom' ? '✎' : (this.optionFor(line)?.glyph || '📦');
            },

            /** Oil and gas are poured, so the counter records how much came out. */
            isMeasured(line) {
                return this.optionFor(line)?.is_measured === true;
            },

            unitAbbrFor(line) {
                return this.optionFor(line)?.unit_abbreviation ?? '';
            },

            optionFor(line) {
                return this.items.find(item => String(item.id) === String(line.item_id));
            },

            stockIsLowFor(line) {
                return Boolean(this.optionFor(line)?.is_low_on_stock);
            },

            /* ---------------- acknowledgement ---------------- */

            pop(itemId) {
                this.poppedId = itemId;
                setTimeout(() => { if (this.poppedId === itemId) this.poppedId = null; }, 240);
            },

            flash(uid) {
                this.flashedUid = uid;
                setTimeout(() => { if (this.flashedUid === uid) this.flashedUid = null; }, 900);
            },

            /**
             * Every price on this screen is typed by hand, so the cursor belongs
             * in the price box the moment a line appears. Skipped on a small
             * screen, where the ticket is behind a sheet and the on-screen
             * keyboard would cover the products.
             */
            focusPrice(uid) {
                if (window.innerWidth < 1024) return;

                this.$nextTick(() => {
                    const field = this.$refs.shell?.querySelector('[data-price-uid="' + uid + '"]');

                    field?.focus();
                    field?.select();
                    this.scrollTicketToEnd();
                });
            },

            scrollTicketToEnd() {
                const field = this.$refs.shell?.querySelector('[data-price-uid]:last-of-type');

                field?.closest('.pos-scroll')?.scrollTo({ top: 999999, behavior: 'smooth' });
            },

            /* ---------------- who is being billed ---------------- */

            get customerHeadline() {
                const name = (this.customer.customer_name || '').trim();
                const phone = (this.customer.phone || '').trim();

                if (name === '' && phone === '') return 'Walk-in customer';

                return [name || 'Walk-in customer', phone].filter(Boolean).join(' · ');
            },

            get vehicleHeadline() {
                const parts = [
                    (this.customer.vehicle_plate || '').trim(),
                    (this.customer.vehicle_model || '').trim(),
                ].filter(Boolean);

                return parts.length ? parts.join(' · ') : 'No vehicle recorded';
            },

            /* ---------------- money (integer cents, mirrors the server) ---------------- */

            // This is a line-for-line mirror of App\Support\SaleTotalCalculator.
            // Read the contract in that class's docblock before changing a
            // character here: the two regexes below are copied from its PATTERN
            // and GROUPED_PATTERN constants, and tests/Feature/MoneyParityTest
            // fails the build if this file and that class ever disagree about
            // what an amount is. The screen must quote exactly what the invoice
            // will say.

            // Fold a human's thousands separators away: "1,234.56" -> "1234.56".
            // Anything that is not well-formed grouping is returned untouched so
            // toCents() refuses it, exactly as the server does.
            normalise(value) {
                const text = String(value).replace(/\u00A0/g, ' ').trim();
                const grouped = /^([+-]?)(\d{1,3}(?:[, ]\d{3})+)(\.\d*)?$/.exec(text);

                return grouped ? grouped[1] + grouped[2].replace(/[, ]/g, '') + (grouped[3] || '') : text;
            },

            toCents(value) {
                if (value === null || value === undefined) return 0;

                const text = this.normalise(value);
                // No exponent part on purpose: "1e3" is a typo, not a thousand.
                if (! /^[+-]?(\d+(\.\d*)?|\.\d+)$/.test(text)) return 0;

                const negative = text.startsWith('-');
                const body = text.replace(/^[+-]/, '');

                const [wholeRaw, fractionRaw = ''] = body.split('.');
                // Leading zeroes are free ("007"), so strip them before deciding
                // whether the number is absurdly long.
                const whole = wholeRaw.replace(/^0+/, '');
                if (whole.length > 12) return 0;

                const fraction = (fractionRaw + '000').slice(0, 3);
                let cents = (parseInt(whole || '0', 10) * 100) + parseInt(fraction.slice(0, 2), 10);
                if (parseInt(fraction[2], 10) >= 5) cents += 1;

                return negative ? -cents : cents;
            },

            // The one deliberate difference from the server: the screen groups
            // thousands for readability ("1,234.56") where the stored and
            // printed value is canonical ("1234.56"). The paisa are identical.
            money(cents) {
                const sign = cents < 0 ? '-' : '';
                const abs = Math.abs(cents);
                const whole = Math.floor(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                return sign + whole + '.' + String(abs % 100).padStart(2, '0');
            },

            get lineSubtotalCents() {
                return this.lines.reduce((sum, line) => sum + this.toCents(line.price), 0);
            },

            get totalCents() {
                return this.lineSubtotalCents + this.toCents(this.labor) + this.toCents(this.misc);
            },

            /* ---------------- on-the-fly item creation ---------------- */

            normaliseName(value) {
                return String(value ?? '').replace(/\s+/g, ' ').trim();
            },

            newCompatibilityRow() {
                this.compatibilitySeq += 1;

                return {
                    uid: 'compatibility-' + this.compatibilitySeq,
                    vehicle_make_id: '',
                    vehicle_make_name: '',
                    vehicle_model_id: '',
                    vehicle_model_name: '',
                    year_from: '',
                    year_to: '',
                };
            },

            addCompatibilityRow() {
                this.quickAdd.compatibilities.push(this.newCompatibilityRow());
            },

            removeCompatibilityRow(uid) {
                this.quickAdd.compatibilities = this.quickAdd.compatibilities.filter(compatibility => compatibility.uid !== uid);

                if (this.quickAdd.compatibilities.length === 0) {
                    this.addCompatibilityRow();
                }
            },

            setQuickAddScope(isUniversal) {
                this.quickAdd.is_universal = isUniversal;

                if (! isUniversal && this.quickAdd.compatibilities.length === 0) {
                    this.addCompatibilityRow();
                }
            },

            quickAddVehicleModels(compatibility) {
                if (compatibility.vehicle_make_id !== '') {
                    return this.findVehicleMake(compatibility.vehicle_make_id)?.vehicle_models || [];
                }

                return this.findVehicleMakeByName(compatibility.vehicle_make_name)?.vehicle_models || [];
            },

            syncCompatibilityMake(compatibility) {
                compatibility.vehicle_make_name = '';

                const knownIds = this.quickAddVehicleModels(compatibility).map(model => String(model.id));

                if (! knownIds.includes(String(compatibility.vehicle_model_id))) {
                    compatibility.vehicle_model_id = '';
                }
            },

            syncTypedCompatibilityMake(compatibility) {
                compatibility.vehicle_make_id = '';
                compatibility.vehicle_model_id = '';
            },

            compatibilityMessages(index, field) {
                return this.quickAdd.errors[`compatibilities.${index}.${field}`] || [];
            },

            quickAddPayload() {
                return {
                    name: this.quickAdd.name,
                    type: this.quickAdd.type,
                    unit_cost: this.quickAdd.unit_cost,
                    stock_level: this.quickAdd.type === 'product' ? this.quickAdd.stock_level : '',
                    is_universal: this.quickAdd.type === 'product' ? this.quickAdd.is_universal : undefined,
                    compatibilities: this.quickAdd.type === 'product' && ! this.quickAdd.is_universal
                        ? this.quickAdd.compatibilities.map(compatibility => ({
                            vehicle_make_id: compatibility.vehicle_make_id,
                            vehicle_make_name: this.normaliseName(compatibility.vehicle_make_name),
                            vehicle_model_id: compatibility.vehicle_model_id,
                            vehicle_model_name: this.normaliseName(compatibility.vehicle_model_name),
                            year_from: compatibility.year_from,
                            year_to: compatibility.year_to,
                        }))
                        : [],
                };
            },

            quickAddNeedsVehicleRefresh() {
                return this.quickAdd.type === 'product'
                    && ! this.quickAdd.is_universal
                    && this.quickAdd.compatibilities.some(compatibility =>
                        this.normaliseName(compatibility.vehicle_make_name) !== ''
                        || this.normaliseName(compatibility.vehicle_model_name) !== ''
                    );
            },

            async refreshVehicleMakes() {
                try {
                    const response = await fetch(this.saleUrl, {
                        headers: {
                            'Accept': 'text/html',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (! response.ok) {
                        return;
                    }

                    const html = await response.text();
                    const config = this.saleScreenConfig(html);

                    if (Array.isArray(config?.vehicleMakes)) {
                        this.vehicleMakes = config.vehicleMakes;
                    }
                } catch (error) {
                    // Keep the created item on screen even if the follow-up
                    // vehicle option refresh cannot be parsed.
                }
            },

            saleScreenConfig(html) {
                const page = new DOMParser().parseFromString(html, 'text/html');
                const shell = page.querySelector('form[x-data]');
                const expression = shell?.getAttribute('x-data') || '';
                const prefix = "posCounter(JSON.parse('";
                const suffix = "'))";

                if (! expression.startsWith(prefix) || ! expression.endsWith(suffix)) {
                    return null;
                }

                return JSON.parse(expression.slice(prefix.length, expression.length - suffix.length));
            },

            openQuickAdd(line) {
                this.quickAdd.targetUid = line ? line.uid : null;
                this.quickAdd.type = line && line.mode === 'repair' ? 'repair' : 'product';
                this.quickAdd.name = this.query.trim();
                this.quickAdd.unit_cost = '';
                this.quickAdd.stock_level = '';
                this.quickAdd.is_universal = true;
                this.quickAdd.compatibilities = [];
                this.quickAdd.errors = {};
                this.quickAdd.failed = false;
                this.quickAdd.open = true;
                this.$nextTick(() => this.$refs.quickAddName?.focus());
            },

            closeQuickAdd() {
                this.quickAdd.open = false;
                this.quickAdd.saving = false;
            },

            async saveQuickAdd() {
                if (this.quickAdd.saving) return;

                this.quickAdd.saving = true;
                this.quickAdd.errors = {};
                this.quickAdd.failed = false;

                try {
                    const shouldRefreshVehicleMakes = this.quickAddNeedsVehicleRefresh();
                    const response = await fetch(config.quickAddUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify(this.quickAddPayload()),
                    });

                    if (response.status === 422) {
                        this.quickAdd.errors = (await response.json()).errors || {};
                        return;
                    }

                    if (! response.ok) {
                        this.quickAdd.failed = true;
                        return;
                    }

                    const created = (await response.json()).data;

                    // 1. Onto the wall, so every future bill can reach it too.
                    this.items.push(created);
                    this.items.sort((a, b) => a.name.localeCompare(b.name));

                    if (shouldRefreshVehicleMakes) {
                        await this.refreshVehicleMakes();
                    }

                    // 2. Onto the ticket. A row that asked for it is filled in
                    //    place; otherwise the new item is simply billed.
                    const line = this.lines.find(candidate => candidate.uid === this.quickAdd.targetUid);

                    if (line) {
                        line.mode = created.type;
                        line.item_id = String(created.id);
                        line.item_name = created.name;
                        this.flash(line.uid);
                        this.focusPrice(line.uid);
                    } else {
                        this.addItem(created);
                    }

                    // The counter searched for something that did not exist, then
                    // created it. Clearing the box puts it back on the full wall.
                    this.query = '';
                    this.closeQuickAdd();
                } catch (error) {
                    this.quickAdd.failed = true;
                } finally {
                    this.quickAdd.saving = false;
                }
            },
        };
    };
</script>
@endpush
