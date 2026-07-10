<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FailedJobResource\Pages;
use App\Models\FailedJob;
use App\Services\Queue\FailedJobRecoveryService;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Operator-facing recovery workflow for Critical Production Blocker 5 — see
 * docs/plans/Critical-Production-Blockers.md. Access is gated by the same
 * panel-level `canAccessPanel()` (superadmin-only) every other Filament
 * resource in this app already relies on; no extra authorization layer is
 * added here since none of the others have one either.
 */
class FailedJobResource extends Resource
{
    protected static ?string $model = FailedJob::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Failed Jobs';

    protected static ?int $navigationSort = 7;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('queue')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('job_class')
                    ->label('Job')
                    ->state(fn (FailedJob $record): string => $record->jobClass())
                    ->searchable(query: fn ($query, string $search) => $query->where('payload', 'like', "%{$search}%")),
                Tables\Columns\TextColumn::make('exception_summary')
                    ->label('Exception')
                    ->state(fn (FailedJob $record): string => $record->exceptionSummary())
                    ->limit(80)
                    ->wrap(),
                Tables\Columns\TextColumn::make('failed_at')
                    ->label('Failed At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('failed_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('queue')
                    ->options(fn (): array => FailedJob::query()->distinct()->pluck('queue', 'queue')->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (FailedJob $record, FailedJobRecoveryService $service): void {
                        $service->retry($record);
                        Notification::make()->title('Job re-queued for retry.')->success()->send();
                    }),

                Tables\Actions\Action::make('forget')
                    ->label('Discard')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (FailedJob $record, FailedJobRecoveryService $service): void {
                        $service->forget($record);
                        Notification::make()->title('Failed job discarded.')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Failure Details')->schema([
                Infolists\Components\TextEntry::make('uuid')->label('UUID'),
                Infolists\Components\TextEntry::make('connection'),
                Infolists\Components\TextEntry::make('queue')->badge(),
                Infolists\Components\TextEntry::make('job_class')
                    ->label('Job')
                    ->state(fn (FailedJob $record): string => $record->jobClass()),
                Infolists\Components\TextEntry::make('failed_at')->label('Failed At')->dateTime(),
                Infolists\Components\TextEntry::make('exception')
                    ->label('Full Exception')
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFailedJobs::route('/'),
            'view' => Pages\ViewFailedJob::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
