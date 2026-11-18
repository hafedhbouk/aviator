<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GuardsProduction;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Rechiffre les colonnes chiffrées avec l'APP_KEY actuelle, après un changement
 * de clé (D23). L'ancienne clé doit figurer dans APP_PREVIOUS_KEYS pendant
 * l'opération ; elle peut en être retirée une fois la commande réussie.
 *
 * Les valeurs sont lues brutes (getRawOriginal), sans les casts, et réécrites sans
 * toucher updated_at ; aucune portée d'agence ne s'applique (traitement complet).
 */
class ReencryptSecretsCommand extends Command
{
    use GuardsProduction;

    /**
     * Toutes les colonnes chiffrées de l'application (cast « encrypted » ou
     * « encrypted:… »), par modèle. Un test vérifie que cette liste couvre tous les modèles.
     *
     * @var array<class-string<Model>, list<string>>
     */
    public const ENCRYPTED_COLUMNS = [
        Customer::class => ['id_document_number'],
        User::class => ['app_authentication_secret', 'app_authentication_recovery_codes'],
    ];

    /** @var string */
    protected $signature = 'aviator:reencrypt-secrets {--force : Exécuter en production}';

    /** @var string */
    protected $description = 'Rechiffre les données chiffrées avec l\'APP_KEY actuelle (après un changement de clé)';

    public function handle(): int
    {
        if ($this->refusesInProduction()) {
            return self::FAILURE;
        }

        $total = 0;

        foreach (self::ENCRYPTED_COLUMNS as $modelClass => $columns) {
            $modelClass::query()->withoutGlobalScopes()->chunkById(200, function (Collection $models) use ($columns, &$total): void {
                foreach ($models as $model) {
                    $total += $this->reencryptModel($model, $columns);
                }
            });
        }

        activity('security')
            ->event('secrets_reencrypted')
            ->withProperties(['rows' => $total])
            ->log('Données chiffrées rechiffrées avec la clé actuelle');

        $this->components->info("Lignes rechiffrées : {$total}.");

        return self::SUCCESS;
    }

    /**
     * Rechiffre les colonnes non vides d'un enregistrement ; renvoie 1 s'il a été modifié.
     *
     * @param  list<string>  $columns
     */
    private function reencryptModel(Model $model, array $columns): int
    {
        $changes = [];

        foreach ($columns as $column) {
            $value = $model->getRawOriginal($column);

            if ($value === null || $value === '') {
                continue;
            }

            $changes[$column] = $this->reencrypt((string) $value, $model->getTable().'.'.$column, $model->getKey());
        }

        if ($changes === []) {
            return 0;
        }

        $model->newQueryWithoutScopes()->whereKey($model->getKey())->toBase()->update($changes);

        return 1;
    }

    /**
     * Déchiffre avec APP_KEY ou une clé de APP_PREVIOUS_KEYS, puis rechiffre avec APP_KEY.
     */
    private function reencrypt(string $payload, string $column, mixed $id): string
    {
        try {
            return Crypt::encryptString(Crypt::decryptString($payload));
        } catch (DecryptException) {
            $this->components->error(sprintf(
                'Impossible de déchiffrer %s (ligne %s) : ancienne clé absente de APP_PREVIOUS_KEYS ?',
                $column,
                is_scalar($id) ? (string) $id : '?',
            ));

            throw new RuntimeException(
                'Rechiffrement interrompu. Les lignes déjà traitées restent lisibles avec la clé actuelle : '
                .'ajoutez l\'ancienne clé à APP_PREVIOUS_KEYS, puis relancez la commande sans risque.'
            );
        }
    }
}
