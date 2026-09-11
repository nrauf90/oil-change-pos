@extends('layouts.app')
@section('title', 'New item')

@section('content')
<div class="mx-auto max-w-2xl">
    <h1 class="mb-5 text-3xl font-black tracking-tight">New inventory item</h1>

    <form method="POST" action="{{ route('items.store') }}" enctype="multipart/form-data" class="card p-6">
        @csrf
        @include('items._form', ['item' => null, 'types' => $types, 'categories' => $categories])

        <div class="mt-6 flex gap-3">
            <button type="submit" class="btn-primary">Save item</button>
            <a href="{{ route('items.index') }}" class="btn-ghost">Cancel</a>
        </div>
    </form>
</div>
@endsection
