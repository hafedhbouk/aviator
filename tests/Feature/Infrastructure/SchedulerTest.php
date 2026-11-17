<?php

use App\Settings\NotificationSettings;
use App\Support\Authorization\Role;
use App\Support\Notifications\UrgentNotificationDispatcher;
use App\Support\Scheduling\QueueDrainer;
use App\Support\Scheduling\SchedulerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Jobs\RecordProcessedJob;
use Tests\Fixtures\Notifications\TestUrgentNotification;

uses(RefreshDatabase::class);

/**
 * @return list<Event>
 */
function scheduledEvents(): array
{
    return array_values(app(Schedule::class)->events());
}

function scheduledEvent(string $name): Event
{
    foreach (scheduledEvents() as $event) {
        if ($event->description === $name) {
            return $event;
        }
    }

    throw new RuntimeException("Tâche planifiée introuvable : {$name}");
}

/**
 * Remplace le traitement de la file par un compteur d'appels.
 */
function countingDrainer(): QueueDrainer
{
    $drainer = new class extends QueueDrainer
    {
        public int $calls = 0;

        public function drain(): int
        {
            $this->calls++;

            return 0;
        }
    };

    app()->instance(QueueDrainer::class, $drainer);

    return $drainer;
}

// --- D15 : tout dans le processus du planificateur ---------------------------

it('ne déclare que des tâches exécutées dans le processus (aucun Schedule::command, D15)', function () {
    expect(scheduledEvents())->not->toBeEmpty();

    foreach (scheduledEvents() as $event) {
        expect($event)->toBeInstanceOf(CallbackEvent::class, "Tâche lancée en sous-processus : {$event->command}");
    }
});

it('cale toutes les tâches sur des minutes multiples de 5 (cron toutes les 5 minutes, D20)', function () {
    foreach (scheduledEvents() as $event) {
        $minute = explode(' ', $event->expression)[0];

        $valid = $minute === '*'
            || (preg_match('#^\*/(\d+)$#', $minute, $step) === 1 && (int) $step[1] % 5 === 0)
            || collect(explode(',', $minute))->every(fn (string $m): bool => ctype_digit($m) && (int) $m % 5 === 0);

        expect($valid)->toBeTrue("Minute non multiple de 5 pour « {$event->description} » : {$event->expression}");
    }
});

it('traite la file avec --stop-when-empty et --max-time=50, sous verrou de 5 minutes', function () {
    $event = scheduledEvent('queue-worker');

    expect(app(QueueDrainer::class)->options())->toMatchArray(['--stop-when-empty' => true, '--max-time' => 50])
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(5);
});

it('traite réellement les tâches en file, dans le processus courant', function () {
    config(['queue.default' => 'database']);
    RecordProcessedJob::dispatch('alpha');

    expect(Cache::has('test:processed:alpha'))->toBeFalse();

    app(QueueDrainer::class)->drain();

    expect(Cache::get('test:processed:alpha'))->toBeTrue();
});

// --- Verrou anti-chevauchement de la file (D15) -----------------------------

it('ne démarre pas une seconde exécution tant que la première tient le verrou', function () {
    $drainer = countingDrainer();
    $event = scheduledEvent('queue-worker');

    // Première exécution en cours : elle tient le verrou.
    expect($event->mutex->create($event))->toBeTrue();

    $this->artisan('schedule:run')->assertSuccessful();
    expect($drainer->calls)->toBe(0);

    // La première exécution se termine et libère le verrou : la suivante démarre.
    $event->mutex->forget($event);

    $this->artisan('schedule:run')->assertSuccessful();
    expect($drainer->calls)->toBe(1);
});

// --- Battement de cœur (cadence du cron, D20) --------------------------------

it('enregistre l\'horodatage de chaque passage et mesure la cadence', function () {
    countingDrainer();

    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Africa/Tunis'));
    $this->artisan('schedule:run');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:05:00', 'Africa/Tunis'));
    $this->artisan('schedule:run');

    $heartbeat = app(SchedulerHeartbeat::class);

    expect($heartbeat->runs())->toHaveCount(2)
        ->and($heartbeat->measuredInterval())->toBe(300)
        ->and($heartbeat->secondsSinceLastRun())->toBe(0);
});

it('ne conserve que les deux derniers passages', function () {
    $heartbeat = app(SchedulerHeartbeat::class);

    foreach (['10:00', '10:01', '10:02'] as $time) {
        $this->travelTo(CarbonImmutable::parse("2026-11-18 {$time}:00", 'Africa/Tunis'));
        $heartbeat->beat();
    }

    expect($heartbeat->runs())->toHaveCount(2)
        ->and($heartbeat->measuredInterval())->toBe(60);
});

// --- Notifications urgentes (D20) --------------------------------------------

it('met une notification urgente en file quand le réglage est inactif (par défaut)', function () {
    Queue::fake();
    $superAdmin = userWithProfile(Role::SuperAdmin);

    expect(app(NotificationSettings::class)->send_urgent_in_request)->toBeFalse();

    app(UrgentNotificationDispatcher::class)->send($superAdmin, new TestUrgentNotification);

    Queue::assertPushed(SendQueuedNotifications::class);
    expect($superAdmin->notifications()->count())->toBe(0);
});

it('envoie une notification urgente pendant la requête quand le réglage est actif', function () {
    Queue::fake();
    $settings = app(NotificationSettings::class);
    $settings->send_urgent_in_request = true;
    $settings->save();
    $superAdmin = userWithProfile(Role::SuperAdmin);

    app(UrgentNotificationDispatcher::class)->send($superAdmin, new TestUrgentNotification);

    Queue::assertNothingPushed();
    expect($superAdmin->notifications()->count())->toBe(1);
});
