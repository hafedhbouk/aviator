<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Lieux de prise en charge et de restitution des agences, avec leurs horaires (A-02).
 * Horaires en JSON par jour de la semaine (App\Domain\Setup\OpeningHours), sans
 * recherche dans le JSON (pas d'index JSON en MariaDB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('address')->nullable();
            $table->boolean('is_pickup')->default(true);
            $table->boolean('is_return')->default(true);
            $table->json('opening_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['agency_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_locations');
    }
};
