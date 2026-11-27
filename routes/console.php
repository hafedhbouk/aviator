<?php

use App\Domain\Availability\Actions\CloseReservation;
use App\Domain\Fleet\Alerts\DueDateAlerts;
use App\Domain\Fleet\ReservedStatusSync;
use App\Domain\Fleet\StatusConsistencyCheck;
use App\Domain\Rental\Alerts\LateReturnAlerts;
use App\Support\Scheduling\QueueDrainer;
use App\Support\Scheduling\SchedulerHeartbeat;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Planificateur (un seul cron : * * * * * php artisan schedule:run)
|--------------------------------------------------------------------------
|
| D15 : toutes les tâches sont déclarées par Schedule::call() ou Schedule::job()
|       et s'exécutent dans le processus du planificateur, jamais par
|       Schedule::command(), qui lance un sous-processus PHP (proc_open, D14).
| D20 : un cron toutes les 5 minutes doit suffire. Toute tâche est idempotente
|       et calée sur des minutes multiples de 5 (:00, :05…) ; un test le vérifie.
| Ordre : les tâches rapides d'abord, le traitement de la file (jusqu'à 50 s) en dernier.
|
*/

Schedule::call(fn (SchedulerHeartbeat $heartbeat) => $heartbeat->beat())
    ->name('scheduler-heartbeat')
    ->everyMinute();

// Statut des véhicules selon leurs documents obligatoires (B-09, lot L5 ; fleet:check-status).
Schedule::call(fn (StatusConsistencyCheck $check) => $check->run())
    ->name('fleet-check-status')
    ->dailyAt('06:00');

// Alertes avant échéance : assurance, visite technique, fin de leasing (B-07, B-13, lot L7).
Schedule::call(fn (DueDateAlerts $alerts) => $alerts->run())
    ->name('fleet-alerts')
    ->dailyAt('06:05');

// Expiration des brouillons et réservations en attente (C-09, lot L11).
Schedule::call(fn (CloseReservation $reservations) => $reservations->expireDue())
    ->name('reservations-expire')
    ->everyFiveMinutes();

// Statut « Réservé » avant le départ (Q28, lot L11).
Schedule::call(fn (ReservedStatusSync $reserved) => $reserved->syncAll())
    ->name('fleet-reserved-window')
    ->hourly();

// Retards de restitution (A-09, phase 5, lot L12) : une alerte par échéance dépassée.
Schedule::call(fn (LateReturnAlerts $alerts) => $alerts->run())
    ->name('contracts-late-returns')
    ->everyFifteenMinutes();

Schedule::call(fn (QueueDrainer $drainer) => $drainer->drain())
    ->name('queue-worker')
    ->everyMinute()
    // Verrou anti-chevauchement, libéré au plus tard après 5 minutes si le passage est interrompu.
    ->withoutOverlapping(5);
