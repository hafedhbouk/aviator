<?php

declare(strict_types=1);

namespace App\Filament\Prototype;

use App\Domain\Availability\Planning\PlanningEntry;
use App\Domain\Availability\Planning\PlanningProjection;
use App\Models\Agency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Guava\Calendar\Enums\CalendarViewType;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Guava\Calendar\ValueObjects\CalendarResource;
use Guava\Calendar\ValueObjects\DateClickInfo;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Support\Collection;

/**
 * Prototype du planning sur guava/calendar (D5, D35 ; plan de la phase 4, §3).
 * Frise par véhicule regroupée par agence, données fictives, lecture seule (D39).
 */
class PlanningPrototypeCalendar extends CalendarWidget
{
    protected CalendarViewType $calendarView = CalendarViewType::ResourceTimelineWeek;

    protected ?string $locale = 'fr';

    protected bool $useFilamentTimezone = true;

    // Clic sur un bloc détaillé : ouverture du document (lien). Les blocs « Occupé » n'ont
    // ni lien ni modèle et sont rendus non cliquables (classe planning-occupied).
    protected bool $eventClickEnabled = true;

    protected bool $dateClickEnabled = true;

    // D39 : aucune modification depuis le planning.
    protected bool $eventDragEnabled = false;

    protected bool $eventResizeEnabled = false;

    private const array COLORS = [
        'reservation' => '#0284c7',
        'contract' => '#059669',
        'maintenance' => '#d97706',
        'unavailability' => '#6b7280',
        'occupied' => '#94a3b8',
    ];

    /**
     * Options de vkurko/calendar, fusionnées en dernier par le paquet.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return [
            'headerToolbar' => [
                'start' => 'prev,next today',
                'center' => 'title',
                'end' => 'resourceTimelineWeek,resourceTimelineMonth',
            ],
            'buttonText' => [
                'today' => 'Aujourd\'hui',
                'resourceTimelineWeek' => 'Semaine',
                'resourceTimelineMonth' => 'Mois',
            ],
            'views' => [
                'resourceTimelineWeek' => [
                    'slotDuration' => '06:00',
                    'slotWidth' => 36,
                    'slotLabelFormat' => ['hour' => '2-digit', 'hour12' => false],
                ],
                'resourceTimelineMonth' => [
                    'slotDuration' => ['days' => 1],
                    'slotWidth' => 40,
                    'slotLabelFormat' => ['weekday' => 'narrow', 'day' => 'numeric'],
                ],
            ],
            'nowIndicator' => true,
            'height' => '78vh',
            'eventTimeFormat' => ['hour' => '2-digit', 'minute' => '2-digit', 'hour12' => false],
        ];
    }

    /**
     * @return list<CalendarResource>
     */
    protected function getResources(): array
    {
        $agencies = Agency::query()->orderBy('id')->pluck('name', 'id');

        return collect($this->demo()->vehicles())
            ->groupBy('agency_id')
            ->map(fn (Collection $vehicles, int $agencyId): CalendarResource => CalendarResource::make('agency-'.$agencyId)
                ->title((string) ($agencies[$agencyId] ?? 'Agence '.$agencyId))
                // guava/calendar 3.2.2 ne convertit pas les ressources enfants (objets sérialisés
                // en « {} ») : elles sont transmises déjà converties en tableaux.
                ->children($vehicles->map(fn (array $vehicle): array => CalendarResource::make('vehicle-'.$vehicle['id'])
                    ->title($vehicle['label'].' ('.$vehicle['category'].')')
                    ->toCalendarObject())->all()))
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

        $blocks = $this->demo()->blocksBetween(
            CarbonImmutable::instance($info->start),
            CarbonImmutable::instance($info->end),
        );

        $events = [];

        foreach (app(PlanningProjection::class)->forUser($user, $blocks) as $entry) {
            array_push($events, ...$this->toEvents($entry));
        }

        return $events;
    }

    protected function onDateClick(DateClickInfo $info): void
    {
        // Prototype : l'assistant de réservation (lot L11) recevra le véhicule et la date.
        Notification::make()
            ->title('Nouvelle réservation')
            ->body(sprintf(
                'Assistant prérempli : %s, le %s.',
                $info->resource?->getTitle() ?? 'véhicule non précisé',
                $info->date->timezone(config('app.timezone'))->format('d/m/Y à H:i'),
            ))
            ->info()
            ->send();
    }

    /**
     * Un bloc par occupation. Le délai de préparation prolonge le bloc et apparaît hachuré
     * sur sa fin (proportion calculée ici) : un événement séparé passerait sur une seconde
     * ligne en vue mois, où les créneaux durent un jour.
     *
     * @return list<CalendarEvent>
     */
    private function toEvents(PlanningEntry $entry): array
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
            ->classNames(['planning-occupied' => ! $entry->detailed, 'planning-late' => $entry->late]);

        if ($entry->bufferEndsAt !== null) {
            $total = max(1, $entry->startsAt->diffInMinutes($end));
            $solid = round(100 * $entry->startsAt->diffInMinutes($entry->endsAt) / $total, 2);

            $event->styles([
                'background' => sprintf(
                    'linear-gradient(to right, %1$s 0 %2$s%%, transparent %2$s%%), repeating-linear-gradient(135deg, %1$s 0 3px, transparent 3px 7px)',
                    $color,
                    $solid,
                ),
            ]);
        }

        if ($entry->url !== null) {
            $event->url($entry->url, '_self');
        }

        return [$event];
    }

    private function demo(): PlanningDemoData
    {
        /** @var list<int> $agencyIds */
        $agencyIds = Agency::query()->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        return once(fn (): PlanningDemoData => new PlanningDemoData($agencyIds, CarbonImmutable::today()));
    }
}
