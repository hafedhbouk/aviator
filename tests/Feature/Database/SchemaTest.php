<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * @return list<object{TABLE_NAME: string, ENGINE: string, TABLE_COLLATION: string}>
 */
function aviatorTables(): array
{
    return DB::select(
        'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
    );
}

it('crée toutes les tables du socle (D18)', function () {
    $expected = [
        'agencies', 'users', 'agency_assignments', 'sequences', 'customers',
        'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        'activity_log', 'settings', 'notifications',
        'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    foreach ($expected as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Table manquante : {$table}");
    }
});

it('crée toutes les tables en InnoDB et utf8mb4_unicode_ci', function () {
    foreach (aviatorTables() as $table) {
        expect($table->ENGINE)->toBe('InnoDB', "Moteur de {$table->TABLE_NAME}")
            ->and($table->TABLE_COLLATION)->toBe('utf8mb4_unicode_ci', "Interclassement de {$table->TABLE_NAME}");
    }
});

it("n'utilise aucune colonne FLOAT ni DOUBLE", function () {
    $floats = DB::select(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('float', 'double')"
    );

    expect($floats)->toBe([]);
});

it('complète la table users pour les agences, la désactivation et la 2FA', function () {
    expect(Schema::hasColumns('users', [
        'agency_id', 'is_active', 'last_login_at', 'app_authentication_secret', 'app_authentication_recovery_codes',
    ]))->toBeTrue();
});

it('protège les agences contre la suppression en cascade', function () {
    $rules = collect(DB::select(
        'SELECT TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?',
        ['agencies']
    ))->mapWithKeys(fn (object $row): array => [$row->TABLE_NAME => $row->DELETE_RULE]);

    expect($rules['users'])->toBe('RESTRICT')
        ->and($rules['customers'])->toBe('RESTRICT')
        ->and($rules['agency_assignments'])->toBe('RESTRICT');
});

it('impose l\'unicité des codes d\'agence et des clés de séquence', function () {
    $unique = fn (string $table, string $column): bool => DB::scalar(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND NON_UNIQUE = 0',
        [$table, $column]
    ) > 0;

    expect($unique('agencies', 'code'))->toBeTrue()
        ->and($unique('sequences', 'key'))->toBeTrue();
});

it('indexe les colonnes de recherche exacte des clients', function () {
    $indexed = collect(DB::select(
        'SELECT DISTINCT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        ['customers']
    ))->pluck('COLUMN_NAME')->all();

    expect($indexed)->toContain('search_name', 'phone_search', 'id_document_hash', 'origin_agency_id');
});
