<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OpportunityResource\Pages;
use App\Models\Opportunity;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OpportunityResource extends Resource
{
    protected static ?string $model = Opportunity::class;

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
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes()->with('company'))
            ->columns([
                Tables\Columns\TextColumn::make('company.name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('title')->limit(50)->searchable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'open' => 'primary',
                        'selected' => 'success',
                        'dismissed' => 'gray',
                        'expired' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('composite_score')->label('Score')->sortable(),
                Tables\Columns\IconColumn::make('ai_detected')->label('AI')->boolean(),
                Tables\Columns\TextColumn::make('detected_at')->dateTime()->sortable(),
            ])
            ->defaultSort('detected_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'selected' => 'Selected',
                        'dismissed' => 'Dismissed',
                        'expired' => 'Expired',
                    ]),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'featured_item' => 'Featured Item',
                        'urgency_promotion' => 'Urgency Promotion',
                        'new_arrival' => 'New Arrival',
                        're_engagement' => 'Re-engagement',
                    ]),
            ])
            ->actions([])
            ->bulkActions([]);
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
            'index' => Pages\ListOpportunities::route('/'),
            'create' => Pages\CreateOpportunity::route('/create'),
            'edit' => Pages\EditOpportunity::route('/{record}/edit'),
        ];
    }
}
