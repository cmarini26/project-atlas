<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CampaignResource\Pages;
use App\Models\Campaign;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')->columnSpanFull(),
                Forms\Components\Textarea::make('target_audience')->rows(2)->columnSpanFull(),
                Forms\Components\Textarea::make('positioning')->label('Core Message')->rows(3)->columnSpanFull(),
                Forms\Components\TextInput::make('call_to_action'),
                Forms\Components\Select::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'approved' => 'Approved',
                        'cancelled' => 'Cancelled',
                        'scheduled' => 'Scheduled',
                        'published' => 'Published',
                        'completed' => 'Completed',
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes())
            ->columns([
                Tables\Columns\TextColumn::make('company.name')->searchable(),
                Tables\Columns\TextColumn::make('title')->limit(50)->searchable(),
                Tables\Columns\TextColumn::make('campaign_type')->badge(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'approved' => 'success',
                        'cancelled' => 'danger',
                        'scheduled' => 'info',
                        'published' => 'primary',
                        'completed' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('expected_asset_count')->label('Expected'),
                Tables\Columns\TextColumn::make('generated_asset_count')->label('Generated'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'approved' => 'Approved',
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
            Infolists\Components\Section::make('Campaign Details')->schema([
                Infolists\Components\TextEntry::make('title'),
                Infolists\Components\TextEntry::make('campaign_type')->badge(),
                Infolists\Components\TextEntry::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'cancelled', 'failed' => 'danger',
                        'published' => 'primary',
                        'completed' => 'success',
                        default => 'gray',
                    }),
                Infolists\Components\TextEntry::make('target_audience')->columnSpanFull(),
                Infolists\Components\TextEntry::make('positioning')->label('Core Message')->columnSpanFull(),
                Infolists\Components\TextEntry::make('call_to_action'),
            ])->columns(3),

            Infolists\Components\Section::make('Performance')
                ->description('Actual vs. expected campaign performance')
                ->schema([
                    Infolists\Components\TextEntry::make('performance_rating')
                        ->label('Rating')
                        ->badge()
                        ->state(fn (Campaign $record): string => $record->kpiSnapshots()
                            ->where('snapshot_type', 'final')
                            ->orderBy('snapshotted_at', 'desc')
                            ->first()?->performance_rating ?? 'awaiting metrics')
                        ->color(fn (string $state): string => match ($state) {
                            'exceeded' => 'success',
                            'met' => 'primary',
                            'below' => 'warning',
                            default => 'gray',
                        }),

                    Infolists\Components\TextEntry::make('snapshot_type')
                        ->label('Snapshot')
                        ->badge()
                        ->state(fn (Campaign $record): string => $record->kpiSnapshots()
                            ->orderBy('snapshotted_at', 'desc')
                            ->first()?->snapshot_type ?? '—'),

                    Infolists\Components\TextEntry::make('snapshotted_at')
                        ->label('Measured At')
                        ->state(fn (Campaign $record): string => $record->kpiSnapshots()
                            ->orderBy('snapshotted_at', 'desc')
                            ->first()?->snapshotted_at?->toDateTimeString() ?? '—'),

                    Infolists\Components\TextEntry::make('total_reach')
                        ->label('Total Reach')
                        ->state(fn (Campaign $record): string => (string) ($record->kpiSnapshots()
                            ->orderBy('snapshotted_at', 'desc')
                            ->first()?->actual_kpis['total_reach'] ?? '—')),

                    Infolists\Components\TextEntry::make('total_engagement')
                        ->label('Total Engagement')
                        ->state(fn (Campaign $record): string => (string) ($record->kpiSnapshots()
                            ->orderBy('snapshotted_at', 'desc')
                            ->first()?->actual_kpis['total_engagement'] ?? '—')),

                    Infolists\Components\TextEntry::make('best_channel')
                        ->label('Best Channel')
                        ->badge()
                        ->state(fn (Campaign $record): string => (string) ($record->kpiSnapshots()
                            ->orderBy('snapshotted_at', 'desc')
                            ->first()?->actual_kpis['best_channel'] ?? '—')),
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
            'index' => Pages\ListCampaigns::route('/'),
            'view' => Pages\ViewCampaign::route('/{record}'),
        ];
    }
}
