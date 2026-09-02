<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in &middot; {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full items-center justify-center bg-slate-900 p-4 font-sans antialiased">

<div class="w-full max-w-sm">
    <div class="mb-6 text-center">
        <span class="mx-auto grid size-14 place-items-center rounded-xl bg-amber-500 text-slate-900">
            <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="size-7" />
        </span>
        <h1 class="mt-4 text-2xl font-black tracking-tight text-white">{{ config('app.name') }}</h1>
        <p class="mt-1 text-sm font-medium text-slate-400">Oil change &amp; auto repair</p>
    </div>

    <form method="POST" action="{{ route('login.store') }}" class="card space-y-5 p-6">
        @csrf

        <h2 class="text-lg font-black tracking-tight">Sign in</h2>

        @if ($errors->any())
            <div class="rounded-lg border-2 border-red-300 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        <div>
            <label class="label" for="username">Username</label>
            <input id="username" name="username" type="text" class="field" autofocus
                   autocomplete="username" value="{{ old('username') }}" placeholder="counter1">
        </div>

        <div>
            <label class="label" for="password">Password</label>
            <input id="password" name="password" type="password" class="field"
                   autocomplete="current-password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;">
        </div>

        <label class="flex items-center gap-2 text-sm font-semibold text-slate-600">
            <input type="checkbox" name="remember" value="1" class="size-4 rounded border-2 border-slate-300">
            Keep me signed in on this terminal
        </label>

        <button type="submit" class="btn-primary w-full !py-3">Sign in</button>
    </form>

    <p class="mt-4 text-center text-xs font-medium text-slate-500">
        No account? Ask the shop owner to create one for you.
    </p>
</div>

</body>
</html>
