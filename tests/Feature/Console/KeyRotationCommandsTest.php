<?php

use App\Console\Commands\ReencryptSecretsCommand;
use App\Models\Customer;
use App\Support\Privacy\BlindIndex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * Remplace l'APP_KEY (et éventuellement les clés précédentes) pour le reste du test.
 *
 * @param  list<string>  $previousKeys
 */
function useAppKey(string $key, array $previousKeys = []): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previousKeys]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

function randomAppKey(): string
{
    return 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC'));
}

it('recalcule les empreintes avec la nouvelle clé d\'index', function () {
    $customer = Customer::factory()->create(['id_document_number' => '0123 4567']);
    $oldHash = $customer->id_document_hash;

    $newKey = Str::random(64);
    config(['aviator.blind_index_key' => $newKey]);
    app()->forgetInstance(BlindIndex::class);

    $this->artisan('aviator:rehash-blind-index')->assertSuccessful();

    $expected = (new BlindIndex($newKey))->hash('01234567', Customer::ID_DOCUMENT_INDEX);

    expect($customer->fresh()?->id_document_hash)->toBe($expected)->not->toBe($oldHash)
        ->and(Activity::query()->where('log_name', 'security')->where('event', 'blind_index_rehashed')->exists())->toBeTrue();
});

it('rechiffre les colonnes chiffrées avec la nouvelle APP_KEY', function () {
    $oldKey = randomAppKey();
    useAppKey($oldKey);
    $customer = Customer::factory()->create(['id_document_number' => '01234567']);

    $newKey = randomAppKey();
    useAppKey($newKey, [$oldKey]);

    $this->artisan('aviator:reencrypt-secrets')->assertSuccessful();

    // Sans l'ancienne clé, la donnée reste lisible : elle est chiffrée avec la nouvelle.
    useAppKey($newKey);
    $raw = (string) DB::table('customers')->where('id', $customer->id)->value('id_document_number');

    expect(Crypt::decryptString($raw))->toBe('01234567')
        ->and(Customer::query()->findOrFail($customer->id)->id_document_number)->toBe('01234567');
});

it('s\'arrête si l\'ancienne clé manque dans APP_PREVIOUS_KEYS', function () {
    useAppKey(randomAppKey());
    Customer::factory()->create(['id_document_number' => '01234567']);

    useAppKey(randomAppKey());

    $this->artisan('aviator:reencrypt-secrets');
})->throws(RuntimeException::class, 'APP_PREVIOUS_KEYS');

it('refuse de s\'exécuter en production sans --force', function (string $command) {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan($command)->assertFailed();
})->with(['aviator:rehash-blind-index', 'aviator:reencrypt-secrets']);

it('couvre toutes les colonnes chiffrées des modèles', function () {
    $declared = collect(glob(app_path('Models/*.php')) ?: [])
        ->map(fn (string $file): string => 'App\\Models\\'.basename($file, '.php'))
        ->filter(fn (string $class): bool => is_subclass_of($class, Model::class))
        ->flatMap(function (string $class): array {
            /** @var Model $model */
            $model = new $class;

            return collect($model->getCasts())
                ->filter(fn (string $cast): bool => str_starts_with($cast, 'encrypted'))
                ->keys()
                ->map(fn (string $column): string => $model->getTable().'.'.$column)
                ->all();
        })
        ->sort()->values()->all();

    $covered = collect(ReencryptSecretsCommand::ENCRYPTED_COLUMNS)
        ->flatMap(fn (array $columns, string $class): array => array_map(fn (string $c): string => (new $class)->getTable().".{$c}", $columns))
        ->sort()->values()->all();

    expect(array_diff($declared, $covered))->toBe([]);
});
