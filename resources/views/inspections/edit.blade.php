@extends('layouts.app')
@section('title', 'Edit Inspection')

@section('content')
<div class="mb-5">
    <h1 class="text-3xl font-black tracking-tight">Edit Inspection</h1>
    <p class="mt-1 text-sm font-medium text-slate-500">
        {{ $inspection->vehicle_plate }} &mdash;
        inspected {{ $inspection->inspected_at?->format('d M Y') }}
        @if ($inspection->inspector)
            by {{ $inspection->inspector->name }}
        @endif
    </p>
</div>

@include('inspections.form', [
    'action' => route('inspections.update', $inspection),
    'method' => 'PUT',
    'submit' => 'Save changes',
])
@endsection
