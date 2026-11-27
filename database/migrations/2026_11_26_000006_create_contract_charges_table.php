<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Frais figés au retour (phase 5, lot L8, D-09, RG-09, scénario 4) : retard,
 * kilomètres supplémentaires, carburant manquant, dommages. Chaque ligne garde le
 * détail du calcul et les paramètres utilisés ; **aucun code fiscal** (il est choisi à
 * la facture, phase 6, N2). Ajout seul. `damage_file_id` reçoit sa clé étrangère avec
 * les dossiers de dommage (lot L9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->string('nature', 32);
            $table->string('label', 255);
            $table->decimal('quantity', 10, 3);
            $table->decimal('unit_amount', 15, 3);
            $table->decimal('amount', 15, 3);
            $table->json('calculation');
            $table->foreignId('inspection_damage_id')->nullable()->constrained('inspection_damages')->restrictOnDelete();
            $table->unsignedBigInteger('damage_file_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['contract_id', 'nature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_charges');
    }
};
