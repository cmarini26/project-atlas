<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContentAssetResource\Pages;
use App\Models\ContentAsset;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContentAssetResource extends Resource
{
    protected static ?string $model = ContentAsset::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')->columnSpanFull(),
                Forms\Components\Textarea::make('body')->rows(6)->columnSpanFull(),
                Forms\Components\Select::make('type')
                    ->options([
                        'social_post' => 'Social Post',
                        'email' => 'Email',
                        'sms' => 'SMS',
                        'blog_post' => 'Blog Post',
                        'ad_copy' => 'Ad Copy',
                        'landing_page' => 'Landing Page',
                    ]),
                Forms\Components\Select::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'approved' => 'Approved',
                        'scheduled' => 'Scheduled',
                        'published' => 'Published',
                        'archived' => 'Archived',
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes())
            ->columns([
                Tables\Columns\TextColumn::make('company.name')->searchable(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('title')->limit(50),
                Tables\Columns\TextColumn::make('body')->limit(80)->wrap(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'approved' => 'success',
                        'scheduled' => 'info',
                        'published' => 'primary',
                        'archived' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'approved' => 'Approved',
                        'archived' => 'Archived',
                    ]),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'social_post' => 'Social Post',
                        'email' => 'Email',
                        'sms' => 'SMS',
                        'blog_post' => 'Blog Post',
                        'landing_page' => 'Landing Page',
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
            'index' => Pages\ListContentAssets::route('/'),
            'view' => Pages\ViewContentAsset::route('/{record}'),
        ];
    }
}
