@props([
    'label' => 'Scripts',
    'floating' => true,
    'title' => 'Counter Scripts',
    // Lets a host screen dress the trigger as one of its own controls — the
    // sale screen docks it in the category rail, where a floating dark pill
    // would sit on top of the Charge button.
    'triggerClass' => 'btn-dark !px-5 !py-3 text-base',
    'iconClass' => 'mr-2',
])

@php
    /**
     * Embeddable counter-script drawer.
     *
     * Rendered on the sale screen next to (or inside) the checkout form, so it
     * is deliberately inert: no <form>, no submit button, no window dialogs, no
     * global JS. Its Alpine state lives entirely in the one x-data below and
     * never reads from a parent scope, so the cart component's state is safe.
     */
    $scriptGroups = \App\Support\CounterScripts::all();
    $firstGroup = $scriptGroups[0]['key'];
@endphp

<div {{ $attributes->merge(['class' => 'no-print']) }}
     x-data="{ open: false, tab: @js($firstGroup), q: '' }"
     @keydown.escape.window="open = false">

    {{-- Trigger. Explicitly type="button" so it can never submit a checkout. --}}
    <button type="button"
            @click="open = true"
            :aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="dialog"
            class="{{ $triggerClass }} {{ $floating ? 'fixed bottom-5 right-5 z-40 shadow-xl' : '' }}">
        <span aria-hidden="true" class="{{ $iconClass }}">&#128172;</span>{{ $label }}
    </button>

    {{-- Slide-over --}}
    <div x-show="open"
         style="display: none"
         class="fixed inset-0 z-50 flex"
         role="dialog"
         aria-modal="true"
         aria-label="{{ $title }}">

        <button type="button"
                x-show="open"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @click="open = false"
                aria-label="Close scripts"
                class="absolute inset-0 h-full w-full cursor-default bg-slate-900/60"></button>

        <div x-show="open"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full"
             class="relative ml-auto flex h-full w-full max-w-xl flex-col bg-white shadow-2xl">

            <div class="flex items-center gap-3 border-b-4 border-amber-500 bg-slate-900 px-4 py-4 text-white">
                <h2 class="flex-1 text-xl font-black tracking-tight">{{ $title }}</h2>
                <button type="button"
                        @click="open = false"
                        aria-label="Close scripts"
                        class="grid size-11 place-items-center rounded-lg bg-slate-700 text-2xl font-bold text-white hover:bg-slate-600">
                    &times;
                </button>
            </div>

            <div class="border-b-2 border-slate-200 bg-slate-50 px-4 py-3">
                {{-- No name attribute: nothing here is ever posted with the bill. --}}
                <input type="search"
                       x-model="q"
                       @keydown.enter.prevent
                       placeholder="Search scripts&hellip;"
                       aria-label="Search scripts"
                       class="field !py-3 text-base">

                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($scriptGroups as $group)
                        <button type="button"
                                @click="tab = @js($group['key'])"
                                :class="tab === @js($group['key'])
                                    ? 'bg-amber-500 text-slate-900'
                                    : 'bg-white text-slate-600 hover:bg-slate-200'"
                                class="rounded-lg border-2 border-slate-300 px-4 py-3 text-sm font-bold uppercase tracking-wide">
                            {{ $group['title'] }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="flex-1 overflow-y-auto px-4 py-4">
                @foreach ($scriptGroups as $group)
                    <section x-show="tab === @js($group['key'])" style="display: none">
                        <p class="mb-4 text-sm font-medium text-slate-500">{{ $group['blurb'] }}</p>

                        <div class="space-y-3">
                            @foreach ($group['entries'] as $entry)
                                @php
                                    $haystack = \Illuminate\Support\Str::lower(
                                        $entry['title'].' '.implode(' ', $entry['lines']).' '.($entry['note'] ?? '')
                                    );
                                @endphp
                                <article data-search="{{ $haystack }}"
                                         x-show="q.trim() === '' || $el.dataset.search.includes(q.trim().toLowerCase())"
                                         class="card p-4">
                                    <h3 class="text-lg font-black leading-tight">{{ $entry['title'] }}</h3>

                                    <ul class="mt-3 space-y-2">
                                        @foreach ($entry['lines'] as $line)
                                            <li class="border-l-4 border-amber-400 bg-amber-50/60 px-3 py-2 text-base font-medium leading-relaxed text-slate-800">
                                                &ldquo;{{ $line }}&rdquo;
                                            </li>
                                        @endforeach
                                    </ul>

                                    @if ($entry['note'])
                                        <p class="mt-3 text-sm font-semibold text-slate-500">{{ $entry['note'] }}</p>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>

            <div class="flex items-center gap-3 border-t-2 border-slate-200 bg-slate-50 px-4 py-3">
                <p class="flex-1 text-xs font-medium text-slate-400">Reference only &mdash; nothing here touches the bill. Esc closes.</p>
                <button type="button"
                        @click="q = ''"
                        x-show="q.trim() !== ''"
                        style="display: none"
                        class="btn-ghost !px-4 !py-2">Clear search</button>
            </div>
        </div>
    </div>
</div>
