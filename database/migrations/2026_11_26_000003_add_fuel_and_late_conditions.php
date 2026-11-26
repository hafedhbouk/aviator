<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Conditions de carburant et de retard (phase 5, lot L4, D-05, D50, D51, Q44, Q91,
 * Q92) : capacité du réservoir par véhicule et tarif horaire du retard par catégorie,
 * vides par défaut (aucun frais calculé sans eux) ; conditions figées sur le contrat à
 * sa création, avec le kilométrage déjà figé (lot L2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedSmallInteger('fuel_tank_liters')->nullable()->after('transmission');
        });

        Schema::table('vehicle_categories', function (Blueprint $table) {
            $table->decimal('late_hour_amount', 15, 3)->nullable()->after('insurance_excess_amount');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedSmallInteger('fuel_tank_liters')->nullable()->after('fuel_eighths_required');
            $table->decimal('fuel_liter_amount', 15, 3)->nullable()->after('fuel_tank_liters');
            $table->decimal('fuel_service_amount', 15, 3)->nullable()->after('fuel_liter_amount');
            $table->decimal('late_hour_amount', 15, 3)->nullable()->after('daily_rate_amount');
            $table->decimal('insurance_excess_amount', 15, 3)->nullable()->after('late_hour_amount');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['fuel_tank_liters', 'fuel_liter_amount', 'fuel_service_amount', 'late_hour_amount', 'insurance_excess_amount']);
        });

        Schema::table('vehicle_categories', function (Blueprint $table) {
            $table->dropColumn('late_hour_amount');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('fuel_tank_liters');
        });
    }
};
