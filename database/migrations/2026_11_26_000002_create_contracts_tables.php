<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Contrats de location (phase 5, lot L2, module D) : contrat issu d'une réservation
 * confirmée ou du comptoir (RG-03), conducteurs (D-02), options figées. Conditions de
 * kilométrage et de carburant (D-05) et tarif journalier figé (D48) reprises de la
 * catégorie et du devis à la création. Montants en DECIMAL(15,3). Pose la clé
 * étrangère laissée vide en phase 4 sur `customer_recommendations.contract_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->foreignId('return_agency_id')->constrained('agencies')->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('departed_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->string('status', 32)->index();
            $table->string('mileage_policy', 32);
            $table->unsignedInteger('included_km_per_day')->nullable();
            $table->decimal('extra_km_amount', 15, 3)->nullable();
            $table->string('fuel_policy', 32);
            $table->unsignedTinyInteger('fuel_eighths_required')->nullable();
            $table->decimal('daily_rate_amount', 15, 3);
            $table->decimal('rental_amount', 15, 3);
            $table->decimal('options_amount', 15, 3);
            $table->decimal('discount_amount', 15, 3)->default(0);
            $table->decimal('total_amount', 15, 3);
            $table->json('quote');
            $table->decimal('deposit_expected_amount', 15, 3)->nullable();
            $table->foreignId('discount_approval_id')->nullable()->constrained('approval_requests')->restrictOnDelete();
            $table->foreignId('override_approval_id')->nullable()->constrained('approval_requests')->restrictOnDelete();
            $table->string('cancellation_reason', 255)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['agency_id', 'status', 'starts_at']);
            $table->index(['vehicle_id', 'status']);
            $table->index(['customer_id', 'starts_at']);
        });

        Schema::create('contract_drivers', function (Blueprint $table) {
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->json('checks')->nullable();
            $table->timestamps();

            $table->primary(['contract_id', 'driver_id']);
        });

        Schema::create('contract_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->decimal('unit_amount', 15, 3);
            $table->decimal('total_amount', 15, 3);
            $table->timestamps();

            $table->unique(['contract_id', 'option_id']);
        });

        Schema::table('customer_recommendations', function (Blueprint $table) {
            $table->foreign('contract_id')->references('id')->on('contracts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_recommendations', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
        });

        Schema::dropIfExists('contract_options');
        Schema::dropIfExists('contract_drivers');
        Schema::dropIfExists('contracts');
    }
};
