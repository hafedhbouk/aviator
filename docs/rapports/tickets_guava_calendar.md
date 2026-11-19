# Tickets à ouvrir chez le mainteneur de `guava/calendar`

Préparés le 19/11/2026 après le prototype du planning (phase 4, lot L0, D35). Publication par Hafedh sur https://github.com/GuavaCZ/calendar/issues. Le texte est en anglais, langue du dépôt. Aucune donnée d'Aviator n'y figure.

| N° | Sujet | Contournement dans Aviator | Test qui garde le contournement (lot L12) | Statut |
| --- | --- | --- | --- | --- |
| 1 | Ressources enfants sérialisées en `{}` | Enfants transmis déjà convertis en tableaux | Le JSON des ressources contient l'`id` et le `title` de chaque véhicule ; un test lit la version installée et échoue si le comportement du paquet change | Prêt à publier (observé dans le navigateur pendant le prototype : lignes vides, aucun bloc) |
| 2 | Clic sur un événement sans modèle : exception | Blocs « Occupé » non cliquables ; tout bloc détaillé porte un lien | Aucun bloc sans lien n'est cliquable ; un appel serveur pour un bloc sans modèle est refusé proprement | **À reproduire** au lot L12 avant publication : établi par la lecture du code (`HasCalendarContextData::setRawCalendarContextData()` → `resolveEventRecord()`), pas encore observé dans le navigateur |
| 3 | Fuseau d'affichage (indicateur « maintenant », clic sur une date) | Heures toujours affichées à l'heure de Tunis | Navigateur réglé sur un autre fuseau (essai dans le navigateur, lot L12) | **À confirmer** par l'essai du lot L12 avant publication |

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

## Ticket 3 — Display timezone independent of the browser (to confirm before publishing)

**À confirmer au lot L12** par un essai avec un navigateur réglé sur un autre fuseau que `Africa/Tunis`. Le texte ci-dessous sera corrigé selon le résultat avant publication.

**Title**: Allow a fixed display timezone (now indicator and date clicks follow the browser timezone)

**Description**

The README states that EventCalendar uses the browser's local time. With `$useFilamentTimezone = true`, event times are sent as ISO strings in the Filament timezone, and EventCalendar appears to display their wall-clock time regardless of the offset. Two parts still depend on the browser timezone:

1. the **now indicator**, computed from the browser clock;
2. **date clicks and selections**: `browser_date_to_user_date()` reinterprets the browser wall-clock time in the Filament timezone (`shiftTimezone`), which is correct only if the clicked slot is displayed in that same timezone.

For an application whose users all work in one timezone (here `Africa/Tunis`, UTC+1 all year), a user whose computer is set to another timezone sees the now indicator in the wrong place.

**Reproduction**: set the browser or OS timezone to `America/New_York` and open a resource timeline view in an app whose Filament timezone is `Africa/Tunis`. Compare the now indicator with the current time in Tunis.

**Suggested feature**: a `$displayTimezone` (or `timeZone`) option passed to EventCalendar, used for the now indicator and for date click and select conversions.

**Workaround**: to be defined by the L12 test (server-side conversion if needed).
