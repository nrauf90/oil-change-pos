@extends('layouts.app')
@section('title', 'Log an expense')

@section('content')
<div class="mx-auto max-w-3xl">
    <h1 class="text-3xl font-black tracking-tight">Log an expense</h1>
    <p class="mt-1 mb-5 text-sm font-medium text-slate-500">Money out of the drawer, recorded against your name.</p>

    <form method="POST" action="{{ route('expenses.store') }}" class="card p-6">
        @include('expenses._form', ['expense' => null, 'submitLabel' => 'Log expense'])
    </form>
</div>
@endsection
