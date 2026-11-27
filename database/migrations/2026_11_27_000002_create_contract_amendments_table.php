<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Avenants (phase 5, lot L10, D-11) : un contrat clôturé n'est plus modifiable ; une
 * correction non financière (mention, conducteur additionnel) passe par un avenant
 * motivé, en ajout seul. Un écart financier relève d'une facture complémentaire
 * (phase 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->text('reason');
            $table->json('changes');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_amendments');
    }
};
