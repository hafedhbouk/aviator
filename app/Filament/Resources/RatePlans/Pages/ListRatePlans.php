<?php

namespace App\Filament\Resources\RatePlans\Pages;

use App\Filament\Resources\RatePlans\RatePlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRatePlans extends ListRecords
{
    protected static string $resource = RatePlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
