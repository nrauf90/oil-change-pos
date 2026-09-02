<?php

namespace App\Filament\Platform\Resources\Shops\Tables;

use App\Actions\Tenancy\ProvisionShop;
use App\Actions\Tenancy\RecordShopLifecycleActivity;
use App\Enums\ShopLifecycleEvent;
use App\Enums\ShopStatus;
use App\Exceptions\TenantProvisioningException;
use App\Filament\Platform\Resources\Shops\ShopResource;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ShopsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Shop')
                    ->description(static fn (Shop $record): string => $record->slug)
                    ->searchable(['name', 'slug'])
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(static fn (ShopStatus $state): string => ShopResource::statusLabel($state))
                    ->icon(static fn (ShopStatus $state): Heroicon => ShopResource::statusIcon($state))
                    ->color(static fn (ShopStatus $state): string => ShopResource::statusColor($state))
                    ->sortable(),

                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->description(static fn (Shop $record): ?string => $record->owner?->username)
                    ->placeholder('Not assigned')
                    ->visibleFrom('lg'),

                TextColumn::make('enabled_features_count')
                    ->label('Features')
                    ->state(static fn (Shop $record): int => ShopResource::enabledFeatureCount($record))
                    ->formatStateUsing(static fn (int $state): string => "{$state} enabled")
                    ->visibleFrom('xl'),

                TextColumn::make('health')
                    ->state(static fn (Shop $record): string => (string) data_get(
                        $record->healthSnapshot?->summary,
                        'connection_status',
                        'unavailable',
                    ))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => ucfirst($state))
                    ->icon(static fn (string $state): Heroicon => $state === 'healthy'
                        ? Heroicon::CheckCircle
                        : Heroicon::ExclamationTriangle)
                    ->color(static fn (string $state): string => $state === 'healthy' ? 'success' : 'danger')
                    ->visibleFrom('md'),

                TextColumn::make('healthSnapshot.last_activity_at')
                    ->label('Last activity')
                    ->dateTime('d M Y, g:i A')
                    ->placeholder('No activity')
                    ->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ShopStatus::cases())->mapWithKeys(
                        static fn (ShopStatus $status): array => [
                            $status->value => ShopResource::statusLabel($status),
                        ],
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                self::suspendAction(),
                self::reactivateAction(),
                self::retryProvisioningAction(),
            ])
            ->defaultSort('name')
            ->stackedOnMobile()
            ->emptyStateIcon(Heroicon::OutlinedBuildingStorefront)
            ->emptyStateHeading('No shops yet')
            ->emptyStateDescription('Create the first shop and provision its owner account.')
            ->emptyStateActions([
                CreateAction::make(),
            ]);
    }

    public static function suspendAction(): Action
    {
        return Action::make('suspend')
            ->label('Suspend')
            ->icon(Heroicon::OutlinedPauseCircle)
            ->color('danger')
            ->visible(static fn (Shop $record): bool => $record->status === ShopStatus::Active)
            ->authorize(static fn (Shop $record): bool => ShopResource::canView($record))
            ->requiresConfirmation()
            ->modalHeading(static fn (Shop $record): string => "Suspend {$record->name}?")
            ->modalDescription('Staff cannot sign in or use shop operations until this shop is reactivated.')
            ->modalSubmitActionLabel('Suspend shop')
            ->action(static function (Shop $record): void {
                $actor = self::authorizedActor($record);

                DB::connection('central')->transaction(static function () use ($record, $actor): void {
                    $record->suspend();
                    resolve(RecordShopLifecycleActivity::class)->handle(
                        $record,
                        ShopLifecycleEvent::Suspended,
                        $actor,
                        ['reason_code' => 'platform_action'],
                    );
                });

                Notification::make()->success()->title('Shop suspended')->send();
            });
    }

    public static function reactivateAction(): Action
    {
        return Action::make('reactivate')
            ->label('Reactivate')
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('success')
            ->visible(static fn (Shop $record): bool => $record->status === ShopStatus::Suspended)
            ->authorize(static fn (Shop $record): bool => ShopResource::canView($record))
            ->requiresConfirmation()
            ->modalHeading(static fn (Shop $record): string => "Reactivate {$record->name}?")
            ->modalDescription('Staff can sign in and resume shop operations immediately after reactivation.')
            ->modalSubmitActionLabel('Reactivate shop')
            ->action(static function (Shop $record): void {
                $actor = self::authorizedActor($record);

                DB::connection('central')->transaction(static function () use ($record, $actor): void {
                    $record->reactivate();
                    resolve(RecordShopLifecycleActivity::class)->handle(
                        $record,
                        ShopLifecycleEvent::Reactivated,
                        $actor,
                        ['reason_code' => 'platform_action'],
                    );
                });

                Notification::make()->success()->title('Shop reactivated')->send();
            });
    }

    public static function retryProvisioningAction(): Action
    {
        return Action::make('retryProvisioning')
            ->label('Retry')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(static fn (Shop $record): bool => $record->status === ShopStatus::Failed)
            ->authorize(static fn (Shop $record): bool => ShopResource::canView($record))
            ->modalHeading(static fn (Shop $record): string => "Retry provisioning {$record->name}?")
            ->modalDescription('Provisioning resumes against the registered private database target. No database is deleted.')
            ->modalSubmitActionLabel('Retry provisioning')
            ->schema([
                TextInput::make('temporary_owner_password')
                    ->label('Temporary owner password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(8)
                    ->maxLength(255),
            ])
            ->action(static function (Action $action, Shop $record, array $data): void {
                self::authorizedActor($record);

                try {
                    resolve(ProvisionShop::class)->retry(
                        $record,
                        (string) $data['temporary_owner_password'],
                    );
                } catch (TenantProvisioningException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Shop provisioning failed')
                        ->body($exception->getMessage())
                        ->send();
                    $action->failure();
                    $action->halt();
                }

                Notification::make()
                    ->success()
                    ->title('Shop provisioned')
                    ->body('The shop is active and its owner can sign in.')
                    ->send();
            });
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
