<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Catégories de véhicules (A-03) et leurs surcharges : caution (Q14), conditions du
 * conducteur (Q42), kilométrage inclus et prix du km supplémentaire (Q43), franchise
 * (Q45), délai de préparation (Q41). Une valeur vide = valeur générale ou « non fixé ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->decimal('deposit_amount', 15, 3)->nullable();
            $table->unsignedTinyInteger('min_driver_age')->nullable();
            $table->unsignedTinyInteger('min_license_years')->nullable();
            $table->unsignedInteger('included_km_per_day')->nullable();
            $table->decimal('extra_km_amount', 15, 3)->nullable();
            $table->decimal('insurance_excess_amount', 15, 3)->nullable();
            $table->unsignedSmallInteger('preparation_buffer_minutes')->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_categories');
    }
};
