<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Historique des modifications des données (J-05) : qui, quand, avant, après, dans
 * le journal « audit ». Les colonnes sensibles (sensitiveAuditAttributes) ne sont
 * jamais copiées : seul leur changement est signalé (sensitive_changed).
 *
 * À poser sur chaque modèle métier, avec la liste de ses colonnes sensibles.
 */
trait AuditsChanges
{
    use LogsActivity;

    public static function bootAuditsChanges(): void
    {
        foreach (static::$changesPipes as $pipe) {
            if ($pipe instanceof MarkSensitiveChanges) {
                return;
            }
        }

        static::addLogChange(new MarkSensitiveChanges);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('audit')
            ->logFillable()
            ->logExcept($this->sensitiveAuditAttributes())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Colonnes dont la valeur n'est jamais copiée dans le journal.
     *
     * @return list<string>
     */
    public function sensitiveAuditAttributes(): array
    {
        return [];
    }

    /**
     * Une entrée qui ne signale qu'un changement de colonne sensible n'est pas vide.
     *
     * @param  array<string, mixed>  $changes
     */
    public function isLogEmpty(array $changes): bool
    {
        return empty($changes['attributes'] ?? [])
            && empty($changes['old'] ?? [])
            && empty($changes['sensitive_changed'] ?? []);
    }
}
