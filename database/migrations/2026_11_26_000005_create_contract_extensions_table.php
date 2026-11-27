<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Prolongations de contrat (phase 5, lot L7, D-07, D48, scénario 3) : ancienne et
 * nouvelle date de retour, jours ajoutés au tarif journalier figé du contrat, options
 * par jour prolongées (Q94), remise éventuelle soumise au seuil (RG-10, Q93). Ajout
 * seul : une prolongation n'est jamais modifiée ni supprimée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->dateTime('previous_ends_at');
            $table->dateTime('new_ends_at');
            $table->unsignedSmallInteger('added_days');
            $table->decimal('daily_rate_amount', 15, 3);
            $table->decimal('added_rental_amount', 15, 3);
            $table->decimal('added_options_amount', 15, 3);
            $table->decimal('discount_amount', 15, 3)->default(0);
            $table->foreignId('discount_approval_id')->nullable()->constrained('approval_requests')->restrictOnDelete();
            $table->decimal('total_amount', 15, 3);
            $table->json('options')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['contract_id', 'new_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_extensions');
    }
};
