<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompanyResource\Pages;
use App\Models\Company;
use App\Models\LearningApplication;
use App\Services\Analytics\RecommendationKpiService;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Company Details')->schema([
                Infolists\Components\TextEntry::make('name'),
                Infolists\Components\TextEntry::make('slug'),
                Infolists\Components\TextEntry::make('created_at')->dateTime(),
            ])->columns(3),

            Infolists\Components\Section::make('Recommendation Analytics')
                ->description('Approval rates and decision trends for this company')
                ->schema([
                    Infolists\Components\TextEntry::make('approval_rate')
                        ->label('Approval Rate')
                        ->state(fn (Company $record): string => self::formatRate(
                            app(RecommendationKpiService::class)->forCompany($record->id)['approval_rate']
                        )),

                    Infolists\Components\TextEntry::make('rejection_rate')
                        ->label('Rejection Rate')
                        ->state(fn (Company $record): string => self::formatRate(
                            app(RecommendationKpiService::class)->forCompany($record->id)['rejection_rate']
                        )),

                    Infolists\Components\TextEntry::make('edit_rate')
                        ->label('Edit Rate')
                        ->state(fn (Company $record): string => self::formatRate(
                            app(RecommendationKpiService::class)->forCompany($record->id)['edit_rate']
                        )),

                    Infolists\Components\TextEntry::make('trend_30d')
                        ->label('30-Day Trend')
                        ->state(function (Company $record): string {
                            $trend = app(RecommendationKpiService::class)->forCompany($record->id)['approval_rate_trend_30d'];
                            $delta = (float) $trend['delta'];
                            $arrow = $delta > 0 ? '↑' : ($delta < 0 ? '↓' : '→');

                            return $arrow.' '.self::formatRate(abs($delta)).' vs prior 30d';
                        }),

                    Infolists\Components\TextEntry::make('total_recommendations')
                        ->label('Total Recommendations')
                        ->state(fn (Company $record): string => (string) app(RecommendationKpiService::class)->forCompany($record->id)['total_recommendations']),
                ])->columns(3),

            Infolists\Components\Section::make('Learning Log')
                ->description('Applied learnings and their effects on this company\'s Business Brain')
                ->schema([
                    Infolists\Components\TextEntry::make('total_applied_learnings')
                        ->label('Total Applied Learnings')
                        ->state(fn (Company $record): string => (string) LearningApplication::withoutGlobalScopes()
                            ->where('company_id', $record->id)
                            ->count()),

                    Infolists\Components\TextEntry::make('rolled_back_learnings')
                        ->label('Rolled Back')
                        ->state(fn (Company $record): string => (string) LearningApplication::withoutGlobalScopes()
                            ->where('company_id', $record->id)
                            ->whereNotNull('rolled_back_at')
                            ->count()),

                    Infolists\Components\TextEntry::make('last_learning_applied_at')
                        ->label('Last Applied')
                        ->state(function (Company $record): string {
                            $latest = LearningApplication::withoutGlobalScopes()
                                ->where('company_id', $record->id)
                                ->latest('created_at')
                                ->first();

                            return $latest?->created_at?->diffForHumans() ?? 'Never';
                        }),
                ])->columns(3),

            Infolists\Components\Section::make('Applied Learning Effects')
                ->description('Most recent Learning applications and their Business Brain mutations')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('recent_learning_applications')
                        ->label('')
                        ->state(fn (Company $record): array => LearningApplication::withoutGlobalScopes()
                            ->where('company_id', $record->id)
                            ->latest('created_at')
                            ->limit(10)
                            ->get()
                            ->map(fn (LearningApplication $app): array => [
                                'applied_at' => $app->created_at?->format('Y-m-d H:i'),
                                'signal' => optional($app->learning)->signal ?? '—',
                                'effects_count' => count($app->effects ?? []),
                                'rolled_back' => $app->isRolledBack() ? 'Yes' : 'No',
                                'summary' => collect($app->effects ?? [])->pluck('description')->implode('; '),
                            ])
                            ->toArray())
                        ->schema([
                            Infolists\Components\TextEntry::make('applied_at')->label('Applied At'),
                            Infolists\Components\TextEntry::make('signal')->label('Signal'),
                            Infolists\Components\TextEntry::make('effects_count')->label('Effects'),
                            Infolists\Components\TextEntry::make('rolled_back')->label('Rolled Back'),
                            Infolists\Components\TextEntry::make('summary')->label('Summary')->columnSpanFull(),
                        ])->columns(4),
                ]),
        ]);
    }

    private static function formatRate(mixed $rate): string
    {
        return number_format((float) $rate * 100, 1).'%';
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanies::route('/'),
            'create' => Pages\CreateCompany::route('/create'),
            'edit' => Pages\EditCompany::route('/{record}/edit'),
            'view' => Pages\ViewCompany::route('/{record}'),
        ];
    }
}
