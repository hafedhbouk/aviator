<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\PasswordDoesNotContainIdentity;
use App\Support\Authorization\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Crée un compte super admin (Q2) en demandant le nom, l'e-mail et le mot de passe
 * au clavier. Aucun compte n'est créé par les seeders. Au plus deux super admins
 * (compte principal et compte de secours, aviator.max_super_admins). La 2FA est
 * imposée à la première connexion (EnsurePrivilegedTwoFactor).
 */
class CreateSuperAdminCommand extends Command
{
    /** @var string */
    protected $signature = 'aviator:create-super-admin';

    /** @var string */
    protected $description = 'Crée un compte super admin (saisie au clavier ; 2FA imposée à la première connexion)';

    public function handle(): int
    {
        Artisan::call('aviator:sync-permissions');

        $max = (int) config('aviator.max_super_admins', 2);

        if ($this->superAdminCount() >= $max) {
            $this->components->error("Nombre maximal de super admins atteint ({$max}). Aucun compte créé.");

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Nom complet'));
        $email = mb_strtolower(trim((string) $this->ask('Adresse e-mail')));
        $password = (string) $this->secret('Mot de passe (12 caractères minimum, majuscules, minuscules, chiffres et symboles)');
        $confirmation = (string) $this->secret('Confirmez le mot de passe');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
                'password' => ['required', 'confirmed', Password::defaults(), new PasswordDoesNotContainIdentity($email, $name)],
            ],
            [],
            ['name' => 'nom', 'email' => 'adresse e-mail', 'password' => 'mot de passe'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            $this->components->error('Aucun compte créé.');

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($name, $email, $password, $max): ?User {
            // Contrôle refait sous verrou : deux créations simultanées ne dépassent pas la limite.
            RoleModel::query()->where('name', Role::SuperAdmin->value)->lockForUpdate()->first();

            if ($this->superAdminCount() >= $max) {
                return null;
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'agency_id' => null,
                'is_active' => true,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole(Role::SuperAdmin->value);

            return $user;
        });

        if (! $user instanceof User) {
            $this->components->error("Nombre maximal de super admins atteint ({$max}). Aucun compte créé.");

            return self::FAILURE;
        }

        activity('security')
            ->event('super_admin_created')
            ->performedOn($user)
            ->withProperties(['email' => $user->email, 'via' => 'console'])
            ->log('Compte super admin créé');

        $this->components->info("Super admin créé : {$user->email}. La 2FA sera demandée à la première connexion.");

        return self::SUCCESS;
    }

    private function superAdminCount(): int
    {
        return User::role(Role::SuperAdmin->value)->count();
    }
}
