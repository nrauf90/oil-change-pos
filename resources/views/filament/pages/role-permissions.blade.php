<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-400">
        Every guarded action in the shop system is listed below. Tick a box to give that role the action,
        untick it to take it away &mdash; it applies on their next request, no redeploy. The
        <strong>{{ \App\Enums\Role::Admin->label() }}</strong> column is fixed: the owner always holds
        everything, because a role that can be edited down to nothing is a role that can lock you out of
        your own shop.
    </p>

    <div class="grid gap-3 sm:grid-cols-3">
        @foreach ($this->getRoleColumns() as $role)
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <h3 class="flex flex-wrap items-center gap-2 text-sm font-semibold text-gray-950 dark:text-white">
                    {{ $role['label'] }}

                    @if ($role['locked'])
                        <x-filament::badge color="gray" size="sm">Locked</x-filament::badge>
                    @endif
                </h3>

                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ $role['description'] }}</p>

                <x-filament::button
                    class="mt-3"
                    size="xs"
                    color="gray"
                    outlined
                    wire:click="resetToDefaults('{{ $role['value'] }}')"
                    wire:loading.attr="disabled"
                >
                    Reset to defaults
                </x-filament::button>
            </div>
        @endforeach
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-white/10">
                    <th class="px-4 py-3 text-left font-semibold text-gray-950 dark:text-white">Permission</th>

                    @foreach ($this->getRoleColumns() as $role)
                        <th class="w-32 px-4 py-3 text-center font-semibold text-gray-950 dark:text-white">
                            {{ $role['label'] }}
                        </th>
                    @endforeach
                </tr>
            </thead>

            <tbody>
                @foreach ($this->getPermissionGroups() as $group)
                    <tr class="bg-gray-50 dark:bg-white/5">
                        <th
                            colspan="{{ 1 + count($this->getRoleColumns()) }}"
                            class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"
                        >
                            {{ $group['name'] }}
                        </th>
                    </tr>

                    @foreach ($group['permissions'] as $permission)
                        <tr wire:key="permission-{{ $permission['name'] }}" class="border-t border-gray-100 dark:border-white/5">
                            <td class="px-4 py-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700 dark:bg-white/5 dark:text-gray-300">{{ $permission['name'] }}</code>

                                    <span class="text-gray-600 dark:text-gray-400">{{ $permission['label'] }}</span>

                                    @if ($permission['moduleOff'])
                                        {{-- Still listed, never hidden: it comes back the moment the module does. --}}
                                        <x-filament::badge color="gray" size="sm">
                                            Module off &mdash; {{ $permission['module'] }}
                                        </x-filament::badge>
                                    @endif
                                </div>
                            </td>

                            @foreach ($this->getRoleColumns() as $role)
                                @php($cell = $permission['roles'][$role['value']])

                                <td class="px-4 py-2 text-center">
                                    <input
                                        type="checkbox"
                                        class="h-5 w-5 rounded border-gray-300 text-primary-600 disabled:opacity-50 dark:border-white/20 dark:bg-white/5"
                                        wire:key="cell-{{ $role['value'] }}-{{ $permission['name'] }}"
                                        @checked($cell['held'])
                                        @disabled($cell['locked'])
                                        @unless ($cell['locked'])
                                            wire:click="toggle('{{ $role['value'] }}', '{{ $permission['name'] }}')"
                                            wire:loading.attr="disabled"
                                        @endunless
                                        aria-label="{{ $permission['name'] }} for {{ $role['label'] }}"
                                    />
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
