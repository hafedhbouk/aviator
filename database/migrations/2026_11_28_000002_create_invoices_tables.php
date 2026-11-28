<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Factures, factures complémentaires et avoirs (phase 6, lot L2), docs/modele_donnees.md
 * §6 avec les écarts du plan de la phase 6 (§3) :
 *
 * - deux statuts (E11, D56) : document (`status` : draft, validated, issued, credited,
 *   cancelled) et paiement (`payment_status`, calculé depuis les imputations, jamais saisi) ;
 * - numéro attribué à la validation (RG-07), jamais au brouillon ; série et clé du
 *   compteur conservées ; année d'émission (heure de Tunis) dans un champ distinct
 *   (référentiel §4.1) ;
 * - dinar seul (D26) : `currency` vaut TND, toute autre devise est refusée par le modèle ;
 * - client et émetteur figés à la validation (D59, E8) ;
 * - timbre : règle appliquée et, le cas échéant, exonération manuelle (N3) ;
 * - PDF figé à l'émission (empreinte SHA-256).
 *
 * Une facture validée ne se modifie plus (modèle et constructeur de requêtes) ; pas de
 * déclencheur en base (docs/architecture.md : droits rarement accordés en mutualisé).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // RNE du client entreprise (D59), vide par défaut.
            $table->string('trade_register', 64)->nullable()->after('tax_id');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->string('number', 32)->nullable()->unique();
            $table->string('series', 32)->nullable();
            $table->string('sequence_key', 64)->nullable();
            $table->unsignedSmallInteger('fiscal_year')->nullable();
            $table->string('status', 32)->index();
            $table->string('payment_status', 32)->index();
            $table->foreignId('contract_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('credited_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->dateTime('period_start')->nullable();
            $table->dateTime('period_end')->nullable();
            $table->char('currency', 3)->default('TND');
            $table->json('customer_snapshot')->nullable();
            $table->json('issuer_snapshot')->nullable();
            $table->decimal('total_excl_tax_amount', 15, 3)->default(0);
            $table->decimal('total_tax_amount', 15, 3)->default(0);
            $table->decimal('total_incl_tax_amount', 15, 3)->default(0);
            $table->decimal('stamp_duty_amount', 15, 3)->default(0);
            $table->decimal('total_due_amount', 15, 3)->default(0);
            $table->foreignId('stamp_duty_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('stamp_exemption_rule_id')->nullable()->constrained('stamp_duty_rules')->restrictOnDelete();
            $table->string('stamp_exemption_reason', 500)->nullable();
            $table->string('stamp_exemption_file_path')->nullable();
            $table->char('stamp_exemption_file_sha256', 64)->nullable();
            $table->date('due_on')->nullable();
            $table->dateTime('validated_at')->nullable()->index();
            $table->dateTime('issued_at')->nullable()->index();
            $table->string('issued_pdf_path')->nullable();
            $table->char('issued_pdf_sha256', 64)->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['contract_id', 'period_start']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('nature', 32);
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('tax_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('label');
            $table->decimal('quantity', 10, 3);
            $table->decimal('unit_amount_excl_tax', 15, 3);
            $table->decimal('discount_amount', 15, 3)->default(0);
            $table->decimal('total_excl_tax_amount', 15, 3);
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });

        Schema::create('invoice_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_rule_id')->constrained()->restrictOnDelete();
            $table->foreignId('tax_code_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('nature', 16);
            $table->decimal('rate', 7, 4)->nullable();
            $table->decimal('base_amount', 15, 3);
            $table->decimal('tax_amount', 15, 3);
            $table->string('validation_status', 32);
            $table->json('rule_snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_taxes');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('trade_register');
        });
    }
};
