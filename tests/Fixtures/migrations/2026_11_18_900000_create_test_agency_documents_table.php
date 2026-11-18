<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table de test uniquement (jamais migrée hors de la suite de tests) : document
 * minimal rattaché à une agence, pour éprouver AgencyScope et BelongsToAgency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_agency_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_agency_documents');
    }
};
