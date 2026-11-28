<?php

use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\ManageLineJustifications;
use App\Domain\Billing\Actions\ValidateInvoice;
use App\Domain\Billing\ClosedPeriodGuard;
use App\Domain\Billing\Documents\InvoiceDocuments;
use App\Domain\Billing\Exceptions\InvoiceDraftException;
use App\Domain\Billing\Exceptions\InvoiceLockedException;
use App\Domain\Billing\Exceptions\InvoiceValidationException;
use App\Enums\ContractStatus;
use App\Enums\FiscalValidationStatus;
use App\Enums\InspectionType;
use App\Enums\InvoiceLineNature;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\JustificationKind;
use App\Enums\TaxNature;
use App\Models\Agency;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\DamageFile;
use App\Models\Inspection;
use App\Models\InspectionDamage;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Sequence;
use App\Models\StampDutyRule;
use App\Models\TaxCode;
use App\Models\TaxRule;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Authorization\Role;
use App\Support\Files\PrivateFiles;
use Carbon\CarbonImmutable;
use Database\Seeders\FiscalReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * Validation et émission (phase 6, lot L4, E-03, RG-06, RG-07, N2, N3, N5, E6, D53, D55,
 * référentiel §3 et §5) : exemple chiffré 115,000 → 137,850, code fiscal choisi et
 * jamais déduit du libellé « assurance », justificatif exigé pour le hors champ,
 * l'exonéré et les règles CONDITIONAL, règle PENDING_DOCUMENTATION refusée, garde-fou
 * en production, timbre jamais retiré automatiquement, numéro attribué dans la
 * transaction et jamais consommé par un refus, PDF figé à l'émission. Numéros sans
 * trou sous validation simultanée : tests/Feature/Concurrency/InvoiceValidationConcurrencyTest.php.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2027-03-20 10:00');
    Storage::fake(PrivateFiles::DISK);
    $this->seed(FiscalReferenceSeeder::class);
    Company::query()->forceCreate([
        'id' => Company::SINGLETON_ID, 'legal_name' => 'Aviator SARL', 'trade_name' => 'Aviator', 'tax_id' => '1234567/A/M/000',
        'trade_register' => 'B0123452026', 'invoice_legal_mentions' => 'Mentions de test',
    ]);

    $this->sfax = Agency::factory()->create(['code' => 'SFX', 'name' => 'Sfax']);
    $this->sousse = Agency::factory()->create(['code' => 'SOU', 'name' => 'Sousse']);
    $this->agent = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sfax->id]);
    $this->agentSousse = userWithProfile(Role::AgencyUser, ['agency_id' => $this->sousse->id]);
    $this->customer = Customer::factory()->create(['origin_agency_id' => $this->sfax->id]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * Brouillon écrit directement (le brouillon depuis le contrat est testé au lot L3).
 *
 * @param  list<array{0: string|null, 1: string, 2?: string}>  $lines  code fiscal, montant HT, libellé
 */
function draftWith(array $lines, array $attributes = []): Invoice
{
    $invoice = Invoice::query()->create([
        'agency_id' => test()->sfax->id, 'type' => InvoiceType::Invoice, 'customer_id' => test()->customer->id, ...$attributes,
    ]);

    foreach ($lines as $index => [$code, $amount]) {
        InvoiceLine::query()->create([
            'invoice_id' => $invoice->id, 'position' => $index + 1, 'nature' => InvoiceLineNature::Free,
            'tax_code_id' => $code === null ? null : TaxCode::query()->where('code', $code)->value('id'),
            'label' => $lines[$index][2] ?? (string) $code, 'quantity' => '1.000',
            'unit_amount_excl_tax' => $amount, 'total_excl_tax_amount' => $amount,
        ]);
    }

    return $invoice;
}

function validateDraft(Invoice $invoice, array $stampExemption = []): Invoice
{
    return app(ValidateInvoice::class)->handle($invoice, test()->agent, $stampExemption);
}

function invoiceCounter(): int
{
    return (int) Sequence::query()->where('key', 'series:invoice')->value('last_value');
}

function invoiceUpload(string $name): string
{
    $path = PrivateFiles::UPLOAD_DIRECTORY.'/'.$name;
    Storage::disk(PrivateFiles::DISK)->put($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

    return $path;
}

function attachProof(Invoice $invoice, JustificationKind $kind = JustificationKind::InsuranceContract): void
{
    foreach ($invoice->lines as $line) {
        app(ManageLineJustifications::class)->attachDocument($line, $kind, invoiceUpload('preuve-'.$line->id.'.pdf'), 'Pièce de test', test()->agent);
    }
}

it('calcule l\'exemple chiffré du référentiel et l\'imprime à l\'identique : 115,000 HT, 21,850 de TVA, 137,850 à payer (§5)', function () {
    $invoice = validateDraft(draftWith([['RENTAL', '100.000', 'Location'], ['GPS', '10.000', 'GPS'], ['CHILD_SEAT', '5.000', 'Siège enfant']]));

    expect($invoice->status)->toBe(InvoiceStatus::Validated)
        ->and($invoice->number)->toBe('AVI-000001')
        ->and($invoice->fiscal_year)->toBe(2027)
        ->and($invoice->validated_by)->toBe($this->agent->id)
        ->and($invoice->total_excl_tax_amount->toDecimalString())->toBe('115.000')
        ->and($invoice->total_tax_amount->toDecimalString())->toBe('21.850')
        ->and($invoice->total_incl_tax_amount->toDecimalString())->toBe('136.850')
        ->and($invoice->stamp_duty_amount->toDecimalString())->toBe('1.000')
        ->and($invoice->total_due_amount->toDecimalString())->toBe('137.850')
        ->and($invoice->stamp_duty_rule_id)->toBe(StampDutyRule::query()->where('rule_code', StampDutyRule::STANDARD)->value('id'))
        ->and($invoice->due_on?->format('Y-m-d'))->toBe('2027-03-20')
        ->and($invoice->customer_snapshot['name'] ?? null)->toBe($this->customer->displayName())
        ->and($invoice->issuer_snapshot['legal_mentions'] ?? null)->toBe('Mentions de test')
        ->and($invoice->issuer_snapshot['agency']['code'] ?? null)->toBe('SFX');

    // Une taxe par code, copie figée de la règle ; la TVA du taux 19 % reste 21,850.
    $taxes = $invoice->taxes()->orderBy('code')->get();
    expect($taxes->map(fn ($tax) => [$tax->code, $tax->base_amount->toDecimalString(), $tax->tax_amount->toDecimalString(), $tax->rule_snapshot['rate']])->all())
        ->toBe([['CHILD_SEAT', '5.000', '0.950', '19.0000'], ['GPS', '10.000', '1.900', '19.0000'], ['RENTAL', '100.000', '19.000', '19.0000']]);

    $rates = app(InvoiceDocuments::class)->rates($invoice);
    expect($rates)->toHaveCount(1)
        ->and($rates[0]['label'])->toBe('TVA 19 %')
        ->and($rates[0]['base']->toDecimalString())->toBe('115.000')
        ->and($rates[0]['tax']->toDecimalString())->toBe('21.850');

    // Mêmes montants dans le document que dans la facture enregistrée (format de Money).
    $html = view('pdf.invoice', app(InvoiceDocuments::class)->viewData($invoice))->render();
    expect($html)->toContain('AVI-000001')
        ->toContain(e($invoice->total_excl_tax_amount->format()))
        ->toContain(e($invoice->total_tax_amount->format()))
        ->toContain(e($invoice->total_incl_tax_amount->format()))
        ->toContain(e($invoice->total_due_amount->format()))
        ->toContain('137,850')
        ->toContain('Règles fiscales non confirmées');
});

it('ne déduit jamais le traitement d\'un libellé « assurance » (référentiel §2.2, annexe B)', function (string $label) {
    // Sans code fiscal choisi : refus, aucun numéro consommé.
    expect(fn () => validateDraft(draftWith([[null, '50.000', $label]])))->toThrow(InvoiceValidationException::class, 'Choisissez le code fiscal')
        ->and(invoiceCounter())->toBe(0);

    // Prime d'assurance exonérée sans contrat ni justificatif de l'assureur : refus.
    expect(fn () => validateDraft(draftWith([['INSURANCE_PREMIUM', '50.000', $label]])))->toThrow(InvoiceValidationException::class, 'exige un justificatif');

    // Protection commerciale d'Aviator : TVA 19 % (règle CONDITIONAL, avec sa pièce : Q96).
    $draft = draftWith([['ADDITIONAL_PROTECTION_SERVICE', '50.000', $label]]);
    expect(fn () => validateDraft($draft))->toThrow(InvoiceValidationException::class, 'exige un justificatif');

    attachProof($draft, JustificationKind::ContractClause);
    $invoice = validateDraft($draft->refresh());

    expect($invoice->number)->toBe('AVI-000001')
        ->and($invoice->total_tax_amount->toDecimalString())->toBe('9.500')
        ->and($invoice->taxes()->sole()->validation_status)->toBe(FiscalValidationStatus::Conditional);
})->with(['Assurance tous risques', 'ASSURANCE', 'assurance complémentaire']);

it('refuse le hors champ sans justificatif, puis l\'accepte sans TVA avec un dossier de dommage complet (N2, D55)', function (string $code) {
    expect(fn () => validateDraft(draftWith([[$code, '300.000', 'Indemnité']])))->toThrow(InvoiceValidationException::class, "le code {$code} exige un justificatif")
        ->and(invoiceCounter())->toBe(0);

    $vehicle = Vehicle::factory()->create(['agency_id' => $this->sfax->id, 'vehicle_category_id' => VehicleCategory::factory()->create()->id]);
    $contract = Contract::query()->create([
        'number' => 'CTR-2027-00001', 'agency_id' => $this->sfax->id, 'return_agency_id' => $this->sfax->id, 'vehicle_id' => $vehicle->id,
        'vehicle_category_id' => $vehicle->vehicle_category_id, 'customer_id' => $this->customer->id, 'starts_at' => '2027-03-10 09:00',
        'ends_at' => '2027-03-13 09:00', 'status' => ContractStatus::Returned, 'mileage_policy' => Contract::MILEAGE_UNLIMITED,
        'fuel_policy' => Contract::FUEL_SAME_LEVEL, 'daily_rate_amount' => '100.000', 'rental_amount' => '300.000',
        'options_amount' => '0.000', 'total_amount' => '300.000', 'quote' => [],
    ]);
    $inspection = Inspection::query()->create(['contract_id' => $contract->id, 'type' => InspectionType::Return, 'mileage' => 42000, 'fuel_eighths' => 8]);
    $damage = InspectionDamage::query()->create(['inspection_id' => $inspection->id, 'zone' => 'rear_bumper', 'x' => '10.00', 'y' => '40.00', 'description' => 'Portière enfoncée', 'severity' => 'major', 'is_new' => true]);
    $open = DamageFile::query()->create(['contract_id' => $contract->id, 'inspection_damage_id' => $damage->id, 'status' => DamageFile::OPEN]);
    $other = InspectionDamage::query()->create(['inspection_id' => $inspection->id, 'zone' => 'rear_bumper', 'x' => '50.00', 'y' => '95.00', 'description' => 'Pare-chocs fendu', 'severity' => 'major', 'is_new' => true]);
    $complete = DamageFile::query()->create(['contract_id' => $contract->id, 'inspection_damage_id' => $other->id, 'status' => DamageFile::COMPLETE, 'completed_at' => now()]);

    $draft = draftWith([['RENTAL', '300.000', 'Location'], [$code, '300.000', 'Indemnité']], ['contract_id' => $contract->id]);
    $line = $draft->lines()->where('position', 2)->sole();
    $justify = app(ManageLineJustifications::class);

    expect(fn () => $justify->attachDamageFile($line, $open, $this->agent))->toThrow(ValidationException::class);

    $justify->attachDamageFile($line, $complete, $this->agent);
    $invoice = validateDraft($draft);

    // TVA sur la location seule ; l'indemnité forme une base hors champ, sans TVA.
    expect($invoice->total_excl_tax_amount->toDecimalString())->toBe('600.000')
        ->and($invoice->total_tax_amount->toDecimalString())->toBe('57.000')
        ->and($invoice->total_due_amount->toDecimalString())->toBe('658.000')
        ->and($invoice->taxes()->where('code', $code)->sole()->nature)->toBe(TaxNature::OutOfScope)
        ->and($invoice->taxes()->where('code', $code)->sole()->tax_amount->isZero())->toBeTrue()
        // Justificatif figé avec la facture.
        ->and(fn () => $justify->detach($line->justifications()->sole(), $this->agent))->toThrow(InvoiceDraftException::class, 'Seul un brouillon');
})->with(['LATE_RETURN_PENALTY', 'VEHICLE_DAMAGE_COMPENSATION', 'INSURANCE_EXCESS_COMPENSATION']);

it('refuse un dossier de dommage d\'un autre contrat et une pièce d\'un type non accepté', function () {
    $draft = draftWith([['VEHICLE_DAMAGE_COMPENSATION', '100.000', 'Dommage']]);
    $line = $draft->lines()->sole();
    Storage::disk(PrivateFiles::DISK)->put(PrivateFiles::UPLOAD_DIRECTORY.'/script.pdf', '<?php echo 1;');

    expect(fn () => app(ManageLineJustifications::class)->attachDocument($line, JustificationKind::ClaimReport, PrivateFiles::UPLOAD_DIRECTORY.'/script.pdf', null, $this->agent))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(ManageLineJustifications::class)->attachDocument($line, JustificationKind::DamageFile, invoiceUpload('x.pdf'), null, $this->agent))
        ->toThrow(ValidationException::class)
        ->and($line->justifications()->count())->toBe(0);
});

it('refuse une règle PENDING_DOCUMENTATION, hors production comme en production (N5)', function (string $environment) {
    $code = TaxCode::query()->create([
        'code' => 'TEST_PENDING', 'label' => 'Règle de test en attente de pièce', 'nature' => TaxNature::Taxable,
        'requires_justification' => false, 'legal_reference' => 'Test',
    ]);
    TaxRule::query()->create([
        'tax_code_id' => $code->id, 'rate' => '19.0000', 'valid_from' => '2026-01-01',
        'validation_status' => FiscalValidationStatus::PendingDocumentation, 'is_active' => true,
    ]);
    app()->detectEnvironment(fn (): string => $environment);

    expect(fn () => validateDraft(draftWith([['TEST_PENDING', '100.000', 'Prestation']])))->toThrow(InvoiceValidationException::class)
        ->and(invoiceCounter())->toBe(0);
})->with(['testing', 'production']);

it('refuse toute validation en production tant que le garde-fou fiscal signale un problème (E6)', function () {
    app()->detectEnvironment(fn (): string => 'production');
    Company::query()->whereKey(Company::SINGLETON_ID)->update(['invoice_legal_mentions' => null]);
    $draft = draftWith([['RENTAL', '100.000', 'Location']]);

    expect(fn () => validateDraft($draft))->toThrow(InvoiceValidationException::class, 'mentions obligatoires des factures vides')
        ->and(invoiceCounter())->toBe(0);

    Company::query()->whereKey(Company::SINGLETON_ID)->update(['invoice_legal_mentions' => 'Mentions validées']);

    expect(validateDraft($draft)->number)->toBe('AVI-000001')
        ->and(app(InvoiceDocuments::class)->viewData($draft->refresh())['fiscalNotice'])->toBeFalse();
});

it('garde le timbre d\'une facture impayée et n\'exonère jamais par une règle en attente (N3)', function () {
    $invoice = validateDraft(draftWith([['RENTAL', '100.000', 'Location']]));

    expect($invoice->payment_status->value)->toBe('unpaid')
        ->and($invoice->stamp_duty_amount->toDecimalString())->toBe('1.000')
        ->and($invoice->stamp_exemption_rule_id)->toBeNull();

    $pending = StampDutyRule::query()->where('rule_code', 'EXEMPT_ART118_NOT_PAID')->sole();

    expect(fn () => validateDraft(draftWith([['RENTAL', '100.000', 'Location']]), [
        'rule_id' => $pending->id, 'reason' => 'Facture non acquittée dans les conditions légales', 'file' => invoiceUpload('timbre.pdf'),
    ]))->toThrow(InvoiceValidationException::class, 'PENDING_DOCUMENTATION')
        ->and(invoiceCounter())->toBe(1);
});

it('applique à la main une exonération active, avec motif et justificatif (N3)', function () {
    // Règle de test : aucune exonération n'est documentée aujourd'hui (référentiel §14, point 7).
    $rule = StampDutyRule::query()->create([
        'rule_code' => 'TEST_EXEMPTION', 'description' => 'Exonération de test', 'amount' => '0.000', 'applicability' => 'Test',
        'exemption_condition' => 'Test', 'legal_reference' => 'Test', 'effective_from' => '2026-01-01',
        'validation_status' => FiscalValidationStatus::Confirmed, 'is_active' => true,
    ]);
    $draft = draftWith([['RENTAL', '100.000', 'Location']]);

    expect(fn () => validateDraft($draft, ['rule_id' => $rule->id, 'reason' => 'court', 'file' => invoiceUpload('a.pdf')]))->toThrow(InvoiceValidationException::class, 'motif')
        ->and(fn () => validateDraft($draft, ['rule_id' => $rule->id, 'reason' => 'Motif suffisamment détaillé']))->toThrow(InvoiceValidationException::class, 'justificatif')
        ->and(fn () => validateDraft($draft, ['rule_id' => StampDutyRule::query()->where('rule_code', StampDutyRule::STANDARD)->value('id'), 'reason' => 'Motif suffisamment détaillé', 'file' => invoiceUpload('b.pdf')]))
        ->toThrow(InvoiceValidationException::class, 'inconnue');

    $invoice = validateDraft($draft, ['rule_id' => $rule->id, 'reason' => 'Motif suffisamment détaillé', 'file' => invoiceUpload('c.pdf')]);

    expect($invoice->stamp_duty_amount->isZero())->toBeTrue()
        ->and($invoice->total_due_amount->toDecimalString())->toBe('119.000')
        ->and($invoice->stamp_exemption_rule_id)->toBe($rule->id)
        ->and($invoice->stamp_duty_rule_id)->toBeNull()
        ->and($invoice->stamp_exemption_reason)->toBe('Motif suffisamment détaillé')
        ->and($invoice->stamp_exemption_file_sha256)->toBe(hash('sha256', (string) Storage::disk(PrivateFiles::DISK)->get((string) $invoice->stamp_exemption_file_path)))
        ->and($invoice->number)->toBe('AVI-000001');
});

it('ne consomme aucun numéro par un refus : la facture suivante reçoit le numéro suivant (RG-07)', function () {
    $refused = draftWith([[null, '100.000', 'Sans code']]);

    expect(fn () => validateDraft($refused))->toThrow(InvoiceValidationException::class)
        ->and(validateDraft(draftWith([['RENTAL', '100.000', 'Location']]))->number)->toBe('AVI-000001')
        ->and(validateDraft(draftWith([['RENTAL', '200.000', 'Location']]))->number)->toBe('AVI-000002')
        ->and($refused->refresh()->number)->toBeNull()
        ->and($refused->status)->toBe(InvoiceStatus::Draft);
});

it('appelle le garde de période clôturée avant le numéro et avant l\'émission (D53)', function () {
    $draft = draftWith([['RENTAL', '100.000', 'Location']]);
    app()->instance(ClosedPeriodGuard::class, new class extends ClosedPeriodGuard
    {
        public function isClosed(DateTimeInterface $date): bool
        {
            return true;
        }
    });

    expect(fn () => validateDraft($draft))->toThrow(InvoiceValidationException::class, 'est clôturée')
        ->and(invoiceCounter())->toBe(0);

    app()->forgetInstance(ClosedPeriodGuard::class);
    $invoice = validateDraft($draft);
    app()->instance(ClosedPeriodGuard::class, new class extends ClosedPeriodGuard
    {
        public function isClosed(DateTimeInterface $date): bool
        {
            return true;
        }
    });

    expect(fn () => app(IssueInvoice::class)->handle($invoice, $this->agent))->toThrow(InvoiceValidationException::class, 'est clôturée')
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Validated);
});

it('émet une facture validée avec un PDF figé, une seule fois (D56, E-10)', function () {
    $draft = draftWith([['RENTAL', '100.000', 'Location']]);

    expect(fn () => app(IssueInvoice::class)->handle($draft, $this->agent))->toThrow(InvoiceValidationException::class, 'non autorisée');

    $invoice = app(IssueInvoice::class)->handle(validateDraft($draft), $this->agent);
    $bytes = (string) Storage::disk(PrivateFiles::DISK)->get((string) $invoice->issued_pdf_path);

    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->issued_by)->toBe($this->agent->id)
        ->and($invoice->issued_at)->not->toBeNull()
        ->and($invoice->issued_pdf_path)->toBe("invoices/{$invoice->id}/AVI-000001.pdf")
        ->and(str_starts_with($bytes, '%PDF'))->toBeTrue()
        ->and($invoice->issued_pdf_sha256)->toBe(hash('sha256', $bytes))
        ->and(fn () => app(IssueInvoice::class)->handle($invoice, $this->agent))->toThrow(InvoiceValidationException::class, 'non autorisée')
        ->and(fn () => $invoice->fill(['issued_pdf_path' => 'autre.pdf'])->save())->toThrow(InvoiceLockedException::class);
});

it('refuse de valider deux fois et réserve validation et émission à l\'agence de la facture (RG-13)', function () {
    $draft = draftWith([['RENTAL', '100.000', 'Location']]);

    expect(fn () => app(ValidateInvoice::class)->handle($draft, $this->agentSousse))->toThrow(InvoiceValidationException::class, 'non autorisée')
        ->and(invoiceCounter())->toBe(0);

    $invoice = validateDraft($draft);

    expect(fn () => validateDraft($invoice))->toThrow(InvoiceValidationException::class, 'non autorisée')
        ->and(fn () => app(IssueInvoice::class)->handle($invoice, $this->agentSousse))->toThrow(InvoiceValidationException::class, 'non autorisée')
        ->and(invoiceCounter())->toBe(1);
});
