<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Role details')
                ->description('Give the role a clear name that staff will recognize.')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(125)
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (?Role $record): bool => RoleResource::isBuiltIn($record))
                        ->helperText(fn (?Role $record): string => RoleResource::isBuiltIn($record)
                            ? 'Built-in role names cannot be changed.'
                            : 'For example, Service Adviser or Inventory Lead.'),

                    Textarea::make('description')
                        ->label('Description')
                        ->rows(3)
                        ->maxLength(500)
                        ->disabled(fn (?Role $record): bool => RoleResource::isBuiltIn($record))
                        ->helperText('Optional. Summarize what this role is responsible for.'),
                ]),

            Section::make('Permissions')
                ->description('Choose what staff holding this role can do. Unavailable shop features cannot be granted.')
                ->schema(self::permissionFields()),
        ]);
    }

    /** @return array<int, CheckboxList> */
    private static function permissionFields(): array
    {
        return collect(RoleResource::permissionGroups())
            ->map(function (array $group): CheckboxList {
                $description = e($group['description']);

                if (! $group['enabled']) {
                    $description .= '<br><strong>Unavailable for this shop.</strong>';
                }

                return CheckboxList::make("permission_groups.{$group['key']}")
                    ->label($group['title'])
                    ->hintIcon(RoleResource::moduleIcon($group['key']))
                    ->options($group['options'])
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->bulkToggleable($group['enabled'])
                    ->disabled(fn (?Role $record): bool => ! $group['enabled'] || RoleResource::isAdministrator($record))
                    ->helperText(new HtmlString($description));
            })
            ->values()
            ->all();
    }
}
