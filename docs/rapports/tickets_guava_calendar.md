# Tickets à ouvrir chez le mainteneur de `guava/calendar`

Préparés le 19/11/2026 après le prototype du planning (phase 4, lot L0, D35). Publication par Hafedh sur https://github.com/GuavaCZ/calendar/issues. Le texte est en anglais, langue du dépôt. Aucune donnée d'Aviator n'y figure.

| N° | Sujet | Contournement dans Aviator | Test qui garde le contournement (lot L12) | Statut |
| --- | --- | --- | --- | --- |
| 1 | Ressources enfants sérialisées en `{}` | Enfants transmis déjà convertis en tableaux | `tests/Feature/Planning/PlanningTest.php` : « témoin 1 » (échoue quand le paquet est corrigé) et « contournement 1 » (chaque véhicule porte son `id` et son `title`) | Prêt à publier (observé dans le navigateur pendant le prototype : lignes vides, aucun bloc) |
| 2 | Clic sur un événement sans modèle : exception | Blocs « Occupé » non cliquables (`pointer-events: none`) ; tout bloc détaillé porte un lien | « témoin 2 » (l'appel `onEventClickJs` pour un bloc sans modèle lève toujours l'exception) et « contournement 2 » (tout bloc sans lien porte la classe inerte) | **Reproduit** le 26/11/2026 (lot L12) par un appel automatisé de la méthode Livewire `onEventClickJs`, celle que le navigateur appelle au clic : exception « Could not resolve event record ». Pas de clic manuel : dans Aviator, ces blocs ne sont plus cliquables. Prêt à publier |
| 3 | `$useFilamentTimezone` n'impose pas le fuseau d'affichage avec EventCalendar 5 | Option `timeZone` d'EventCalendar fixée au décalage de Tunis (`+01:00`) | « témoin 3 » (le paquet embarqué convertit toujours au fuseau du navigateur par défaut) et deux tests « contournement 3 » (option imposée ; clic venu d'un navigateur à New York → heure de Tunis de la case) | **Reproduit** le 26/11/2026 dans le navigateur réglé sur `America/New_York` : bloc de 15:00 affiché à 09:00 ; corrigé par le contournement (15:00 affiché, clic sur 00:00 → 00:00). Texte du ticket réécrit d'après l'essai. Prêt à publier |

Version concernée : `guava/calendar` 3.2.2 (Filament 5, PHP 8.3, Laravel 13).

---

## Ticket 1 — Child resources are serialized as empty objects

**Title**: `CalendarResource::toCalendarObject()` does not convert child resources (`children()` renders empty rows)

**Description**

When a `CalendarResource` has children added with `children([...])` or `child(...)`, the children are passed to the browser as empty objects. In resource timeline views, the child rows have no title and no id, so no event can be attached to them.

`toCalendarObject()` builds the children with:

```php
'children' => collect($this->getChildren())->toArray(),
```

`Collection::toArray()` only converts `Arrayable` items. `CalendarResource` is not `Arrayable` and its properties are protected, so each child is JSON-encoded as `{}`.

**Minimal reproduction**

```php
use Guava\Calendar\Enums\CalendarViewType;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Guava\Calendar\ValueObjects\CalendarResource;
use Guava\Calendar\ValueObjects\FetchInfo;

class ReproWidget extends CalendarWidget
{
    protected CalendarViewType $calendarView = CalendarViewType::ResourceTimelineWeek;

    protected function getResources(): array
    {
        return [
            CalendarResource::make('group-1')->title('Group')->children([
                CalendarResource::make('item-1')->title('Item 1'),
            ]),
        ];
    }

    protected function getEvents(FetchInfo $info): array
    {
        return [
            CalendarEvent::make()->title('Event')->start(now())->end(now()->addDay())->resourceId('item-1'),
        ];
    }
}
```

Expected: one group row "Group" with one child row "Item 1" holding the event.
Actual: the child row is blank and the event is not displayed. `json_encode((new ReproWidget)->getResourcesJs())` shows `"children":[{}]`.

**Suggested fix**

```php
'children' => collect($this->getChildren())
    ->map(fn (CalendarResource | array $child): array => $child instanceof CalendarResource ? $child->toCalendarObject() : $child)
    ->all(),
```

**Workaround**: pass children that are already converted: `->children([CalendarResource::make('item-1')->title('Item 1')->toCalendarObject()])`.

---

## Ticket 2 — Clicking an event without a model throws an exception

**Title**: With `$eventClickEnabled = true`, clicking an event that has no `model`/`key` (and no `url`) throws `Could not resolve event record`

**Description**

A calendar can mix model-backed events with display-only events built from arrays (for example, blocks that only show that a slot is busy, with no record behind them). When event clicks are enabled, clicking a display-only event calls `onEventClickJs()`, and `setRawCalendarContextData()` calls `resolveEventRecord()`, which throws:

```
Could not resolve event record. A [model] or [key] property set in the [extendedProps] of the mounted event was missing.
```

The user sees a server error instead of nothing happening. Events with a `url` are not affected, because the browser opens the URL without calling the server.

**Minimal reproduction**

```php
protected bool $eventClickEnabled = true;

protected function getEvents(FetchInfo $info): array
{
    return [
        CalendarEvent::make()->title('Busy')->start(now())->end(now()->addHours(2)),
    ];
}
```

Click the "Busy" event: the request fails with the exception above.

**Suggested fix**

Either skip the server round trip in `calendar-event.js` when the event has neither `model`/`key` nor `url`, or make `resolveEventRecord()` return `null` for events without a model, and let `onEventClick()` receive a nullable record. A per-event `clickable(false)` option, rendered as `pointer-events: none` or ignored in `eventClick`, would also cover this case.

**Workaround**: add a CSS class to display-only events and set `pointer-events: none` on it.

---

## Ticket 3 — `$useFilamentTimezone` does not fix the display timezone with EventCalendar 5

**Title**: With `$useFilamentTimezone = true`, events are still displayed in the browser timezone (EventCalendar 5 converts ISO offsets)

**Description**

With `$useFilamentTimezone = true`, `CalendarEvent::toCalendarObject()` sends `start`/`end` as ISO strings in the Filament timezone, e.g. `2026-11-27T15:00:00+01:00`, and `utc_to_user_local_time()` / `browser_date_to_app_date()` read dates coming back from the browser as wall-clock times in that timezone (`shiftTimezone`). This only works if EventCalendar displays the wall-clock part of the string and ignores the offset.

The bundled `@event-calendar/core` 5.x does not: its `timeZone` option defaults to `"local"`, and an ISO string with an offset is converted to the browser timezone. A user whose computer is not set to the Filament timezone sees every event shifted.

**Reproduction** (observed on 26/11/2026, Chromium with the timezone overridden to `America/New_York`, Filament timezone `Africa/Tunis`, resource timeline week view): an event stored at 15:00 Tunis time is displayed at 09:00. With the browser in `Africa/Tunis`, it is displayed at 15:00.

**Suggested fix**

When `$useFilamentTimezone` is true, also pass EventCalendar's `timeZone` option, set to the UTC offset of the Filament timezone (EventCalendar 5 accepts `"local"`, `"UTC"` or a fixed offset such as `"+01:00"`). Event display, the now indicator, and the dates sent back on clicks and fetches then all use the Filament timezone. For timezones with daylight saving time, a fixed offset is only exact for one side of the change; a note in the README would help.

**Workaround** (used in Aviator, where `Africa/Tunis` is UTC+1 all year): add `'timeZone' => now(config('app.timezone'))->format('P')` to `getOptions()`. Verified in the browser set to `America/New_York`: events at the Tunis time, and a click on the 00:00 slot sends 00:00 Tunis time.
