<?php

namespace App\Filament\Platform\Resources\PlatformUsers\Schemas;

use App\Filament\Platform\Resources\PlatformUsers\PlatformUserResource;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class PlatformUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Platform user')
                    ->description('Platform users sign in with an email address and have super-admin access.')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label('Full name')
                            ->required()
                            ->maxLength(150),

                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->maxLength(255)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): string => $operation === 'edit'
                                ? 'Leave blank to keep the current password.'
                                : 'At least 8 characters.'),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->disabled(fn (?Model $record): bool => PlatformUserResource::isFinalActiveSuperAdmin($record))
                            ->helperText(fn (?Model $record): string => PlatformUserResource::isFinalActiveSuperAdmin($record)
                                ? 'Activate another super admin before deactivating this account.'
                                : 'Inactive platform users cannot sign in.'),
                    ]),
            ]);
    }
}
