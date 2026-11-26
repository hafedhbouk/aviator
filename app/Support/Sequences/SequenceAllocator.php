<?php

declare(strict_types=1);

namespace App\Support\Sequences;

use App\Models\Sequence;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Attribue le numéro suivant d'une séquence, sans doublon ni trou (RG-02, RG-07).
 *
 * - La ligne du compteur est verrouillée (SELECT … FOR UPDATE) avant l'incrément :
 *   deux attributions simultanées sont servies l'une après l'autre.
 * - À appeler **dans la transaction qui enregistre le document numéroté** : si elle
 *   échoue, l'incrément est annulé avec elle, et le numéro sera réattribué (pas de trou).
 *   Hors transaction, l'attribution ouvre la sienne.
 * - Pour les factures et avoirs, le numéro est attribué à l'émission, jamais au
 *   brouillon (RG-07), avec le format fixé par la règle fiscale validée (Q11, Q30).
 */
final class SequenceAllocator
{
    /** Connexion en validation automatique, réservée à la création des compteurs. */
    public const AUTOCOMMIT_CONNECTION = 'mariadb_autocommit';

    public function next(SequenceDefinition $definition, ?CarbonInterface $at = null): string
    {
        $date = $at ?? CarbonImmutable::now();
        $key = $definition->keyFor($date);

        $this->ensureCounterExists($key);

        return DB::transaction(function () use ($definition, $key, $date): string {
            /** @var Sequence $sequence */
            $sequence = Sequence::query()->where('key', $key)->lockForUpdate()->firstOrFail();
            $value = $sequence->last_value + 1;

            if ($value > $definition->maxValue()) {
                throw SequenceOverflowException::for($key, $definition->length);
            }

            $sequence->last_value = $value;
            $sequence->save();

            return $definition->format($value, $date);
        });
    }

    /**
     * Crée d'avance le compteur de la période, **avant** d'ouvrir la transaction du
     * document. À appeler quand cette transaction peut déjà tenir le verrou d'un autre
     * compteur : le verrou de ligne de MariaDB couvre aussi l'intervalle qui précède la
     * clé verrouillée, et la création d'un compteur dont la clé tombe dans cet intervalle
     * attendrait la fin de la transaction (délai de verrou dépassé, erreur 1205).
     */
    public function prepare(SequenceDefinition $definition, ?CarbonInterface $at = null): void
    {
        $this->ensureCounterExists($definition->keyFor($at ?? CarbonImmutable::now()));
    }

    /**
     * Crée le compteur s'il n'existe pas encore (premier numéro d'une période).
     *
     * La création passe par une connexion distincte, en validation automatique : elle
     * ne garde aucun verrou dans la transaction du document. Créer la ligne dans cette
     * transaction (INSERT IGNORE puis SELECT … FOR UPDATE) provoque un interblocage
     * quand deux processus ouvrent la même période en même temps (MariaDB 1213 ; mis
     * en évidence par SequenceConcurrencyTest). La ligne créée vaut 0 : elle ne
     * consomme aucun numéro, même si le document échoue ensuite.
     */
    private function ensureCounterExists(string $key): void
    {
        // Lecture simple, sans verrou : le cas courant (compteur existant) s'arrête ici.
        if (Sequence::query()->where('key', $key)->exists()) {
            return;
        }

        Sequence::on(self::AUTOCOMMIT_CONNECTION)->insertOrIgnore([
            'key' => $key,
            'last_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
