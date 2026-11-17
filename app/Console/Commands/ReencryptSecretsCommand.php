<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GuardsProduction;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rechiffre les colonnes chiffrées avec l'APP_KEY actuelle, après un changement
 * de clé (D23). L'ancienne clé doit figurer dans APP_PREVIOUS_KEYS pendant
 * l'opération ; elle peut en être retirée une fois la commande réussie.
 */
class ReencryptSecretsCommand extends Command
{
    use GuardsProduction;

    /**
     * Toutes les colonnes chiffrées de l'application (cast « encrypted » ou
     * « encrypted:… »). Un test vérifie que cette liste couvre tous les modèles.
     */
    public const ENCRYPTED_COLUMNS = [
        'customers' => ['id_document_number'],
        'users' => ['app_authentication_secret', 'app_authentication_recovery_codes'],
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

        foreach (self::ENCRYPTED_COLUMNS as $table => $columns) {
            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $columns, &$total): void {
                foreach ($rows as $row) {
                    $total += $this->reencryptRow($table, $columns, $row);
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
     * Rechiffre les colonnes non vides d'une ligne ; renvoie 1 si la ligne a été modifiée.
     *
     * @param  list<string>  $columns
     */
    private function reencryptRow(string $table, array $columns, object $row): int
    {
        $changes = [];

        foreach ($columns as $column) {
            $value = $row->{$column} ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $changes[$column] = $this->reencrypt((string) $value, "{$table}.{$column}", (int) $row->id);
        }

        if ($changes === []) {
            return 0;
        }

        DB::table($table)->where('id', $row->id)->update($changes);

        return 1;
    }

    /**
     * Déchiffre avec APP_KEY ou une clé de APP_PREVIOUS_KEYS, puis rechiffre avec APP_KEY.
     */
    private function reencrypt(string $payload, string $column, int $id): string
    {
        try {
            return Crypt::encryptString(Crypt::decryptString($payload));
        } catch (DecryptException) {
            $this->components->error("Impossible de déchiffrer {$column} (ligne {$id}) : ancienne clé absente de APP_PREVIOUS_KEYS ?");

            throw new RuntimeException(
                'Rechiffrement interrompu. Les lignes déjà traitées restent lisibles avec la clé actuelle : '
                .'ajoutez l\'ancienne clé à APP_PREVIOUS_KEYS, puis relancez la commande sans risque.'
            );
        }
    }
}
