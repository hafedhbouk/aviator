<?php

namespace Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

/**
 * Tâche de test : note son passage, pour vérifier que la file est bien traitée
 * dans le processus du planificateur (D15).
 */
class RecordProcessedJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public string $marker) {}

    public function handle(): void
    {
        Cache::put('test:processed:'.$this->marker, true);
    }
}
