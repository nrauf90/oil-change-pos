<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose a new password &middot; {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full items-center justify-center bg-slate-900 p-4 font-sans antialiased">

<div class="w-full max-w-sm">
    <div class="mb-6 text-center">
        <span class="mx-auto grid size-14 place-items-center rounded-xl bg-amber-500 text-slate-900">
            <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="size-7" />
        </span>
        <h1 class="mt-4 text-2xl font-black tracking-tight text-white">{{ config('app.name') }}</h1>
    </div>

    <form method="POST" action="{{ route('password.update') }}" class="card space-y-5 p-6">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <h2 class="text-lg font-black tracking-tight">Choose a new password</h2>

        @if ($errors->any())
            <div class="rounded-lg border-2 border-red-300 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        <div>
            <label class="label" for="email">Email address</label>
            <input id="email" name="email" type="email" class="field" required
                   autocomplete="email" value="{{ old('email', $email) }}">
        </div>

        <div>
            <label class="label" for="password">New password</label>
            <input id="password" name="password" type="password" class="field" required
                   autocomplete="new-password" autofocus>
            <p class="mt-1 text-xs font-medium text-slate-500">At least 8 characters.</p>
        </div>

        <div>
            <label class="label" for="password_confirmation">Repeat new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password"
                   class="field" required autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary w-full">Change password</button>
    </form>
</div>

</body>
</html>
