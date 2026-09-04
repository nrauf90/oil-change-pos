@extends('layouts.app')
@section('title', 'Edit expense')

@section('content')
<div class="mx-auto max-w-3xl">
    <h1 class="text-3xl font-black tracking-tight">Edit expense</h1>
    <p class="mt-1 mb-5 text-sm font-medium text-slate-500">
        Correcting a figure here changes the cash drawer for {{ $expense->spent_at->format('D j M Y') }}.
    </p>

    <form method="POST" enctype="multipart/form-data" action="{{ route('expenses.update', $expense) }}" class="card p-6">
        @method('PUT')
        @include('expenses._form', ['submitLabel' => 'Save changes'])
    </form>

    {{-- Kept out of the form above: a nested <form> is invalid HTML, and each
         receipt needs its own DELETE. --}}
    @if ($expense->receipts->isNotEmpty())
        <div class="card mt-5 p-6">
            <h2 class="text-lg font-black">Receipts on file</h2>
            <p class="mt-1 text-sm font-medium text-slate-500">Proof of where this money went.</p>

            <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ($expense->receipts as $receipt)
                    <li class="flex items-center gap-3 rounded-lg border p-3">
                        <a target="_blank" class="min-w-0 flex-1"
                           href="{{ route('expenses.receipts.show', [$expense, $receipt]) }}">
                            @if ($receipt->isPdf())
                                <span class="pill bg-slate-100 text-slate-700">PDF</span>
                            @else
                                <img class="h-16 w-16 rounded object-cover"
                                     src="{{ route('expenses.receipts.show', [$expense, $receipt]) }}"
                                     alt="Receipt {{ $receipt->original_name }}">
                            @endif
                            <span class="mt-1 block truncate text-sm font-bold text-slate-700">{{ $receipt->original_name }}</span>
                            <span class="block text-xs font-medium text-slate-400">
                                Added by {{ $receipt->user?->name ?? 'Removed user' }}
                            </span>
                        </a>
                        <form method="POST" action="{{ route('expenses.receipts.destroy', [$expense, $receipt]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-ghost text-red-700">Remove</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
@endsection
