<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Avoirs (phase 6, lot L6, E-04, E19, D27-4, référentiel §6, §9.5) :
 *
 * - `invoices.credit_reason` : motif de l'avoir, obligatoire (§6) ;
 * - `credit_note_settlements` : règlement d'un avoir (E19), **remboursé** (décaissement
 *   tracé : mode, référence) ou **imputé** sur une facture du même client (la facture
 *   d'origine ou une autre), totalement ou partiellement. Somme des règlements ≤ total
 *   de l'avoir, contrôlée sous verrou de l'avoir. Ajout seul : un règlement ne se
 *   modifie ni ne se supprime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('credit_reason', 500)->nullable()->after('credited_invoice_id');
        });

        Schema::create('credit_note_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('invoices')->restrictOnDelete();
            $table->string('kind', 16);
            $table->decimal('amount', 15, 3);
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference', 64)->nullable();
            $table->date('settled_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['invoice_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_settlements');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('credit_reason');
        });
    }
};
