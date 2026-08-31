@extends('layouts.app')
@section('title', 'Edit expense')

@section('content')
<div class="mx-auto max-w-3xl">
    <h1 class="text-3xl font-black tracking-tight">Edit expense</h1>
    <p class="mt-1 mb-5 text-sm font-medium text-slate-500">
        Correcting a figure here changes the cash drawer for {{ $expense->spent_at->format('D j M Y') }}.
    </p>

    <form method="POST" action="{{ route('expenses.update', $expense) }}" class="card p-6">
        @method('PUT')
        @include('expenses._form', ['submitLabel' => 'Save changes'])
    </form>
</div>
@endsection
