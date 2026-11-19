<?php

use App\Models\TaxCode;
use App\Models\TaxRule;
use App\Support\Authorization\Role;
use Database\Seeders\FiscalReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Versions de règles fiscales sous saisie simultanée (plan de la phase 3, §6) : deux
 * processus PHP distincts ajoutent en même temps une version de RENTAL à la même date.
 * Une seule passe ; aucune période ne se chevauche.
 */

uses(DatabaseTruncation::class);

/*
 * Les données de ce test sont validées (commit) : elles sont effacées après le test,
 * sinon les tests suivants (RefreshDatabase, simple transaction) les verraient.
 * Requêtes directes : les modèles fiscaux interdisent la suppression.
 */
afterEach(function () {
    foreach (['activity_log', 'tax_rules', 'stamp_duty_rules', 'tax_codes', 'fiscal_references', 'model_has_roles', 'users'] as $table) {
        DB::table($table)->delete();
    }
});

function startTaxWorker(int $userId, string $date, string $rate): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/tax_rule_worker.php'), (string) $userId, $date, $rate],
        base_path(),
        ['APP_ENV' => 'testing'],
        null,
        120,
    );
    $process->start();

    return $process;
}

/**
 * @return array{ok: bool, id?: int, error?: string}
 */
function taxWorkerResult(Process $process): array
{
    $process->wait();

    expect($process->isSuccessful())->toBeTrue('Processus en échec : '.$process->getErrorOutput().$process->getOutput());

    /** @var array{ok: bool, id?: int, error?: string} */
    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('n\'accepte qu\'une des deux versions saisies en même temps à la même date', function () {
    $this->seed(FiscalReferenceSeeder::class);
    $superAdmin = userWithProfile(Role::SuperAdmin);

    $first = startTaxWorker($superAdmin->id, '2027-01-01', '19');
    $second = startTaxWorker($superAdmin->id, '2027-01-01', '18');

    $results = [taxWorkerResult($first), taxWorkerResult($second)];
    $succeeded = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
    $refused = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));

    $rules = TaxRule::query()->where('tax_code_id', TaxCode::query()->where('code', 'RENTAL')->value('id'))
        ->orderBy('valid_from')->get();

    expect($succeeded)->toHaveCount(1)
        ->and($refused)->toHaveCount(1)
        ->and($refused[0]['error'] ?? '')->toContain('commencer après')
        ->and($rules)->toHaveCount(2)
        ->and($rules[0]->valid_to?->toDateString())->toBe('2026-12-31')
        ->and($rules[1]->valid_from->toDateString())->toBe('2027-01-01')
        ->and($rules[1]->valid_to)->toBeNull();
});
