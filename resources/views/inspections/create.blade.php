@extends('layouts.app')
@section('title', 'New Inspection')

@section('content')
<div class="mb-5">
    <h1 class="text-3xl font-black tracking-tight">New Inspection</h1>
    <p class="mt-1 text-sm font-medium text-slate-500">
        Walk around the car and tap a verdict on each point you checked. Leave the rest blank.
    </p>
</div>

@include('inspections.form', [
    'action' => route('inspections.store'),
    'method' => 'POST',
    'submit' => 'Save inspection',
])
@endsection
