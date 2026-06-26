<?php

namespace App\Filament\Resources\ContentAssetResource\Pages;

use App\Filament\Resources\ContentAssetResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListContentAssets extends ListRecords
{
    protected static string $resource = ContentAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
