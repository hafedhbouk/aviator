<?php

declare(strict_types=1);

namespace App\Support\Locking;

use App\Models\NamedLock;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Verrou de sérialisation en base (D38) : la ligne `named_locks` du nom donné est
 * verrouillée par SELECT … FOR UPDATE et le reste jusqu'à la fin de la transaction.
 * Une seconde saisie attend donc que la première soit validée (ou annulée), puis voit
 * ses écritures. Indépendant du magasin de cache ; fonctionne entre processus.
 *
 * Le délai d'attente est celui d'InnoDB (innodb_lock_wait_timeout).
 */
final class DatabaseLock
{
    /**
     * Exécute le traitement dans une transaction, sous le verrou nommé.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string $name, Closure $callback): mixed
    {
        return DB::transaction(function () use ($name, $callback): mixed {
            $this->acquire($name);

            return $callback();
        });
    }

    /**
     * Prend le verrou dans la transaction en cours ; il est rendu à sa fin.
     */
    public function acquire(string $name): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException("Le verrou « {$name} » exige une transaction ouverte.");
        }

        // Création idempotente de la ligne ; sous saisie simultanée, la clé primaire
        // fait attendre la seconde insertion jusqu'à la fin de la première transaction.
        NamedLock::query()->insertOrIgnore(['name' => $name, 'created_at' => now()]);

        NamedLock::query()->whereKey($name)->lockForUpdate()->firstOrFail();
    }
}
