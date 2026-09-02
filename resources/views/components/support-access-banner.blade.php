@php($supportAccess = resolve(\App\Tenancy\SupportAccessContext::class))

@if ($supportAccess->active())
    <div class="no-print relative z-50 border-b border-amber-300 bg-amber-100 px-4 py-3 text-amber-950 shadow-sm"
         role="status" data-support-access-banner>
        <div class="mx-auto flex w-full max-w-[1600px] flex-wrap items-center justify-between gap-3">
            <div>
                <p class="font-black">Viewing shop as super admin</p>
                <p class="text-sm font-semibold">
                    Read-only support view &middot; {{ $supportAccess->shop()->name }} &middot;
                    {{ $supportAccess->platformUser()->name }}
                </p>
            </div>

            <form method="POST" action="{{ route('support-access.exit') }}">
                @csrf
                <button type="submit"
                        class="flex items-center gap-2 rounded-lg bg-amber-950 px-4 py-2 text-sm font-black text-white hover:bg-slate-900">
                    <x-filament::icon icon="heroicon-o-arrow-left-start-on-rectangle" aria-hidden="true" class="size-5" />
                    <span>Exit support view</span>
                </button>
            </form>
        </div>
    </div>
@endif
