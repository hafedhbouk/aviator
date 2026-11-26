<?php

namespace App\Filament\Resources\ApprovalRequests;

use App\Enums\ApprovalStatus;
use App\Filament\Resources\ApprovalRequests\Pages\ListApprovalRequests;
use App\Filament\Resources\ApprovalRequests\Pages\ViewApprovalRequest;
use App\Filament\Resources\ApprovalRequests\Schemas\ApprovalRequestInfolist;
use App\Filament\Resources\ApprovalRequests\Tables\ApprovalRequestsTable;
use App\Models\ApprovalRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Approbations du super admin (écran E6, parcours P5) : liste des demandes, les plus
 * anciennes en tête, et décision. Lecture et décision réservées au super admin
 * (ApprovalRequestPolicy) ; aucune création, modification ni suppression.
 */
class ApprovalRequestResource extends Resource
{
    protected static ?string $model = ApprovalRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Comptoir';

    protected static ?int $navigationSort = 0;

    protected static ?string $modelLabel = 'demande d\'approbation';

    protected static ?string $pluralModelLabel = 'demandes d\'approbation';

    protected static ?string $navigationLabel = 'Approbations';

    protected static ?string $slug = 'approbations';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationBadge(): ?string
    {
        $pending = ApprovalRequest::query()->where('status', ApprovalStatus::Pending->value)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return ApprovalRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApprovalRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalRequests::route('/'),
            'view' => ViewApprovalRequest::route('/{record}'),
        ];
    }
}
