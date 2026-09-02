<?php

namespace App\Filament\Platform\Resources\PlatformUsers\Tables;

use App\Filament\Platform\Resources\PlatformUsers\PlatformUserResource;
use App\Models\Central\PlatformUser;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\HtmlString;
use LogicException;

class PlatformUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Platform user')
                    ->description(fn (PlatformUser $record): string => $record->email)
                    ->searchable(['name', 'email'])
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('current_user')
                    ->label('')
                    ->state(fn (Model $record): ?string => PlatformUserResource::isCurrentUser($record) ? 'You' : null)
                    ->badge()
                    ->color('gray'),

                TextColumn::make('role')
                    ->label('Role')
                    ->formatStateUsing(fn (): string => 'Super admin')
                    ->badge()
                    ->color('warning'),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('last_login_at')
                    ->label('Last signed in')
                    ->dateTime('d M Y, g:i A')
                    ->placeholder('Never')
                    ->sortable()
                    ->visibleFrom('md'),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->date('d M Y')
                    ->sortable()
                    ->visibleFrom('xl')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All platform users')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
            ])
            ->defaultSort('name')
            ->stackedOnMobile()
            ->emptyStateIcon('heroicon-o-users')
            ->emptyStateHeading('No platform users yet')
            ->emptyStateDescription('Add a super admin who can manage the platform.');
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->using(static function (PlatformUser $record): bool {
                try {
                    return $record->delete() === true;
                } catch (LogicException|QueryException) {
                    return false;
                }
            })
            ->failureNotificationTitle('Platform user could not be deleted')
            ->failureNotificationBody(
                'The account is required by audit history or is now the final active super admin.',
            )
            ->disabled(fn (Model $record): bool => PlatformUserResource::isFinalActiveSuperAdmin($record))
            ->tooltip(fn (Model $record): ?string => PlatformUserResource::isFinalActiveSuperAdmin($record)
                ? 'Add or activate another super admin before deleting this account.'
                : null)
            ->modalHeading(fn (PlatformUser $record): string => "Delete {$record->name}?")
            ->modalDescription(fn (PlatformUser $record): HtmlString => new HtmlString(
                '<p>This permanently removes <strong>'.e($record->name).'</strong> from platform access.</p>'.
                '<p>Existing shop and audit records are kept.</p>',
            ));
    }
}
