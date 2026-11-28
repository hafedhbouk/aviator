<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Relances d'impayés (phase 6, lot L9, E-08, Q53), docs/modele_donnees.md §6 : relance
 * manuelle tracée (appel, courrier, visite ; ni SMS ni e-mail, hors périmètre), date,
 * issue. Ajout seul : l'historique d'une facture ne se modifie pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('channel', 32);
            $table->dateTime('reminded_at');
            $table->text('outcome')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['invoice_id', 'reminded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reminders');
    }
};
