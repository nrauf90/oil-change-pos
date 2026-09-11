@extends('layouts.app')
@section('title', 'Edit item')

@section('content')
<div class="mx-auto max-w-2xl">
    <h1 class="mb-5 text-3xl font-black tracking-tight">Edit item</h1>

    <form method="POST" action="{{ route('items.update', $item) }}" enctype="multipart/form-data" class="card p-6">
        @csrf @method('PUT')
        @include('items._form', [
            'item' => $item,
            'types' => $types,
            'categories' => $categories,
            'vehicleMakes' => $vehicleMakes,
            'vehicleModels' => $vehicleModels,
        ])

        <div class="mt-6 flex gap-3">
            <button type="submit" class="btn-primary">Save changes</button>
            <a href="{{ route('items.index') }}" class="btn-ghost">Cancel</a>
        </div>
    </form>
</div>
@endsection
