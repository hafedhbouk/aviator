<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cautions (phase 6, lot L10, module F, RG-08, Q51, Q52, Q54, Q56, D58, E23),
 * docs/modele_donnees.md §6 :
 *
 * - `deposits` : une caution par contrat ; montant attendu (repris du contrat) et reçu
 *   (écart motivé, D58) ; mode, référence d'autorisation pour une empreinte de carte,
 *   **jamais de donnée de carte** (Q54) ; reçu numéroté `CAU-AAAA-NNNNN` (Q56) ; caisse
 *   pour les espèces (L8) ; cumuls restitué et retenu tenus sous verrou (RG-08 :
 *   restitué + retenu ≤ reçu) ;
 * - `deposit_movements` : restitution (reçu numéroté, mode, caisse) ou retenue (motif,
 *   justificatif, facture de frais, approbation du super admin D46, encaissement
 *   « retenue sur caution » qui règle la facture : Q51) ; ajout seul.
 *
 * Une caution n'entre jamais en chiffre d'affaires (F-07) : seule la facture de frais,
 * réglée par la retenue, porte la qualification fiscale (E23).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->decimal('expected_amount', 15, 3)->nullable();
            $table->decimal('amount_received', 15, 3);
            $table->string('difference_reason', 500)->nullable();
            $table->char('currency', 3)->default('TND');
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('reference', 64)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->dateTime('received_at')->index();
            $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('receipt_number', 32)->unique();
            $table->string('receipt_sequence_key', 64);
            $table->decimal('refunded_amount', 15, 3)->default(0);
            $table->decimal('retained_amount', 15, 3)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('deposit_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->decimal('amount', 15, 3);
            $table->string('reason', 1000)->nullable();
            $table->string('file_path')->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('approval_request_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference', 64)->nullable();
            $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('receipt_number', 32)->nullable()->unique();
            $table->string('receipt_sequence_key', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['deposit_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_movements');
        Schema::dropIfExists('deposits');
    }
};
