<?php

declare(strict_types=1);

namespace App\Filament\Planning;

use App\Domain\Availability\Planning\PlanningEntry;
use App\Domain\Availability\Planning\PlanningProjection;
use App\Domain\Availability\Planning\PlanningQuery;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Guava\Calendar\Enums\CalendarViewType;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Guava\Calendar\ValueObjects\CalendarResource;
use Guava\Calendar\ValueObjects\DateClickInfo;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Support\Collection;

/**
 * Planning du comptoir (C-02, D35 : guava/calendar 3.2.2 figé). Frise par véhicule,
 * regroupée par agence de localisation ; lecture seule (D39).
 *
 * Contournements du paquet, chacun gardé par un test (tests/Feature/Planning) :
 * 1. ressources enfants transmises déjà converties en tableaux (sinon « {} ») ;
 * 2. blocs « Occupé » sans lien ni modèle, rendus non cliquables (sinon exception
 *    serveur au clic) ;
 * 3. heures envoyées à l'heure de Tunis (`useFilamentTimezone`) et option `timeZone`
 *    d'EventCalendar fixée au décalage de Tunis : sans elle, la bibliothèque convertit
 *    au fuseau du navigateur (constaté : −6 h à New York). Les dates renvoyées par la
 *    bibliothèque (clic, fenêtre chargée) sont alors elles aussi à l'heure de Tunis.
 */
class PlanningCalendar extends CalendarWidget
{
    protected CalendarViewType $calendarView = CalendarViewType::ResourceTimelineWeek;

    protected ?string $locale = 'fr';

    protected bool $useFilamentTimezone = true;

    protected bool $eventClickEnabled = true;

    protected bool $dateClickEnabled = true;

    protected bool $eventDragEnabled = false;

    protected bool $eventResizeEnabled = false;

    /** Filtres transmis par la page. */
    public ?int $agencyId = null;

    public ?int $categoryId = null;

    private const array COLORS = [
        'reservation' => '#0284c7',
        'contract' => '#059669',
        'maintenance' => '#d97706',
        'unavailability' => '#6b7280',
        'occupied' => '#94a3b8',
    ];

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return [
            'headerToolbar' => ['start' => 'prev,next today', 'center' => 'title', 'end' => 'resourceTimelineWeek,resourceTimelineMonth'],
            'buttonText' => ['today' => 'Aujourd\'hui', 'resourceTimelineWeek' => 'Semaine', 'resourceTimelineMonth' => 'Mois'],
            'views' => [
                'resourceTimelineWeek' => ['slotDuration' => '06:00', 'slotWidth' => 36, 'slotLabelFormat' => ['hour' => '2-digit', 'hour12' => false]],
                'resourceTimelineMonth' => ['slotDuration' => ['days' => 1], 'slotWidth' => 40, 'slotLabelFormat' => ['weekday' => 'narrow', 'day' => 'numeric']],
            ],
            // Contournement 3 : EventCalendar 5 convertit sinon les heures au fuseau du
            // navigateur ; un décalage fixe impose l'heure de Tunis (sans heure d'été).
            'timeZone' => CarbonImmutable::now((string) config('app.timezone'))->format('P'),
            'nowIndicator' => false,
            'height' => '78vh',
            'eventTimeFormat' => ['hour' => '2-digit', 'minute' => '2-digit', 'hour12' => false],
        ];
    }

    /**
     * @return list<CalendarResource>
     */
    protected function getResources(): array
    {
        return collect(app(PlanningQuery::class)->vehicles($this->agencyId, $this->categoryId))
            ->groupBy('current_agency_id')
            ->map(fn (Collection $vehicles): CalendarResource => CalendarResource::make('agency-'.$vehicles->first()->current_agency_id)
                ->title($vehicles->first()->currentAgency->name)
                // Contournement 1 : enfants déjà convertis (guava/calendar 3.2.2).
                ->children($vehicles->map(fn (Vehicle $vehicle): array => CalendarResource::make('vehicle-'.$vehicle->id)
                    ->title("{$vehicle->label()} ({$vehicle->category->name})")
                    ->toCalendarObject())->values()->all()))
            ->values()
            ->all();
    }

    /**
     * @return list<CalendarEvent>
     */
    protected function getEvents(FetchInfo $info): array
    {
        /** @var User $user */
        $user = auth()->user();

        $query = app(PlanningQuery::class);
        $vehicleIds = array_map(fn (Vehicle $vehicle): int => $vehicle->id, $query->vehicles($this->agencyId, $this->categoryId));
        $blocks = $query->blocks($vehicleIds, CarbonImmutable::instance($info->start), CarbonImmutable::instance($info->end));

        return array_map(fn (PlanningEntry $entry): CalendarEvent => $this->toEvent($entry), app(PlanningProjection::class)->forUser($user, $blocks));
    }

    protected function onDateClick(DateClickInfo $info): void
    {
        $vehicleId = str_starts_with((string) $info->resource?->getId(), 'vehicle-') ? (int) substr((string) $info->resource?->getId(), 8) : null;

        $this->redirect(ReservationResource::getUrl('create', array_filter([
            'vehicle' => $vehicleId,
            'starts' => $info->date->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
        ])));
    }

    /**
     * Le délai de préparation prolonge le bloc et apparaît hachuré sur sa fin.
     */
    private function toEvent(PlanningEntry $entry): CalendarEvent
    {
        $color = self::COLORS[$entry->detailed ? $entry->type->value : 'occupied'];
        $end = $entry->bufferEndsAt ?? $entry->endsAt;

        $event = CalendarEvent::make()
            ->extendedProp('entry', $entry->id)
            ->title($entry->label)
            ->start($entry->startsAt)
            ->end($end)
            ->resourceId('vehicle-'.$entry->vehicleId)
            ->backgroundColor($color)
            // Contournement 2 : un bloc sans lien n'est jamais cliquable.
            ->classNames(['planning-occupied' => $entry->url === null, 'planning-late' => $entry->late]);

        if ($entry->bufferEndsAt !== null) {
            // Part pleine en centièmes de pour cent, calculée en entiers.
            $total = max(1, (int) $entry->startsAt->diffInMinutes($end));
            $solid = intdiv(10000 * (int) $entry->startsAt->diffInMinutes($entry->endsAt), $total);
            $percent = sprintf('%d.%02d', intdiv($solid, 100), $solid % 100);

            $event->styles(['background' => sprintf(
                'linear-gradient(to right, %1$s 0 %2$s%%, transparent %2$s%%), repeating-linear-gradient(135deg, %1$s 0 3px, transparent 3px 7px)',
                $color,
                $percent,
            )]);
        }

        if ($entry->url !== null) {
            $event->url($entry->url, '_self');
        }

        return $event;
    }
}
