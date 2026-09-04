<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset your password &middot; {{ config('app.name') }}</title>
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

    <form method="POST" action="{{ route('password.email') }}" class="card space-y-5 p-6">
        @csrf

        <h2 class="text-lg font-black tracking-tight">Reset your password</h2>

        @if (session('status'))
            <div class="rounded-lg border-2 border-emerald-300 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border-2 border-red-300 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        <p class="text-sm font-medium text-slate-600">
            Enter the email address on your account and we'll send you a link. It expires in one hour.
        </p>

        <div>
            <label class="label" for="email">Email address</label>
            <input id="email" name="email" type="email" class="field" autofocus required
                   autocomplete="email" value="{{ old('email') }}">
        </div>

        <button type="submit" class="btn btn-primary w-full">Send reset link</button>

        <p class="text-center text-sm font-medium text-slate-500">
            No email address on your account? Ask your shop admin to set a new password for you.
        </p>

        <p class="text-center">
            <a href="{{ route('login') }}" class="text-sm font-bold text-amber-700 hover:underline">
                Back to sign in
            </a>
        </p>
    </form>
</div>

</body>
</html>
