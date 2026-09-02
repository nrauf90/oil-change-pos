<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'POS') &middot; {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full flex-col bg-slate-100 font-sans text-slate-900 antialiased">

@php
    // Driven by the module registry: switching a module off in the admin panel
    // removes its links here with no edit to this file. Each module also says
    // which group its screens belong in — the everyday bar, or the profile menu.
    $nav = app(\App\Modules\ModuleRegistry::class)->groupedNavigationFor(auth()->user());
    $initials = \Illuminate\Support\Str::of(auth()->user()?->name ?? '')
        ->explode(' ')
        ->filter()
        ->take(2)
        ->map(fn (string $part) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($part, 0, 1)))
        ->join('');
@endphp

<header class="no-print sticky top-0 z-30 border-b border-slate-700/60 bg-slate-900 text-white shadow-lg"
        x-data="{ menu: false, account: false }"
        @keydown.escape.window="menu = false; account = false">
    <div class="flex h-16 w-full items-center gap-4 px-4">

        <a href="{{ Route::has('pos.create') ? route('pos.create') : url('/') }}"
           class="flex shrink-0 items-center gap-2.5 text-base font-black tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-amber-500 text-slate-900">
                <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="size-5" />
            </span>
            <span class="hidden leading-tight xl:inline">{{ config('app.name') }}</span>
        </a>

        {{-- Everyday work. One row, never wrapping: anything occasional lives
             in the profile menu instead. --}}
        <nav class="hidden min-w-0 flex-1 items-center gap-0.5 lg:flex" aria-label="Main">
            @foreach ($nav['main'] as $entry)
                @continue (! Route::has($entry['route']))
                @php($active = request()->routeIs($entry['pattern']))
                <a href="{{ route($entry['route']) }}"
                   @if ($active) aria-current="page" @endif
                   class="relative flex items-center gap-2 rounded-lg px-3 py-2 text-[13px] font-bold whitespace-nowrap transition
                          {{ $active ? 'bg-slate-800 text-white' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white' }}">
                    <span data-navigation-icon aria-hidden="true" class="opacity-80">
                        <x-filament::icon :icon="$entry['icon']" class="size-4" />
                    </span>
                    <span>{{ $entry['label'] }}</span>
                    {{-- The active marker is an underline, not a filled block: it
                         reads as "you are here" without shouting over the row. --}}
                    @if ($active)
                        <span class="absolute inset-x-3 -bottom-px h-0.5 rounded-full bg-amber-500"></span>
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="flex flex-1 items-center justify-end gap-2 lg:flex-none">
            @auth
                {{-- Profile menu: who you are, plus the screens you open now and
                     then rather than all shift. --}}
                <div class="relative" @click.outside="account = false">
                    <button type="button" @click="account = ! account"
                            :aria-expanded="account ? 'true' : 'false'"
                            aria-haspopup="menu"
                            class="flex items-center gap-2.5 rounded-xl px-2 py-1.5 transition hover:bg-slate-800">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-amber-500 text-sm font-black text-slate-900">
                            {{ $initials ?: '?' }}
                        </span>
                        <span class="hidden text-left sm:block">
                            <span class="block text-sm leading-tight font-bold">{{ auth()->user()->name }}</span>
                            <span class="block text-[11px] leading-tight font-medium text-slate-400">{{ auth()->user()->role()?->label() }}</span>
                        </span>
                        <x-filament::icon
                            icon="heroicon-o-chevron-down"
                            aria-hidden="true"
                            class="size-3 text-slate-400 transition"
                            x-bind:class="account ? 'rotate-180' : ''"
                        />
                    </button>

                    <div x-show="account" x-cloak x-transition.origin.top.right
                         role="menu"
                         class="absolute right-0 z-40 mt-2 w-60 overflow-hidden rounded-xl border border-slate-200 bg-white text-slate-900 shadow-2xl">

                        <div class="border-b border-slate-100 px-4 py-3">
                            <p class="truncate text-sm font-bold">{{ auth()->user()->name }}</p>
                            <p class="truncate text-xs font-medium text-slate-500">{{ auth()->user()->role()?->label() }}</p>
                        </div>

                        @if ($nav['account'] !== [] || Gate::allows('users.view_any'))
                            <div class="border-b border-slate-100 p-1.5">
                                @foreach ($nav['account'] as $entry)
                                    @continue (! Route::has($entry['route']))
                                    <a href="{{ route($entry['route']) }}" role="menuitem"
                                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-bold transition
                                              {{ request()->routeIs($entry['pattern']) ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                                        <span data-navigation-icon aria-hidden="true" class="grid w-5 place-items-center">
                                            <x-filament::icon :icon="$entry['icon']" class="size-4" />
                                        </span>
                                        <span>{{ $entry['label'] }}</span>
                                    </a>
                                @endforeach

                                @can('users.view_any')
                                    <a href="{{ url('/admin') }}" role="menuitem"
                                       class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900">
                                        <x-filament::icon icon="heroicon-o-cog-6-tooth" aria-hidden="true" class="size-5" />
                                        <span>Admin panel</span>
                                    </a>
                                @endcan
                            </div>
                        @endif

                        <div class="p-1.5">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" role="menuitem"
                                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-bold text-red-600 transition hover:bg-red-50">
                                    <x-filament::icon icon="heroicon-o-arrow-right-start-on-rectangle" aria-hidden="true" class="size-5" />
                                    <span>Sign out</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                {{-- Narrow screens fold the whole bar behind this. --}}
                <button type="button" @click="menu = ! menu"
                        :aria-expanded="menu ? 'true' : 'false'"
                        aria-label="Main menu"
                        class="grid size-10 shrink-0 place-items-center rounded-lg border border-slate-700 text-lg text-slate-300 transition hover:bg-slate-800 hover:text-white lg:hidden">
                    <x-filament::icon icon="heroicon-o-bars-3" aria-hidden="true" class="size-5" />
                </button>
            @endauth
        </div>
    </div>

    {{-- Narrow-screen drawer. Same entries, same order, stacked. --}}
    <nav x-show="menu" x-cloak x-transition class="border-t border-slate-700/60 bg-slate-900 lg:hidden" aria-label="Main">
        <div class="grid w-full gap-1 px-4 py-3 sm:grid-cols-2">
            @foreach ($nav['main'] as $entry)
                @continue (! Route::has($entry['route']))
                <a href="{{ route($entry['route']) }}"
                   class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-bold transition
                          {{ request()->routeIs($entry['pattern']) ? 'bg-amber-500 text-slate-900' : 'text-slate-300 hover:bg-slate-800' }}">
                    <span data-navigation-icon aria-hidden="true" class="grid w-5 place-items-center">
                        <x-filament::icon :icon="$entry['icon']" class="size-5" />
                    </span>
                    <span>{{ $entry['label'] }}</span>
                </a>
            @endforeach
        </div>
    </nav>
</header>

@if (session('status'))
    <div class="no-print mx-auto mt-4 w-full max-w-[1600px] px-4" x-data="{ show: true }" x-show="show" x-transition>
        <div class="flex items-center gap-3 rounded-lg border-2 border-emerald-300 bg-emerald-50 px-4 py-3 font-semibold text-emerald-900">
            <x-filament::icon icon="heroicon-o-check-circle" aria-hidden="true" class="size-5" />
            <span class="flex-1">{{ session('status') }}</span>
            <button type="button" @click="show = false" aria-label="Dismiss" class="text-emerald-700 hover:text-emerald-900">
                <x-filament::icon icon="heroicon-o-x-mark" aria-hidden="true" class="size-5" />
            </button>
        </div>
    </div>
@endif

{{-- Screens that need the full width (the counter) override this. --}}
<main class="@yield('main-class', 'mx-auto w-full max-w-[1600px] flex-1 px-4 py-6')">
    @yield('content')
</main>

<footer class="no-print border-t-2 border-slate-200 bg-white py-4 text-center text-xs font-medium text-slate-400">
    {{ config('app.name') }} &mdash; every price on every invoice is typed by hand at the counter.
</footer>

@stack('scripts')
</body>
</html>
