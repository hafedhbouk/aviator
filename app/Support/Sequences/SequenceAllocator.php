<?php

declare(strict_types=1);

namespace App\Support\Sequences;

use App\Models\Sequence;
use App\Models\SequenceSeries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
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
 *   brouillon (RG-07), dans leur série (nextInSeries, D27) : format réglable, compteur
 *   attaché à la série et non au préfixe.
 */
final class SequenceAllocator
{
    use DetectsConcurrencyErrors;

    /** Connexion en validation automatique, réservée à la création des compteurs. */
    public const AUTOCOMMIT_CONNECTION = 'mariadb_autocommit';

    /** Essais d'une création de compteurs interrompue par un interblocage. */
    private const CREATE_ATTEMPTS = 5;

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
     * Numéro suivant d'une série à format réglable (D27) : factures, avoirs, reçus.
     *
     * Verrous pris dans cet ordre : la ligne de la série (le format ne change pas
     * pendant l'attribution), puis le compteur. La commande de réglage prend les mêmes,
     * dans le même ordre.
     */
    public function nextInSeries(Series $series, ?CarbonInterface $at = null): string
    {
        $date = $at ?? CarbonImmutable::now();
        $this->prepareSeries($series, $date);

        return DB::transaction(function () use ($series, $date): string {
            /** @var SequenceSeries $config */
            $config = SequenceSeries::query()->where('code', $series->value)->lockForUpdate()->firstOrFail();
            $format = $config->format();
            $key = $format->counterKey($series, $date);

            $sequence = Sequence::query()->where('key', $key)->lockForUpdate()->first()
                ?? throw new SequenceSeriesChangedException("Le réglage de la série « {$series->label()} » vient de changer : recommencez l'opération.");
            $value = $sequence->last_value + 1;

            if ($value > $format->maxValue()) {
                throw SequenceOverflowException::for($key, $format->padding);
            }

            $sequence->last_value = $value;
            $sequence->save();

            return $format->format($value, $date);
        });
    }

    /**
     * Crée d'avance les séries et leurs compteurs pour cette date, avant la transaction
     * du document (voir prepare()). Les compteurs des trois séries sont créés ensemble :
     * un document qui numérote dans plusieurs séries (facture et reçu de caution) ne
     * crée aucun compteur pendant qu'il en tient un autre.
     */
    public function prepareSeries(Series $series, ?CarbonInterface $at = null): void
    {
        $date = $at ?? CarbonImmutable::now();
        $keys = array_map(
            fn (Series $case): string => SequenceSeries::ensure($case)->format()->counterKey($case, $date),
            Series::cases(),
        );

        // Seulement les clés absentes : INSERT IGNORE sur une clé existante attend le
        // verrou de cette ligne, même s'il n'insère rien.
        $missing = array_values(array_diff($keys, self::existingCounters($keys)));

        if ($missing === []) {
            return;
        }

        self::createIgnoringRaces(fn () => Sequence::on(self::AUTOCOMMIT_CONNECTION)->insertOrIgnore(array_map(fn (string $key): array => [
            'key' => $key,
            'last_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $missing)));
    }

    /**
     * Création de lignes absentes (INSERT IGNORE de plusieurs lignes, en validation
     * automatique), reprise si MariaDB l'interrompt par un interblocage : plusieurs
     * processus qui créent en même temps les mêmes clés peuvent se bloquer
     * mutuellement sur les doublons (erreur 1213). L'instruction n'insère que ce qui
     * manque : la reprendre ne crée ni doublon ni compteur avancé.
     *
     * @param  callable(): mixed  $insert
     */
    public static function createIgnoringRaces(callable $insert): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $insert();

                return;
            } catch (QueryException $exception) {
                if ($attempt >= self::CREATE_ATTEMPTS || ! (new self)->causedByConcurrencyError($exception)) {
                    throw $exception;
                }

                usleep(random_int(1_000, 20_000) * $attempt);
            }
        }
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
    public function ensureCounterExists(string $key): void
    {
        // Lecture simple, sans verrou : le cas courant (compteur existant) s'arrête ici.
        if (self::existingCounters([$key]) !== []) {
            return;
        }

        self::createIgnoringRaces(fn () => Sequence::on(self::AUTOCOMMIT_CONNECTION)->insertOrIgnore([
            'key' => $key,
            'last_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * Clés de compteur déjà créées, vues par la connexion courante **ou** par la connexion
     * en validation automatique. La lecture de la transaction en cours ne voit pas un
     * compteur créé (et validé) après son premier instantané ; la recréer attendrait le
     * verrou de cette ligne, que la transaction elle-même peut tenir (interblocage avec
     * soi-même jusqu'au délai d'attente). L'autre connexion voit les lignes validées.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function existingCounters(array $keys): array
    {
        $current = Sequence::query()->whereIn('key', $keys)->pluck('key')->all();
        $missing = array_values(array_diff($keys, $current));

        if ($missing === []) {
            return $keys;
        }

        return array_values(array_unique([
            ...$current,
            ...Sequence::on(self::AUTOCOMMIT_CONNECTION)->whereIn('key', $missing)->pluck('key')->all(),
        ]));
    }
}
