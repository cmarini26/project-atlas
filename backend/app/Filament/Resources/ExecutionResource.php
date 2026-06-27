<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExecutionResource\Pages;
use App\Models\Execution;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExecutionResource extends Resource
{
    protected static ?string $model = Execution::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationLabel = 'Executions';

    protected static ?int $navigationSort = 6;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes()->with(['company', 'campaign', 'contentAsset', 'channel']))
            ->columns([
                Tables\Columns\TextColumn::make('company.name')
                    ->label('Company')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('campaign.title')
                    ->label('Campaign')
                    ->limit(40)
                    ->searchable(),
                Tables\Columns\TextColumn::make('contentAsset.type')
                    ->label('Asset Type')
                    ->badge(),
                Tables\Columns\TextColumn::make('channel.type')
                    ->label('Channel')
                    ->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'queued' => 'gray',
                        'executing' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        'cancelled' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('attempts')
                    ->label('Attempts')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_error')
                    ->label('Last Error')
                    ->limit(60)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('scheduled_at')
                    ->label('Scheduled')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Immediate'),
                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'queued' => 'Queued',
                        'executing' => 'Executing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                        'cancelled' => 'Cancelled',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Execution Details')->schema([
                Infolists\Components\TextEntry::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'executing' => 'warning',
                        default => 'gray',
                    }),
                Infolists\Components\TextEntry::make('channel.type')->label('Channel')->badge(),
                Infolists\Components\TextEntry::make('attempts')->numeric(),
                Infolists\Components\TextEntry::make('completed_at')->label('Completed')->dateTime(),
                Infolists\Components\TextEntry::make('last_error')->columnSpanFull()->placeholder('—'),
            ])->columns(3),

            Infolists\Components\Section::make('Metrics')
                ->description('Analytics collected for this execution')
                ->schema([
                    Infolists\Components\TextEntry::make('metric.channel_type')
                        ->label('Channel Type')
                        ->badge()
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('metric.provider_type')
                        ->label('Provider')
                        ->badge()
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('metric.retrieved_at')
                        ->label('Last Retrieved')
                        ->dateTime()
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('metric.window_closes_at')
                        ->label('Window Closes')
                        ->dateTime()
                        ->placeholder('—'),

                    Infolists\Components\IconEntry::make('metric.is_final')
                        ->label('Final')
                        ->boolean()
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('normalised_reach')
                        ->label('Reach')
                        ->state(fn (Execution $record): string => (string) ($record->metric?->metrics['normalised_reach'] ?? '—')),

                    Infolists\Components\TextEntry::make('normalised_engagement')
                        ->label('Engagement')
                        ->state(fn (Execution $record): string => (string) ($record->metric?->metrics['normalised_engagement'] ?? '—')),

                    Infolists\Components\TextEntry::make('normalised_engagement_rate')
                        ->label('Engagement Rate')
                        ->state(fn (Execution $record): string => isset($record->metric?->metrics['normalised_engagement_rate'])
                            ? number_format((float) $record->metric->metrics['normalised_engagement_rate'] * 100, 2).'%'
                            : '—'),
                ])->columns(3),
        ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExecutions::route('/'),
            'view' => Pages\ViewExecution::route('/{record}'),
        ];
    }
}
