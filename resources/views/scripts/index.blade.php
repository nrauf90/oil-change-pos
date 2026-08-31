@extends('layouts.app')
@section('title', 'Counter Scripts')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-3xl font-black tracking-tight">Counter Scripts</h1>
        <p class="mt-1 text-sm font-medium text-slate-500">
            What to say at the counter — for reading through on a quiet morning. The same scripts are one tap away on the sale screen.
        </p>
    </div>
    <a href="{{ Route::has('pos.create') ? route('pos.create') : url('/') }}" class="btn-ghost no-print">Back to the counter</a>
</div>

<nav class="no-print card mb-6 flex flex-wrap gap-2 p-3">
    @foreach ($groups as $group)
        <a href="#{{ $group['key'] }}" class="btn-ghost !px-4 !py-2">{{ $group['title'] }}</a>
    @endforeach
</nav>

<div class="space-y-8">
    @foreach ($groups as $group)
        <section id="{{ $group['key'] }}" class="scroll-mt-24">
            <div class="mb-4">
                <h2 class="text-2xl font-black tracking-tight">{{ $group['title'] }}</h2>
                <p class="mt-1 text-sm font-medium text-slate-500">{{ $group['blurb'] }}</p>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($group['entries'] as $entry)
                    <article class="card flex flex-col p-5">
                        <h3 class="text-lg font-black leading-tight">{{ $entry['title'] }}</h3>

                        <ul class="mt-3 flex-1 space-y-2">
                            @foreach ($entry['lines'] as $line)
                                <li class="border-l-4 border-amber-400 bg-amber-50/60 px-3 py-2 text-base font-medium leading-relaxed text-slate-800">
                                    &ldquo;{{ $line }}&rdquo;
                                </li>
                            @endforeach
                        </ul>

                        @if ($entry['note'])
                            <p class="mt-3 border-t-2 border-slate-100 pt-3 text-sm font-semibold text-slate-500">
                                <span class="pill bg-slate-100 text-slate-600">Remember</span>
                                <span class="ml-1">{{ $entry['note'] }}</span>
                            </p>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @endforeach
</div>

<x-counter-scripts title="Counter Scripts" label="Open drawer" />
@endsection
