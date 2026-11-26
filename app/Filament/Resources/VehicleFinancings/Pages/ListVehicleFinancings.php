<?php

namespace App\Filament\Resources\VehicleFinancings\Pages;

use App\Filament\Resources\VehicleFinancings\VehicleFinancingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVehicleFinancings extends ListRecords
{
    protected static string $resource = VehicleFinancingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
