<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Role;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Role as RoleModel;
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
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Staff member')
                ->description('Shop staff sign in with a username, not an email address.')
                ->columns(['default' => 1, 'md' => 2])
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

                    // Optional, and never used to sign in. Its only job is to
                    // let this person reset their own password without asking
                    // an admin — which matters most for the owner, who has
                    // nobody above them to ask.
                    TextInput::make('email')
                        ->label('Email address')
                        ->email()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText('Optional. Only used for "Forgot your password?" — not for signing in.'),

                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->rule(Password::defaults())
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
                        ->helperText(fn (?Model $record): string => self::isSelf($record)
                            ? 'You cannot deactivate your own signed-in account.'
                            : 'Deactivated staff cannot sign in, but their sales history is kept.'),
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
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => RoleModel::query()
                            ->where('guard_name', 'web')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (RoleModel $role): array => [
                                $role->name => RoleResource::displayName($role),
                            ])
                            ->all())
                        ->helperText(fn (?Model $record): HtmlString|string => self::roleHelperText($record))
                        // Roles live in the spatie pivot, not on the users table.
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Select $component, ?User $record): void {
                            $component->state($record?->roleName());
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

    private static function roleHelperText(?Model $record): HtmlString|string
    {
        if (self::isSelf($record)) {
            return 'You cannot change the role of your own signed-in account.';
        }

        $descriptions = RoleModel::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get()
            ->map(function (RoleModel $role): string {
                $description = RoleResource::displayDescription($role);

                return '<strong>'.e(RoleResource::displayName($role)).'</strong>'
                    .(filled($description) ? ' &mdash; '.e($description) : '');
            })
            ->implode('<br>');

        return new HtmlString($descriptions);
    }

    /**
     * Field-level feedback for a value that would leave no active admin.
     *
     * EditUser repeats this check in its server-side mutation hook because a
     * crafted Livewire request can set disabled form state.
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
