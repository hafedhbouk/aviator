<?php

namespace Database\Seeders;

use App\Enums\FiscalValidationStatus;
use App\Enums\TaxNature;
use App\Models\FiscalReference;
use App\Models\StampDutyRule;
use App\Models\TaxCode;
use App\Models\TaxRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Paramètres fiscaux initiaux, recopiés du référentiel fiscal v1.0 validé
 * (docs/reference/regles_fiscales_validees/, docs/analyse_ecarts_cabinet.md V2.3) :
 * la référence et son empreinte, les 15 codes, leurs règles (6 CONFIRMED à 19 %,
 * 9 CONDITIONAL), le timbre de 1,000 DT et les exonérations de l'article 118
 * (PENDING_DOCUMENTATION, inactives). Aucun tarif « grandes surfaces » (§3.3).
 *
 * Idempotent : n'ajoute que ce qui manque et **ne modifie jamais** une règle existante.
 * Les règles prennent effet à la date de validation du référentiel.
 */
class FiscalReferenceSeeder extends Seeder
{
    public const VERSION = '1.0';

    public const SHA256 = '43b2de817cb6590f2e90875afbff9c29c795f50599073fe3ecd7cbb98158e887';

    public const FILE_NAME = 'referentiel_fiscal_aviator_v1.0_2026-11.md';

    public const VALIDATED_AT = '2026-11-18';

    private const VAT_RATE = '19.0000';

    /**
     * Code => [libellé, nature, justificatif obligatoire, référence, statut, description].
     *
     * @var array<string, array{0: string, 1: TaxNature, 2: bool, 3: string, 4: FiscalValidationStatus, 5: string}>
     */
    private const CODES = [
        'RENTAL' => ['Location de véhicule', TaxNature::Taxable, false, 'Référentiel v1.0, §2.1', FiscalValidationStatus::Confirmed, 'TVA 19 %.'],
        'CHILD_SEAT' => ['Siège enfant', TaxNature::Taxable, false, 'Référentiel v1.0, §2.1', FiscalValidationStatus::Confirmed, 'TVA 19 %.'],
        'GPS' => ['GPS', TaxNature::Taxable, false, 'Référentiel v1.0, §2.1', FiscalValidationStatus::Confirmed, 'TVA 19 %.'],
        'EXTRA_DRIVER' => ['Conducteur supplémentaire', TaxNature::Taxable, false, 'Référentiel v1.0, §2.1', FiscalValidationStatus::Confirmed, 'TVA 19 %.'],
        'EXTRA_RENTAL_PERIOD' => ['Prolongation de location', TaxNature::Taxable, false, 'Référentiel v1.0, §2.1, §2.4 A', FiscalValidationStatus::Confirmed, 'TVA 19 %, y compris le retard qui rémunère une durée supplémentaire.'],
        'FUEL_RECHARGE' => ['Carburant refacturé', TaxNature::Taxable, false, 'Référentiel v1.0, §2.3', FiscalValidationStatus::Confirmed, 'TVA 19 % en principe, dans la base imposable ; identifié distinctement sur la facture.'],
        'INSURANCE_PREMIUM' => ['Prime d\'assurance véritable', TaxNature::Exempt, true, 'Référentiel v1.0, §2.2 A', FiscalValidationStatus::Conditional, 'Exonérée de TVA s\'il s\'agit d\'une véritable opération d\'assurance ; contrat et justificatif de l\'assureur conservés. Jamais déduite d\'un libellé.'],
        'ADDITIONAL_PROTECTION_SERVICE' => ['Protection commerciale proposée par Aviator', TaxNature::Taxable, false, 'Référentiel v1.0, §2.2 B', FiscalValidationStatus::Conditional, 'TVA 19 % en principe ; prestation commerciale.'],
        'EXCESS_REDUCTION_OPTION' => ['Option de réduction de franchise', TaxNature::Taxable, false, 'Référentiel v1.0, §2.2 B, §2.6 B', FiscalValidationStatus::Conditional, 'TVA 19 % en principe.'],
        'LATE_RETURN_PENALTY' => ['Indemnité de retard véritable', TaxNature::OutOfScope, true, 'Référentiel v1.0, §2.4 B', FiscalValidationStatus::Conditional, 'Hors champ de TVA si sa nature indemnitaire est démontrée ; clause contractuelle et justificatifs conservés.'],
        'VEHICLE_DAMAGE_COMPENSATION' => ['Indemnisation d\'un dommage réel', TaxNature::OutOfScope, true, 'Référentiel v1.0, §2.5', FiscalValidationStatus::Conditional, 'Hors champ de TVA si indemnitaire ; états des lieux, photos, constat, devis ou facture, contrat.'],
        'REPAIR_SERVICE' => ['Réparation facturée', TaxNature::Taxable, false, 'Référentiel v1.0, §2.5', FiscalValidationStatus::Conditional, 'TVA selon le régime applicable, normalement 19 %.'],
        'DAMAGE_ADMIN_FEE' => ['Frais administratifs de gestion d\'un dommage', TaxNature::Taxable, false, 'Référentiel v1.0, §2.5', FiscalValidationStatus::Conditional, 'TVA 19 % en principe.'],
        'INSURANCE_EXCESS_COMPENSATION' => ['Franchise indemnitaire', TaxNature::OutOfScope, true, 'Référentiel v1.0, §2.6 A', FiscalValidationStatus::Conditional, 'Hors champ de TVA si indemnitaire ; justificatifs du sinistre obligatoires.'],
        'CLAIM_ADMIN_FEE' => ['Frais de gestion du sinistre', TaxNature::Taxable, false, 'Référentiel v1.0, §2.6 C', FiscalValidationStatus::Conditional, 'TVA 19 % en principe.'],
    ];

    /**
     * Exonérations de l'article 118 citées par le référentiel (§3.3) : inactives tant que
     * leurs conditions et justificatifs ne sont pas documentés (§14, point 7).
     *
     * @var array<string, string>
     */
    private const STAMP_EXEMPTIONS = [
        'EXEMPT_ART118_NOT_ACCEPTED' => 'Factures non acceptées par les débiteurs, dans les conditions légales',
        'EXEMPT_ART118_NOT_PAID' => 'Factures non acquittées, dans les conditions légales (une facture marquée « impayée » ne suffit pas)',
        'EXEMPT_ART118_EXPORT' => 'Factures relatives à certaines opérations d\'exportation',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $reference = FiscalReference::query()->firstOrCreate(['version' => self::VERSION], [
                'title' => 'Référentiel fiscal et comptable Aviator',
                'file_name' => self::FILE_NAME,
                'sha256' => self::SHA256,
                'validation_source' => 'Confirmation du cabinet conservée par Aviator, attestée par Faouzi Bannour, directeur général, le 18/11/2026',
                'validated_at' => self::VALIDATED_AT,
                'attestation_file_name' => 'attestation_direction_2026-11-18.pdf',
            ]);

            foreach (self::CODES as $code => [$label, $nature, $justification, $legal, $status, $description]) {
                $taxCode = TaxCode::query()->firstOrCreate(['code' => $code], [
                    'label' => $label,
                    'nature' => $nature,
                    'requires_justification' => $justification,
                    'legal_reference' => $legal,
                    'description' => $description,
                ]);

                if ($taxCode->rules()->doesntExist()) {
                    TaxRule::query()->create([
                        'tax_code_id' => $taxCode->id,
                        'rate' => $nature->hasRate() ? self::VAT_RATE : null,
                        'valid_from' => self::VALIDATED_AT,
                        'validation_status' => $status,
                        'fiscal_reference_id' => $reference->id,
                        'is_active' => true,
                    ]);
                }
            }

            $this->stampRule(StampDutyRule::STANDARD, [
                'description' => 'Timbre fiscal par facture ordinaire concernée, sur une ligne distincte, ajouté au total TTC',
                'amount' => '1.000',
                'applicability' => 'Factures de location aux particuliers et aux entreprises, y compris avec options et prestations annexes ; le mode de paiement n\'exonère pas',
                'legal_reference' => 'Article 117 du Code des droits d\'enregistrement et de timbre ; référentiel v1.0, §3.1, §3.2',
                'validation_status' => FiscalValidationStatus::Confirmed,
                'fiscal_reference_id' => $reference->id,
                'is_active' => true,
            ]);

            foreach (self::STAMP_EXEMPTIONS as $code => $condition) {
                $this->stampRule($code, [
                    'description' => 'Exonération du timbre fiscal (article 118)',
                    'amount' => '0.000',
                    'applicability' => 'Jamais automatique : conditions légales et justificatifs vérifiés',
                    'exemption_condition' => $condition,
                    'legal_reference' => 'Article 118 du Code des droits d\'enregistrement et de timbre ; référentiel v1.0, §3.3, §14 (point 7)',
                    'validation_status' => FiscalValidationStatus::PendingDocumentation,
                    'fiscal_reference_id' => $reference->id,
                    'is_active' => false,
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stampRule(string $code, array $attributes): void
    {
        if (StampDutyRule::query()->where('rule_code', $code)->exists()) {
            return;
        }

        StampDutyRule::query()->create(['rule_code' => $code, 'effective_from' => self::VALIDATED_AT] + $attributes);
    }
}
