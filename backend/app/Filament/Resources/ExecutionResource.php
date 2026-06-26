<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExecutionResource\Pages;
use App\Models\Execution;
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
