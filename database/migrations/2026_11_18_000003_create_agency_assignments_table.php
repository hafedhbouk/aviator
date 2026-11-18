<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remplacements à durée limitée (Q69) : une affectation donne accès à une autre
 * agence entre deux dates incluses (fuseau Africa/Tunis). Plusieurs périodes par
 * agence sont possibles. L'agence par défaut reste portée par users.agency_id.
 *
 * Remplace l'ancienne table agency_user, fusionnée ici avant tout déploiement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_assignments');
    }
};
