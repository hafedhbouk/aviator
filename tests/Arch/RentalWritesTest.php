<?php

use Symfony\Component\Finder\Finder;

/*
 * Documents de location et approbations (phase 5, lot L13) : écrits uniquement par
 * App\Domain (contrôle des droits, de l'état, des verrous, du journal). Aucune création,
 * modification ni suppression par appel statique de leurs modèles ailleurs dans app/
 * (ressources Filament, contrôleurs, commandes). Le SQL brut et DB::table() sont déjà
 * interdits par DatabaseAccessTest.
 */

const RENTAL_WRITE_PATTERN = '/\b(Contract|ContractOption|ContractExtension|ContractCharge|ContractAmendment|Inspection|InspectionDamage|InspectionPhoto|DamageFile|DamageFileDocument|ApprovalRequest)::(query|where|whereKey|find|findOrFail|create|forceCreate|insert|update|upsert|firstOrCreate|updateOrCreate|withoutGlobalScopes)\b[^;]*->(create|forceCreate|insert|insertOrIgnore|update|upsert|delete|forceDelete|firstOrCreate|updateOrCreate|increment|decrement)\(/s';

const RENTAL_STATIC_CREATE_PATTERN = '/\b(Contract|ContractOption|ContractExtension|ContractCharge|ContractAmendment|Inspection|InspectionDamage|InspectionPhoto|DamageFile|DamageFileDocument|ApprovalRequest)::(create|forceCreate|insert|upsert|firstOrCreate|updateOrCreate|destroy)\(/';

it('n\'écrit les documents de location et les approbations que depuis App\\Domain', function () {
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->exclude(['Domain', 'Models'])->name('*.php') as $file) {
        $code = $file->getContents();

        if (preg_match(RENTAL_WRITE_PATTERN, $code) === 1 || preg_match(RENTAL_STATIC_CREATE_PATTERN, $code) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('détecte bien une écriture hors du domaine (contrôle des motifs)', function (string $code) {
    expect(preg_match(RENTAL_WRITE_PATTERN, $code) === 1 || preg_match(RENTAL_STATIC_CREATE_PATTERN, $code) === 1)->toBeTrue();
})->with([
    "Contract::query()->whereKey(\$id)->update(['status' => 'closed']);",
    "ContractCharge::query()->where('contract_id', 1)\n    ->delete();",
    "Inspection::create(['contract_id' => 1]);",
    "ApprovalRequest::query()->withoutGlobalScopes()->whereKey(1)->update(['status' => 'approved']);",
]);

it('ne confond pas une action du domaine avec une écriture (contrôle des motifs)', function (string $code) {
    expect(preg_match(RENTAL_WRITE_PATTERN, $code) === 1 || preg_match(RENTAL_STATIC_CREATE_PATTERN, $code) === 1)->toBeFalse();
})->with([
    'app(RecordInspection::class)->update($inspection, $data, $actor);',
    "Contract::query()->with('customer')->get();",
    'app(DecideApproval::class)->approve($request, $actor);',
]);
