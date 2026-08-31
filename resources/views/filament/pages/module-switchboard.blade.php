<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-400">
        Every feature of the shop system is a module. Switch one off and its screens disappear from the
        navigation and its pages stop responding &mdash; no code change, no redeploy. Core modules are
        load-bearing and stay on.
    </p>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($this->getModuleRows() as $module)
            <div @class([
                'rounded-xl border p-5 transition',
                'border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900' => $module['enabled'],
                'border-dashed border-gray-300 bg-gray-50 opacity-70 dark:border-white/10 dark:bg-gray-900/40' => ! $module['enabled'],
            ])>
                <div class="flex items-start justify-between gap-4">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="text-2xl leading-none">{!! $module['icon'] !!}</span>
                        <div class="min-w-0">
                            <h3 class="flex flex-wrap items-center gap-2 font-semibold text-gray-950 dark:text-white">
                                {{ $module['title'] }}

                                @if ($module['core'])
                                    <x-filament::badge color="gray" size="sm">Core</x-filament::badge>
                                @elseif ($module['enabled'])
                                    <x-filament::badge color="success" size="sm">On</x-filament::badge>
                                @else
                                    <x-filament::badge color="danger" size="sm">Off</x-filament::badge>
                                @endif
                            </h3>

                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $module['description'] }}</p>
                        </div>
                    </div>

                    @unless ($module['core'])
                        <x-filament::button
                            size="sm"
                            :color="$module['enabled'] ? 'danger' : 'success'"
                            :outlined="$module['enabled']"
                            :disabled="$module['enabled'] && filled($module['blockedBy'])"
                            wire:click="toggle('{{ $module['key'] }}')"
                            wire:loading.attr="disabled"
                        >
                            {{ $module['enabled'] ? 'Switch off' : 'Switch on' }}
                        </x-filament::button>
                    @endunless
                </div>

                @if (filled($module['dependsOn']))
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        Requires: <span class="font-medium">{{ implode(', ', $module['dependsOn']) }}</span>
                    </p>
                @endif

                @if (! $module['core'] && $module['enabled'] && filled($module['blockedBy']))
                    <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs font-medium text-amber-900 dark:bg-amber-400/10 dark:text-amber-200">
                        Cannot switch off while {{ implode(' and ', $module['blockedBy']) }}
                        {{ count($module['blockedBy']) === 1 ? 'depends' : 'depend' }} on it.
                    </p>
                @endif

                <details class="group mt-3">
                    <summary class="cursor-pointer text-xs font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                        {{ count($module['permissions']) }} permission{{ count($module['permissions']) === 1 ? '' : 's' }}
                    </summary>
                    <ul class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($module['permissions'] as $permission)
                            <li>
                                <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700 dark:bg-white/5 dark:text-gray-300">{{ $permission }}</code>
                            </li>
                        @endforeach
                    </ul>
                </details>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
