<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GuardsProduction;
use App\Support\Authorization\Permission;
use App\Support\Authorization\Role;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Assouplit ou rétablit une règle d'accès sans changer le code (D17), sur décision
 * de la direction. Seules les permissions déclarées « assouplissables » pour le
 * profil visé sont acceptées (Permission::relaxableFor). Chaque changement est
 * tracé dans le journal d'audit : qui l'a demandé, qui l'a exécuté, quoi, quand.
 * Refusée en production sans --force.
 */
class PermissionCommand extends Command
{
    use GuardsProduction;

    /** @var string */
    protected $signature = 'aviator:permission
        {action : grant (accorder) ou revoke (retirer)}
        {role : Profil visé (admin, agency_user…)}
        {permission : Permission (exemple : customers.export_names)}
        {--by= : Personne qui a décidé du changement (direction)}
        {--force : Exécuter en production}';

    /** @var string */
    protected $description = 'Accorde ou retire une permission assouplissable à un profil (décision de la direction, journalisée)';

    public function handle(PermissionRegistrar $registrar): int
    {
        if ($this->refusesInProduction()) {
            return self::FAILURE;
        }

        $action = (string) $this->argument('action');
        $role = Role::tryFrom((string) $this->argument('role'));
        $permission = Permission::tryFrom((string) $this->argument('permission'));

        if (! in_array($action, ['grant', 'revoke'], true) || $role === null || $permission === null) {
            $this->components->error('Action, profil ou permission inconnu. Exemple : aviator:permission grant admin customers.export_names --by="Direction"');

            return self::FAILURE;
        }

        if (! in_array($role, $permission->relaxableFor(), true)) {
            $this->components->error("La permission {$permission->value} n'est pas assouplissable pour le profil {$role->value} : seule une modification du code, validée, peut la changer.");

            return self::FAILURE;
        }

        $by = trim((string) ($this->option('by') ?: $this->ask('Qui a décidé de ce changement (nom et fonction) ?')));

        if ($by === '') {
            $this->components->error('Le nom de la personne qui a décidé du changement est obligatoire.');

            return self::FAILURE;
        }

        $this->call('aviator:sync-permissions');
        $model = RoleModel::findByName($role->value);
        $hadPermission = $model->hasPermissionTo($permission->value);

        if (($action === 'grant') === $hadPermission) {
            $this->components->info('Aucun changement : la permission est déjà dans cet état.');

            return self::SUCCESS;
        }

        $action === 'grant'
            ? $model->givePermissionTo($permission->value)
            : $model->revokePermissionTo($permission->value);
        $registrar->forgetCachedPermissions();

        activity('audit')
            ->event($action === 'grant' ? 'permission_granted' : 'permission_revoked')
            ->withProperties([
                'role' => $role->value,
                'permission' => $permission->value,
                'decided_by' => $by,
                'operator' => get_current_user(),
                'host' => gethostname() ?: null,
                'via' => 'console',
            ])
            ->log(sprintf(
                'Permission %s %s le profil %s',
                $permission->value,
                $action === 'grant' ? 'accordée à' : 'retirée à',
                $role->value,
            ));

        $this->components->info(sprintf(
            '%s : %s pour le profil %s (%s).',
            $action === 'grant' ? 'Accordée' : 'Retirée',
            $permission->label(),
            $role->label(),
            $by,
        ));

        return self::SUCCESS;
    }
}
