@extends('layouts.app')
@section('title', 'Add Supply')
@section('content')
<div class="mx-auto max-w-3xl">
    <a class="text-sm font-bold text-slate-500" href="{{ route('suppliers.show', $supplier) }}">&larr; {{ $supplier->name }}</a>
    <h1 class="mt-1 text-3xl font-black tracking-tight">Add Supply Entry</h1>
    <p class="mt-1 text-sm font-medium text-slate-500">One delivery or paper bill becomes one independently payable entry.</p>
    <form method="POST" enctype="multipart/form-data" action="{{ route('suppliers.supplies.store', $supplier) }}" class="card mt-5 p-6">
        @csrf
        <div class="grid gap-5 sm:grid-cols-2">
            <div><label class="label" for="received_at">Received date</label><input id="received_at" name="received_at" type="date" class="field" required value="{{ old('received_at', now()->toDateString()) }}">@error('received_at') <p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p> @enderror</div>
            <div><label class="label" for="reference_number">Bill / reference number</label><input id="reference_number" name="reference_number" class="field" value="{{ old('reference_number') }}">@error('reference_number') <p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p> @enderror</div>
            <div class="sm:col-span-2"><label class="label" for="items_received">What was received?</label><textarea id="items_received" name="items_received" class="field" rows="4" required placeholder="Material, quantities, brands, pack sizes…">{{ old('items_received') }}</textarea>@error('items_received') <p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p> @enderror</div>
            <div><label class="label" for="total_amount">Paper bill total</label><input id="total_amount" name="total_amount" class="field-money" inputmode="decimal" required value="{{ old('total_amount') }}" placeholder="0.00">@error('total_amount') <p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p> @enderror</div>
            <div><label class="label" for="bill_image">Paper bill picture</label><input id="bill_image" name="bill_image" type="file" accept="image/jpeg,image/png,image/webp" class="field">@error('bill_image') <p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p> @enderror</div>
            <div class="sm:col-span-2"><label class="label" for="notes">Notes</label><textarea id="notes" name="notes" class="field" rows="2">{{ old('notes') }}</textarea>@error('notes') <p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p> @enderror</div>
        </div>
        <div class="mt-6 flex gap-3"><button class="btn-primary">Save supply</button><a class="btn-ghost" href="{{ route('suppliers.show', $supplier) }}">Cancel</a></div>
    </form>
</div>
@endsection
