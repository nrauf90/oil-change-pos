<?php

namespace App\Filament\Platform\Resources\Shops\Pages;

use App\Actions\Tenancy\CollectShopHealth;
use App\Actions\Tenancy\CollectTenantStatistics;
use App\Actions\Tenancy\RecordShopLifecycleActivity;
use App\Actions\Tenancy\RotateShopDatabaseEndpoint;
use App\Enums\DashboardPeriod;
use App\Enums\ShopLifecycleEvent;
use App\Enums\ShopStatus;
use App\Exceptions\TenantDatabaseEndpointRotationException;
use App\Filament\Platform\Resources\Shops\ShopResource;
use App\Filament\Platform\Resources\Shops\Tables\ShopsTable;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopFeature;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use App\Tenancy\SupportAccessManager;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class ViewShop extends ViewRecord
{
    protected static string $resource = ShopResource::class;

    /** @var array<string, int|string|null> */
    public array $tenantStatistics = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $shop = $this->getRecord();

        if (! $shop instanceof Shop) {
            return;
        }

        $platformUser = Auth::guard('platform')->user();

        try {
            $shop->setRelation(
                'healthSnapshot',
                resolve(CollectShopHealth::class)->handle($shop),
            );

            if ($shop->status === ShopStatus::Active) {
                try {
                    $this->tenantStatistics = resolve(CollectTenantStatistics::class)->handle(
                        $shop,
                        DashboardPeriod::Month,
                    );
                } catch (Throwable) {
                    $this->tenantStatistics = [];
                }
            }
        } finally {
            if ($platformUser instanceof PlatformUser) {
                Auth::guard('platform')->setUser($platformUser);
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            self::supportAccessAction(),
            self::rotateDatabaseEndpointAction(),
            self::manageFeaturesAction(),
            ShopsTable::suspendAction(),
            ShopsTable::reactivateAction(),
            ShopsTable::retryProvisioningAction(),
        ];
    }

    private static function rotateDatabaseEndpointAction(): Action
    {
        return Action::make('rotateDatabaseEndpoint')
            ->label('Rotate database endpoint')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('danger')
            ->visible(static fn (Shop $record): bool => in_array(
                $record->status,
                [ShopStatus::Active, ShopStatus::Suspended],
                true,
            ) && $record->database_driver === 'mysql' && $record->database_socket === null)
            ->authorize(static fn (Shop $record): bool => ShopResource::canView($record))
            ->modalHeading(static fn (Shop $record): string => 'Rotate '.$record->name.' database endpoint')
            ->modalDescription('Review the resolved target and confirm this exact rotation.')
            ->fillForm(static function (Shop $record): array {
                $actor = Auth::guard('platform')->user();

                if (! $actor instanceof PlatformUser) {
                    return [
                        'preview_summary' => 'Active super administrator access is required.',
                        'preview_token' => '',
                    ];
                }

                try {
                    $preview = resolve(RotateShopDatabaseEndpoint::class)->preview($actor, $record);

                    return [
                        'preview_summary' => self::databaseEndpointRotationSummary($preview),
                        'preview_token' => $preview['preview_token'],
                    ];
                } catch (TenantDatabaseEndpointRotationException $exception) {
                    return [
                        'preview_summary' => $exception->getMessage(),
                        'preview_token' => '',
                    ];
                } catch (Throwable) {
                    return [
                        'preview_summary' => 'The database endpoint rotation cannot be previewed safely.',
                        'preview_token' => '',
                    ];
                }
            })
            ->modalSubmitActionLabel('Rotate endpoint')
            ->schema([
                Hidden::make('preview_summary')
                    ->dehydrated(false),
                Placeholder::make('resolved_target')
                    ->label('Resolved target')
                    ->content(static fn (Get $get): string => (string) $get('preview_summary')),
                Hidden::make('preview_token'),
                TextInput::make('confirmation')
                    ->label('Type the exact shop slug to confirm')
                    ->helperText(static fn (Shop $record): string => $record->slug)
                    ->required()
                    ->maxLength(63),
            ])
            ->action(static function (Action $action, Shop $record, array $data): void {
                $actor = self::authorizedActor($record);
                $confirmation = $data['confirmation'] ?? null;
                $previewToken = $data['preview_token'] ?? null;

                try {
                    resolve(RotateShopDatabaseEndpoint::class)->handle(
                        $actor,
                        $record,
                        is_string($confirmation) ? $confirmation : '',
                        is_string($previewToken) ? $previewToken : '',
                    );
                } catch (TenantDatabaseEndpointRotationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Database endpoint was not rotated')
                        ->body($exception->getMessage())
                        ->send();
                    $action->failure();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Database endpoint rotated')
                    ->body('The normalized target, tenant marker, and audit trail were reconciled.')
                    ->send();
            });
    }

    private static function supportAccessAction(): Action
    {
        return Action::make('supportAccess')
            ->label('View shop')
            ->icon(Heroicon::OutlinedEye)
            ->color('info')
            ->visible(static fn (Shop $record): bool => $record->status === ShopStatus::Active)
            ->authorize(static fn (Shop $record): bool => ShopResource::canView($record))
            ->modalHeading(static fn (Shop $record): string => "View {$record->name} in support mode")
            ->modalDescription('Opens the shop with read-only access and records an audit session.')
            ->modalSubmitActionLabel('Start read-only view')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(static function (Shop $record, array $data, ViewShop $livewire): void {
                $platformUser = Auth::guard('platform')->user();

                if (! $platformUser instanceof PlatformUser) {
                    throw new AuthorizationException('Active platform administrator authentication is required.');
                }

                $reason = $data['reason'] ?? null;
                $reason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
                $manager = resolve(SupportAccessManager::class);
                $manager->start($platformUser, $record, $reason);
                $livewire->redirect($manager->tenantEntryUrl($record), navigate: false);
            });
    }

    /** @param array<string, mixed> $preview */
    private static function databaseEndpointRotationSummary(array $preview): string
    {
        $old = $preview['old_target'];
        $new = $preview['new_target'];
        $oldHosts = implode(', ', $old['hosts']);
        $newEndpoints = collect($new['endpoints'])
            ->map(static fn (array $endpoint): string => sprintf(
                '%s -> %s:%d',
                $endpoint['host'],
                $endpoint['address'],
                $endpoint['port'],
            ))
            ->join(', ');

        return sprintf(
            'Registered: %s/%s at %s:%d, fingerprint %s. Candidate: %s/%s at %s, fingerprint %s.%s',
            $old['driver'],
            $old['database'],
            $oldHosts,
            $old['port'],
            $old['fingerprint'],
            $new['driver'],
            $new['database'],
            $newEndpoints,
            $new['fingerprint'],
            $preview['pending'] ? ' A previously started rotation will be recovered.' : '',
        );
    }

    private static function manageFeaturesAction(): Action
    {
        return Action::make('manageFeatures')
            ->label('Features')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('warning')
            ->authorize(static fn (Shop $record): bool => ShopResource::canView($record))
            ->modalHeading('Manage platform features')
            ->modalDescription(
                'This ceiling limits what the shop can use. Tenant preferences and saved data are preserved.',
            )
            ->modalSubmitActionLabel('Save features')
            ->fillForm(static fn (Shop $record): array => [
                'feature_keys' => self::platformEnabledFeatureKeys($record),
            ])
            ->schema([
                CheckboxList::make('feature_keys')
                    ->label('Available features')
                    ->options(self::featureOptions())
                    ->descriptions(self::featureDescriptions())
                    ->columns(['default' => 1, 'md' => 2])
                    ->columnSpanFull(),
            ])
            ->action(static function (Action $action, Shop $record, array $data): void {
                self::updateFeatureEntitlements($action, $record, $data);
            });
    }

    /** @return array<string, string> */
    private static function featureOptions(): array
    {
        return self::optionalModules()
            ->mapWithKeys(static fn (Module $module): array => [$module->key() => $module->title()])
            ->all();
    }

    /** @return array<string, string> */
    private static function featureDescriptions(): array
    {
        $registry = resolve(ModuleRegistry::class);

        return self::optionalModules()
            ->mapWithKeys(static function (Module $module) use ($registry): array {
                $requirements = collect($module->dependsOn())
                    ->map(static fn (string $key): string => $registry->find($key)?->title() ?? $key)
                    ->join(', ');
                $description = $module->description();

                if ($requirements !== '') {
                    $description .= ' Requires: '.$requirements.'.';
                }

                return [$module->key() => $description];
            })
            ->all();
    }

    /** @return Collection<string, Module> */
    private static function optionalModules(): Collection
    {
        return resolve(ModuleRegistry::class)
            ->all()
            ->reject(static fn (Module $module): bool => $module->isCore());
    }

    /** @return list<string> */
    private static function platformEnabledFeatureKeys(Shop $shop): array
    {
        $storedStates = ShopFeature::on('central')
            ->where('shop_id', $shop->getKey())
            ->pluck('enabled', 'module_key')
            ->map(static fn (mixed $enabled): bool => (bool) $enabled)
            ->all();

        return self::optionalModules()
            ->filter(static fn (Module $module): bool => $storedStates[$module->key()]
                ?? $module->enabledByDefault())
            ->keys()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $data */
    private static function updateFeatureEntitlements(Action $action, Shop $shop, array $data): void
    {
        $actor = self::authorizedActor($shop);
        $modules = self::optionalModules();
        $submittedKeys = $data['feature_keys'] ?? null;

        if (! is_array($submittedKeys)) {
            self::haltFeatureUpdate($action, 'Select features from the registered module list.');

            return;
        }

        $enabledKeys = collect($submittedKeys)
            ->filter(static fn (mixed $key): bool => is_string($key))
            ->unique()
            ->values();

        if ($enabledKeys->count() !== count($submittedKeys)) {
            self::haltFeatureUpdate($action, 'Select each registered feature at most once.');

            return;
        }

        if ($enabledKeys->diff($modules->keys())->isNotEmpty()) {
            self::haltFeatureUpdate($action, 'An unregistered feature cannot be assigned to a shop.');

            return;
        }

        $dependencyError = self::dependencyError($modules, $enabledKeys->all());

        if ($dependencyError !== null) {
            self::haltFeatureUpdate($action, $dependencyError);

            return;
        }

        $changes = DB::connection('central')->transaction(
            static function () use ($actor, $enabledKeys, $modules, $shop): int {
                $lockedActor = PlatformUser::on('central')
                    ->whereKey($actor->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedActor instanceof PlatformUser
                    || ! $lockedActor->is_active
                    || $lockedActor->role !== PlatformUser::ROLE_SUPER_ADMIN) {
                    throw new AuthorizationException(
                        'Your platform administrator access is no longer active.',
                    );
                }

                $lockedShop = Shop::on('central')
                    ->whereKey($shop->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedShop instanceof Shop) {
                    throw new AuthorizationException('The shop is no longer available.');
                }

                $storedFeatures = ShopFeature::on('central')
                    ->where('shop_id', $lockedShop->getKey())
                    ->orderBy('module_key')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('module_key');
                $changes = 0;

                foreach ($modules as $module) {
                    $storedFeature = $storedFeatures->get($module->key());
                    $currentlyEnabled = $storedFeature instanceof ShopFeature
                        ? $storedFeature->enabled
                        : $module->enabledByDefault();
                    $shouldEnable = $enabledKeys->contains($module->key());

                    if ($currentlyEnabled === $shouldEnable) {
                        continue;
                    }

                    ShopFeature::on('central')->updateOrCreate(
                        [
                            'shop_id' => $lockedShop->getKey(),
                            'module_key' => $module->key(),
                        ],
                        ['enabled' => $shouldEnable],
                    );
                    resolve(RecordShopLifecycleActivity::class)->handle(
                        $lockedShop,
                        $shouldEnable
                            ? ShopLifecycleEvent::FeatureEnabled
                            : ShopLifecycleEvent::FeatureDisabled,
                        $lockedActor,
                        [
                            'module_key' => $module->key(),
                            'reason_code' => 'platform_action',
                        ],
                    );
                    $changes++;
                }

                return $changes;
            },
        );

        resolve(ModuleRegistry::class)->flush();

        Notification::make()
            ->success()
            ->title('Features updated')
            ->body($changes === 0
                ? 'No platform entitlements changed.'
                : 'The platform feature ceiling is effective immediately.')
            ->send();
    }

    /**
     * @param  Collection<string, Module>  $modules
     * @param  list<string>  $enabledKeys
     */
    private static function dependencyError(Collection $modules, array $enabledKeys): ?string
    {
        $registry = resolve(ModuleRegistry::class);

        foreach ($modules as $module) {
            if (! in_array($module->key(), $enabledKeys, true)) {
                continue;
            }

            foreach ($module->dependsOn() as $dependencyKey) {
                $dependency = $registry->find($dependencyKey);

                if ($dependency instanceof Module) {
                    if ($dependency->isCore()) {
                        continue;
                    }
                }

                if (in_array($dependencyKey, $enabledKeys, true)) {
                    continue;
                }

                return $module->title().' requires '
                    .($dependency?->title() ?? $dependencyKey)
                    .'. Enable the dependency first.';
            }
        }

        return null;
    }

    private static function haltFeatureUpdate(Action $action, string $message): void
    {
        Notification::make()
            ->danger()
            ->title('Features were not updated')
            ->body($message)
            ->send();
        $action->failure();
        $action->halt();
    }

    private static function authorizedActor(Shop $shop): PlatformUser
    {
        if (! ShopResource::canView($shop)) {
            throw new AuthorizationException('Platform administrator authentication is required.');
        }

        $actor = Auth::guard('platform')->user();

        if (! $actor instanceof PlatformUser) {
            throw new AuthorizationException('Platform administrator authentication is required.');
        }

        return $actor;
    }
}
