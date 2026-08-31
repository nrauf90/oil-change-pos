@extends('layouts.app')
@section('title', 'Edit Supplier')
@section('content')
<div class="mx-auto max-w-3xl">
    <h1 class="text-3xl font-black tracking-tight">Edit {{ $supplier->name }}</h1>
    <form method="POST" action="{{ route('suppliers.update', $supplier) }}" class="card mt-5 p-6">
        @method('PUT')
        @include('suppliers._form', ['submitLabel' => 'Update supplier'])
    </form>
</div>
@endsection
