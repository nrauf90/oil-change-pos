@extends('layouts.app')
@section('title', 'Add Supplier')
@section('content')
<div class="mx-auto max-w-3xl">
    <h1 class="text-3xl font-black tracking-tight">Add Supplier</h1>
    <p class="mt-1 text-sm font-medium text-slate-500">Create the account before recording deliveries and payments.</p>
    <form method="POST" action="{{ route('suppliers.store') }}" class="card mt-5 p-6">
        @include('suppliers._form', ['supplier' => null, 'submitLabel' => 'Save supplier'])
    </form>
</div>
@endsection
