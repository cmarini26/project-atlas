<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FeedbackResource\Pages;
use App\Models\Feedback;
use Filament\Forms\Components\TextInput;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Milestone 19 — Early Customer Feedback Tooling. Read-only: feedback is
 * submitted by customers in-product, never authored or edited by the team.
 */
class FeedbackResource extends Resource
{
    protected static ?string $model = Feedback::class;

    // Explicit slug: "feedback" doesn't pluralize sensibly via the default
    // Filament/Laravel inflector, so don't leave the URL to a guess.
    protected static ?string $slug = 'feedback';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Feedback';

    protected static ?int $navigationSort = 8;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('score')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 9 => 'success',
                        $state >= 7 => 'warning',
                        default => 'danger',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('comment')
                    ->limit(80)
                    ->wrap()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('company.name')
                    ->label('Company')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('User'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\Filter::make('score')
                    ->form([
                        TextInput::make('score_from')->numeric()->minValue(1)->maxValue(10),
                        TextInput::make('score_to')->numeric()->minValue(1)->maxValue(10),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['score_from'] ?? null, fn ($q, $v) => $q->where('score', '>=', $v))
                            ->when($data['score_to'] ?? null, fn ($q, $v) => $q->where('score', '<=', $v));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Feedback')->schema([
                Infolists\Components\TextEntry::make('score')->badge(),
                Infolists\Components\TextEntry::make('comment')->placeholder('—')->columnSpanFull(),
                Infolists\Components\TextEntry::make('company.name')->label('Company'),
                Infolists\Components\TextEntry::make('user.name')->label('User'),
                Infolists\Components\TextEntry::make('created_at')->dateTime(),
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
            'index' => Pages\ListFeedback::route('/'),
            'view' => Pages\ViewFeedback::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
