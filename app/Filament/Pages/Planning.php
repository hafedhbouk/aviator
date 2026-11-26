<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Agency;
use App\Models\Reservation;
use App\Models\VehicleCategory;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Planning du comptoir (C-02, parcours P2, D35) : tous les profils qui travaillent sur
 * des réservations. Filtres par agence et par catégorie ; les blocs des autres agences
 * restent visibles en « Occupé », sans nom (Q38).
 */
class Planning extends Page
{
    protected string $view = 'filament.pages.planning';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Comptoir';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Planning';

    protected static ?string $title = 'Planning';

    protected static ?string $slug = 'comptoir/planning';

    /** Filtres des listes de la page (chaîne vide = tous). */
    public int|string|null $agencyId = null;

    public int|string|null $categoryId = null;

    public function agencyFilter(): ?int
    {
        return is_numeric($this->agencyId) ? (int) $this->agencyId : null;
    }

    public function categoryFilter(): ?int
    {
        return is_numeric($this->categoryId) ? (int) $this->categoryId : null;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Reservation::class) ?? false;
    }

    /**
     * @return array<int, string>
     */
    public function agencies(): array
    {
        return Agency::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    public function categories(): array
    {
        return VehicleCategory::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all();
    }
}
