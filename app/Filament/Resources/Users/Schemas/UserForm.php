<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Role;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Staff member')
                ->description('Shop staff sign in with a username, not an email address.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Full name')
                        ->required()
                        ->maxLength(150),

                    TextInput::make('username')
                        ->required()
                        ->alphaDash()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->helperText('Lowercase, no spaces. This is what they type to sign in.'),

                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        ->maxLength(255)
                        // Required when creating; on edit, an empty box means "leave it alone".
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                        ->helperText(fn (string $operation): string => $operation === 'edit'
                            ? 'Leave blank to keep the current password.'
                            : 'At least 8 characters.'),

                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->disabled(fn (?Model $record): bool => self::isSelf($record))
                        ->rule(self::keepsAnAdminSignedInRule(
                            static fn (mixed $value): bool => (bool) $value,
                            'This is the last active admin. Promote or activate another admin before deactivating this one.',
                        ))
                        ->helperText('Deactivated staff cannot sign in, but their sales history is kept.'),
                ]),

            Section::make('Role')
                ->description('A member of staff holds exactly one role. Permissions follow from it.')
                ->schema([
                    Select::make('role')
                        ->label('Role')
                        ->required()
                        ->native(false)
                        ->disabled(fn (?Model $record): bool => self::isSelf($record))
                        ->rule(self::keepsAnAdminSignedInRule(
                            static fn (mixed $value): bool => $value === Role::Admin->value,
                            'This is the last active admin. Promote another staff member to admin before changing this role.',
                        ))
                        ->options(collect(Role::cases())->mapWithKeys(
                            fn (Role $role) => [$role->value => $role->label()]
                        ))
                        ->helperText(new HtmlString(
                            collect(Role::cases())
                                ->map(fn (Role $role) => '<strong>'.e($role->label()).'</strong> &mdash; '.e($role->description()))
                                ->implode('<br>')
                        ))
                        // Roles live in the spatie pivot, not on the users table.
                        ->formatStateUsing(fn ($record) => $record?->role()?->value)
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Select $component, $record): void {
                            $component->state($record?->role()?->value);
                        }),
                ]),
        ]);
    }

    /**
     * Your own Active toggle and Role select are disabled: flipping either one
     * takes effect on the very next request, and there is no route back in.
     */
    private static function isSelf(?Model $record): bool
    {
        return $record instanceof User && $record->is(auth()->user());
    }

    /**
     * A field-level rule refusing any value that would leave the shop with no
     * admin able to sign in.
     *
     * This is the enforcement, not the ->disabled() above it: Filament's form
     * state is a public Livewire property, so a crafted request can set a
     * disabled field. Validation runs before the record is written and before
     * EditUser::afterSave() syncs roles, so both paths are covered.
     *
     * @param  Closure(mixed): bool  $keepsAdmin  does this value leave the admin in place?
     */
    private static function keepsAnAdminSignedInRule(Closure $keepsAdmin, string $message): Closure
    {
        return static fn (?Model $record): Closure => static function (
            string $attribute,
            mixed $value,
            Closure $fail,
        ) use ($record, $keepsAdmin, $message): void {
            if ($keepsAdmin($value) || ! UserResource::isLastAdmin($record)) {
                return;
            }

            $fail($message);
        };
    }
}
