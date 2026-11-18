<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Closure;
use Spatie\Activitylog\Contracts\LoggablePipe;
use Spatie\Activitylog\EventLogBag;

/**
 * Signale, dans l'entrée d'audit, les colonnes sensibles modifiées (mot de passe,
 * secrets de 2FA, numéro de pièce…) sans jamais en copier la valeur.
 */
final class MarkSensitiveChanges implements LoggablePipe
{
    public function handle(EventLogBag $event, Closure $next): EventLogBag
    {
        $model = $event->model;

        if (! method_exists($model, 'sensitiveAuditAttributes')) {
            return $next($event);
        }

        /** @var list<string> $sensitive */
        $sensitive = $model->sensitiveAuditAttributes();
        $raw = $model->getAttributes();

        $changed = array_values(array_filter($sensitive, fn (string $attribute): bool => match ($event->event) {
            'created' => ($raw[$attribute] ?? null) !== null && ($raw[$attribute] ?? null) !== '',
            'updated' => $model->wasChanged($attribute),
            default => false,
        }));

        if ($changed !== []) {
            $event->changes['sensitive_changed'] = $changed;
        }

        return $next($event);
    }
}
