<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Paramètres fiscaux (A-08) selon le référentiel fiscal v1.0 validé
 * (docs/reference/regles_fiscales_validees/), plan de la phase 3 §3.1. Remplacent la
 * table « taxes » prévue à l'origine.
 *
 * - fiscal_references : une ligne par version validée du référentiel, jamais modifiée.
 * - tax_codes : liste fermée des 15 codes, créée par le seeder.
 * - tax_rules : versions datées par code, jamais modifiées sur place (seuls la fin de
 *   validité et l'état actif changent), sans chevauchement.
 * - stamp_duty_rules : timbre fiscal (§3.4), mêmes principes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_references', function (Blueprint $table) {
            $table->id();
            $table->string('version', 16)->unique();
            $table->string('title');
            $table->string('file_name');
            $table->char('sha256', 64);
            $table->string('validation_source', 500);
            $table->date('validated_at');
            $table->string('attestation_file_name')->nullable();
            $table->timestamps();
        });

        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('label');
            $table->string('nature', 16);
            $table->boolean('requires_justification')->default(false);
            $table->string('legal_reference');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_code_id')->constrained()->restrictOnDelete();
            $table->decimal('rate', 7, 4)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('validation_status', 32);
            $table->foreignId('fiscal_reference_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['tax_code_id', 'valid_from']);
        });

        Schema::create('stamp_duty_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_code', 64);
            $table->string('description', 500);
            $table->decimal('amount', 15, 3);
            $table->string('applicability', 500);
            $table->string('exemption_condition', 500)->nullable();
            $table->string('legal_reference');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('validation_status', 32);
            $table->foreignId('fiscal_reference_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['rule_code', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stamp_duty_rules');
        Schema::dropIfExists('tax_rules');
        Schema::dropIfExists('tax_codes');
        Schema::dropIfExists('fiscal_references');
    }
};
