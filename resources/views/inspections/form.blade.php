{{--
    The inspection sheet. Built for a wall-mounted tablet and oily gloves:
    every verdict is a full-height button, never a dropdown.

    @param \App\Models\Inspection $inspection
    @param array<string, \App\Models\InspectionItem> $recorded
    @param array<string, array<int, \App\Enums\InspectionPoint>> $groups
    @param array<int, \App\Enums\InspectionStatus> $statuses
    @param string $action
    @param string $method  POST or PUT
    @param string $submit
--}}
<form method="POST" action="{{ $action }}" class="space-y-5" x-data @submit="$event.submitter.disabled = true">
    @csrf
    @if ($method === 'PUT')
        @method('PUT')
    @endif

    @if ($errors->any())
        <div class="rounded-lg border-2 border-red-300 bg-red-50 px-4 py-3 font-semibold text-red-900">
            <p class="mb-1 font-black uppercase tracking-wide">Check the sheet</p>
            <ul class="list-inside list-disc text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="card p-4">
        <h2 class="mb-3 text-lg font-black tracking-tight">Vehicle</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label class="label" for="vehicle_plate">Licence plate <span class="text-red-600">*</span></label>
                <input id="vehicle_plate" name="vehicle_plate" type="text" required autocomplete="off"
                       class="field !py-3.5 !text-lg uppercase"
                       value="{{ old('vehicle_plate', $inspection->vehicle_plate) }}">
            </div>
            <div>
                <label class="label" for="vehicle_model">Vehicle model / year</label>
                <input id="vehicle_model" name="vehicle_model" type="text" class="field !py-3.5"
                       value="{{ old('vehicle_model', $inspection->vehicle_model) }}">
            </div>
            <div>
                <label class="label" for="mileage">Mileage at inspection (km)</label>
                <input id="mileage" name="mileage" type="number" min="0" step="1" inputmode="numeric"
                       class="field-money !py-3.5" value="{{ old('mileage', $inspection->mileage) }}">
            </div>
            <div>
                <label class="label" for="customer_name">Customer</label>
                <input id="customer_name" name="customer_name" type="text" class="field !py-3.5"
                       value="{{ old('customer_name', $inspection->customer_name) }}">
            </div>
            <div>
                <label class="label" for="phone">Mobile number</label>
                <input id="phone" name="phone" type="tel" inputmode="tel" class="field !py-3.5"
                       value="{{ old('phone', $inspection->phone) }}">
            </div>
            <div>
                <label class="label" for="sale_id">Linked invoice (optional)</label>
                <input id="sale_id" name="sale_id" type="number" min="1" step="1" class="field !py-3.5"
                       placeholder="Invoice ID" value="{{ old('sale_id', $inspection->sale_id) }}">
            </div>
        </div>
    </section>

    @foreach ($groups as $groupName => $points)
        <section class="card overflow-hidden">
            <h2 class="border-b-2 border-slate-200 bg-slate-50 px-4 py-3 text-lg font-black tracking-tight">
                {{ $groupName }}
            </h2>

            <div class="divide-y divide-slate-100">
                @foreach ($points as $point)
                    @php($current = $recorded[$point->value] ?? null)
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-3 px-4 py-4">
                        <p class="min-w-48 flex-1 text-lg font-bold">{{ $point->label() }}</p>

                        <div class="flex gap-2" role="group" aria-label="{{ $point->label() }} verdict">
                            @foreach ($statuses as $status)
                                <label class="cursor-pointer">
                                    <input type="radio" class="peer sr-only"
                                           name="points[{{ $point->value }}][status]"
                                           value="{{ $status->value }}"
                                           @checked(old("points.{$point->value}.status", $current?->status?->value) === $status->value)>
                                    <span class="inline-flex min-h-12 min-w-24 items-center justify-center rounded-lg
                                                 border-2 border-slate-300 bg-white px-4 text-sm font-bold uppercase
                                                 tracking-wide text-slate-600 transition select-none
                                                 hover:bg-slate-100 peer-focus-visible:ring-4 peer-focus-visible:ring-amber-200
                                                 {{ $status->tapTargetClasses() }}">
                                        {{ $status->label() }}
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        <input type="text" maxlength="500"
                               name="points[{{ $point->value }}][note]"
                               class="field min-w-56 flex-1 basis-full lg:basis-64"
                               placeholder="Note (optional)"
                               aria-label="{{ $point->label() }} note"
                               value="{{ old("points.{$point->value}.note", $current?->note) }}">
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    <section class="card p-4">
        <label class="label" for="notes">Overall note</label>
        <textarea id="notes" name="notes" rows="3" maxlength="2000" class="field"
                  placeholder="Anything the customer should hear about, in plain words.">{{ old('notes', $inspection->notes) }}</textarea>
        <p class="mt-2 text-xs font-semibold text-slate-400">
            Condition only &mdash; no prices. What it costs is the counter&rsquo;s call.
        </p>
    </section>

    <div class="sticky bottom-0 flex flex-wrap gap-3 border-t-2 border-slate-200 bg-slate-100/95 py-3">
        <button type="submit" class="btn-primary !px-8 !py-4 !text-base">{{ $submit }}</button>
        <a href="{{ route('inspections.index') }}" class="btn-ghost !py-4">Cancel</a>
    </div>
</form>
