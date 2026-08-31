@csrf
<div class="grid gap-5 sm:grid-cols-2">
    <div>
        <label class="label" for="category">Expense category</label>
        <select id="category" name="category" class="field" required>
            <option value="">Choose a category&hellip;</option>
            @foreach ($categories as $category)
                <option value="{{ $category->value }}"
                    @selected(old('category', $expense?->category?->value) === $category->value)>{{ $category->label() }}</option>
            @endforeach
        </select>
        @error('category') <p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="label" for="amount">Amount</label>
        <input id="amount" name="amount" type="text" inputmode="decimal" class="field-money" required
               placeholder="0.00" value="{{ old('amount', $expense?->amount) }}">
        @error('amount') <p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p> @enderror
    </div>

    <div class="sm:col-span-2">
        <label class="label" for="description">Description <span class="font-medium normal-case text-slate-400">(optional)</span></label>
        <input id="description" name="description" type="text" class="field" maxlength="500"
               placeholder="What was the money for?" value="{{ old('description', $expense?->description) }}">
        @error('description') <p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="label" for="spent_at">Spent at</label>
        <input id="spent_at" name="spent_at" type="datetime-local" class="field"
               value="{{ old('spent_at', ($expense?->spent_at ?? now())->format('Y-m-d\TH:i')) }}">
        <p class="mt-1 text-xs font-medium text-slate-500">Back-date this if the money left the drawer earlier.</p>
        @error('spent_at') <p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <span class="label">Logged by</span>
        <p class="field bg-slate-50 text-slate-600">{{ $expense?->user?->name ?? auth()->user()->name }}</p>
    </div>
</div>

<div class="mt-6 flex flex-wrap gap-3">
    <button type="submit" class="btn-primary">{{ $submitLabel }}</button>
    <a href="{{ route('expenses.index') }}" class="btn-ghost">Cancel</a>
</div>
