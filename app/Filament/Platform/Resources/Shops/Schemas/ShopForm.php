<?php

namespace App\Filament\Platform\Resources\Shops\Schemas;

use App\Models\Central\Shop;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ShopForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Shop details')
                    ->description('The shop URL and regional settings are fixed when provisioning begins.')
                    ->columns(['default' => 1, 'lg' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('slug')
                            ->label('URL slug')
                            ->required()
                            ->maxLength(48)
                            ->regex('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/')
                            ->unique(table: Shop::class, column: 'slug')
                            ->helperText('Lowercase letters, numbers, and single hyphens only.'),

                        Select::make('timezone')
                            ->options(self::timezoneOptions())
                            ->default('Asia/Karachi')
                            ->searchable()
                            ->required(),

                        TextInput::make('currency')
                            ->label('Currency code')
                            ->default('PKR')
                            ->required()
                            ->length(3)
                            ->regex('/\A[A-Z]{3}\z/')
                            ->helperText('Three uppercase ISO currency letters, such as PKR.'),
                    ]),

                Section::make('First owner')
                    ->description('The temporary password is sent only to the new tenant database.')
                    ->columns(['default' => 1, 'lg' => 2])
                    ->schema([
                        TextInput::make('owner_name')
                            ->label('Owner name')
                            ->required()
                            ->maxLength(150),

                        TextInput::make('owner_username')
                            ->label('Owner username')
                            ->required()
                            ->maxLength(50)
                            ->regex('/\A[A-Za-z0-9_-]+\z/'),

                        TextInput::make('owner_email')
                            ->label('Owner email')
                            ->email()
                            ->maxLength(255),

                        TextInput::make('temporary_owner_password')
                            ->label('Temporary password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->maxLength(255)
                            ->same('temporary_owner_password_confirmation'),

                        TextInput::make('temporary_owner_password_confirmation')
                            ->label('Confirm temporary password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->dehydrated(false),
                    ]),

                Section::make('Initial features')
                    ->description('Core sales, inventory, and administration remain available automatically.')
                    ->columns(['default' => 1, 'lg' => 2])
                    ->schema([
                        CheckboxList::make('initial_feature_keys')
                            ->label('Optional features')
                            ->options(self::featureOptions())
                            ->descriptions(self::featureDescriptions())
                            ->default(self::defaultFeatureKeys())
                            ->columns(['default' => 1, 'md' => 2])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /** @return array<string, string> */
    private static function timezoneOptions(): array
    {
        $timezones = timezone_identifiers_list();

        return array_combine($timezones, $timezones);
    }

    /** @return array<string, string> */
    private static function featureOptions(): array
    {
        return resolve(ModuleRegistry::class)->all()
            ->reject(static fn (Module $module): bool => $module->isCore())
            ->mapWithKeys(static fn (Module $module): array => [$module->key() => $module->title()])
            ->all();
    }

    /** @return array<string, string> */
    private static function featureDescriptions(): array
    {
        return resolve(ModuleRegistry::class)->all()
            ->reject(static fn (Module $module): bool => $module->isCore())
            ->mapWithKeys(static fn (Module $module): array => [$module->key() => $module->description()])
            ->all();
    }

    /** @return list<string> */
    private static function defaultFeatureKeys(): array
    {
        return resolve(ModuleRegistry::class)->all()
            ->filter(static fn (Module $module): bool => ! $module->isCore() && $module->enabledByDefault())
            ->keys()
            ->values()
            ->all();
    }
}
