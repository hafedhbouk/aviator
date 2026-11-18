<?php

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

Schedule::call(fn (QueueDrainer $drainer) => $drainer->drain())
    ->name('queue-worker')
    ->everyMinute()
    // Verrou anti-chevauchement, libéré au plus tard après 5 minutes si le passage est interrompu.
    ->withoutOverlapping(5);
