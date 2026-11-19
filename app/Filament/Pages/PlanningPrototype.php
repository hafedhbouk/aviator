<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Prototype\PlanningPrototypeCalendar;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Prototype du planning (plan de la phase 4, lot L0, D35). Données fictives, lecture
 * seule. Accessible sur le poste et en test seulement : jamais en recette ni en
 * production. Remplacé par le planning définitif au lot L12.
 */
class PlanningPrototype extends Page
{
    protected string $view = 'filament.pages.planning-prototype';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Comptoir';

    protected static ?string $navigationLabel = 'Planning (prototype)';

    protected static ?string $title = 'Planning — prototype (données fictives)';

    protected static ?string $slug = 'prototype/planning';

    public static function canAccess(): bool
    {
        return app()->environment(['local', 'testing']) && auth()->check();
    }

    public function getWidgetClass(): string
    {
        return PlanningPrototypeCalendar::class;
    }
}
