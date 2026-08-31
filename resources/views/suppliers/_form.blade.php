@csrf
<div class="grid gap-5 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label class="label" for="name">Supplier name</label>
        <input id="name" name="name" class="field" required maxlength="150"
               value="{{ old('name', $supplier?->name) }}" placeholder="Company or supplier name">
        @error('name') <p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="label" for="contact_person">Contact person</label>
        <input id="contact_person" name="contact_person" class="field" maxlength="150"
               value="{{ old('contact_person', $supplier?->contact_person) }}">
        @error('contact_person') <p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="label" for="phone">Phone</label>
        <input id="phone" name="phone" type="tel" class="field" maxlength="40"
               value="{{ old('phone', $supplier?->phone) }}">
        @error('phone') <p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="label" for="email">Email</label>
        <input id="email" name="email" type="email" class="field"
               value="{{ old('email', $supplier?->email) }}">
        @error('email') <p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="label" for="address">Address</label>
        <textarea id="address" name="address" class="field" rows="2">{{ old('address', $supplier?->address) }}</textarea>
        @error('address') <p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p> @enderror
    </div>
    <div class="sm:col-span-2">
        <label class="label" for="notes">Notes</label>
        <textarea id="notes" name="notes" class="field" rows="3" placeholder="Account terms or other useful details">{{ old('notes', $supplier?->notes) }}</textarea>
        @error('notes') <p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p> @enderror
    </div>
</div>
<div class="mt-6 flex gap-3">
    <button class="btn-primary" type="submit">{{ $submitLabel }}</button>
    <a class="btn-ghost" href="{{ $supplier ? route('suppliers.show', $supplier) : route('suppliers.index') }}">Cancel</a>
</div>
