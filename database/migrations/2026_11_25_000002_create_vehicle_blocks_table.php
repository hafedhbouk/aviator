<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Périodes d'occupation des véhicules (D1, RG-01, lot L9) : réservation affectée,
 * contrat, maintenance, immobilisation. La fin inclut le délai de préparation.
 * Le contrôle anti-chevauchement porte sur cette seule table, sous verrou de la
 * ligne du véhicule (RG-02). Écrite uniquement par BlockRegistry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            $table->morphs('source');
            $table->boolean('active')->default(true);
            $table->dateTime('released_at')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'active', 'starts_at', 'ends_at']);
            $table->unique(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_blocks');
    }
};
