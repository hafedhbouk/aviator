<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Parc (module B, phase 4, lot L2) : fiche véhicule et documents datés.
 * Le statut est écrit par VehicleStatusService (lot L5), le kilométrage par
 * RecordMileage (lot L4) ; la sortie du parc (B-10) relève du lot L5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('internal_code', 16)->unique();
            $table->string('plate_number', 16)->unique();
            $table->string('vin', 32)->unique();
            $table->foreignId('vehicle_category_id')->constrained()->restrictOnDelete();
            $table->string('brand', 64);
            $table->string('model', 64);
            $table->unsignedSmallInteger('year');
            $table->string('color', 32)->nullable();
            $table->string('fuel', 32);
            $table->string('transmission', 32);
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->foreignId('current_agency_id')->constrained('agencies')->restrictOnDelete();
            $table->string('status', 32)->default('available')->index();
            $table->unsignedInteger('mileage')->default(0);
            $table->date('acquired_on')->nullable();
            $table->foreignId('acquisition_supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->decimal('purchase_amount', 15, 3)->nullable();
            $table->string('financing_mode', 32);
            $table->decimal('initial_contribution_amount', 15, 3)->nullable();
            $table->unsignedSmallInteger('planned_holding_months')->nullable();
            $table->date('retired_on')->nullable();
            $table->string('retirement_reason', 32)->nullable();
            $table->decimal('disposal_amount', 15, 3)->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference', 64)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->decimal('amount', 15, 3)->nullable();
            $table->string('file_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'type', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_documents');
        Schema::dropIfExists('vehicles');
    }
};
