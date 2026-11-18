<?php

use App\Support\Authorization\Role;
use App\Support\Documents\HasSequenceNumber;
use App\Support\Documents\NumberedDocumentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Fixtures\Models\NumberedDocument;
use Tests\Fixtures\Policies\NumberedDocumentPolicy;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::policy(NumberedDocument::class, NumberedDocumentPolicy::class);
});

// --- Modèle ---------------------------------------------------------------------

it('refuse de supprimer un document qui a reçu un numéro', function () {
    $document = NumberedDocument::query()->create(['number' => 'DEMO-2026-00001', 'title' => 'Numéroté']);

    expect(fn () => $document->delete())->toThrow(NumberedDocumentException::class, 'DEMO-2026-00001')
        ->and(NumberedDocument::query()->whereKey($document->id)->exists())->toBeTrue();
});

it('laisse supprimer un brouillon sans numéro', function () {
    $draft = NumberedDocument::query()->create(['title' => 'Brouillon']);

    $draft->delete();

    expect(NumberedDocument::query()->whereKey($draft->id)->exists())->toBeFalse();
});

it('refuse une suppression en masse qui toucherait un document numéroté, sans rien supprimer', function () {
    NumberedDocument::query()->create(['number' => 'DEMO-2026-00001', 'title' => 'Numéroté']);
    NumberedDocument::query()->create(['title' => 'Brouillon']);

    expect(fn () => NumberedDocument::query()->delete())->toThrow(NumberedDocumentException::class)
        ->and(NumberedDocument::query()->count())->toBe(2);
});

it('laisse supprimer en masse des brouillons seulement', function () {
    NumberedDocument::query()->create(['number' => 'DEMO-2026-00001', 'title' => 'Numéroté']);
    NumberedDocument::query()->create(['title' => 'Brouillon A']);
    NumberedDocument::query()->create(['title' => 'Brouillon B']);

    NumberedDocument::query()->whereNull('number')->delete();

    expect(NumberedDocument::query()->pluck('title')->all())->toBe(['Numéroté']);
});

it('refuse la suppression forcée en masse d\'un document numéroté', function () {
    NumberedDocument::query()->create(['number' => 'DEMO-2026-00001', 'title' => 'Numéroté']);

    NumberedDocument::query()->forceDelete();
})->throws(NumberedDocumentException::class);

// --- Policies (interface) --------------------------------------------------------

it('n\'autorise la suppression d\'un document numéroté à personne, super admin compris', function (Role $role) {
    $user = userWithProfile($role);
    $numbered = NumberedDocument::query()->create(['number' => 'DEMO-2026-00001', 'title' => 'Numéroté']);

    expect($user->can('delete', $numbered))->toBeFalse()
        ->and($user->can('forceDelete', $numbered))->toBeFalse()
        ->and($user->can('deleteAny', NumberedDocument::class))->toBeFalse();
})->with([Role::SuperAdmin, Role::Admin, Role::AgencyUser]);

it('laisse la policy d\'un document ouvrir la suppression de ses brouillons', function () {
    $draft = NumberedDocument::query()->create(['title' => 'Brouillon']);

    expect(userWithProfile(Role::SuperAdmin)->can('delete', $draft))->toBeTrue()
        ->and(userWithProfile(Role::AgencyUser)->can('delete', $draft))->toBeFalse();
});

// --- Architecture : toute table numérotée est protégée ----------------------------

it('protège toute table qui porte une colonne « number » (HasSequenceNumber)', function () {
    $numberedTables = collect(DB::select(
        "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'number'"
    ))->pluck('TABLE_NAME')->sort()->values()->all();

    $models = collect(array_merge(glob(app_path('Models/*.php')) ?: [], glob(base_path('tests/Fixtures/Models/*.php')) ?: []))
        ->map(fn (string $file): string => (str_contains($file, 'Fixtures') ? 'Tests\\Fixtures\\Models\\' : 'App\\Models\\').basename($file, '.php'))
        ->filter(fn (string $class): bool => is_subclass_of($class, Model::class));

    $protected = $models
        ->filter(fn (string $class): bool => in_array(HasSequenceNumber::class, class_uses_recursive($class), true))
        ->map(fn (string $class): string => (new $class)->getTable())
        ->all();

    expect($numberedTables)->not->toBeEmpty()
        ->and(array_values(array_diff($numberedTables, $protected)))->toBe([]);
});
