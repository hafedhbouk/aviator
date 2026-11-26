<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Leasing et crédit (B-11 à B-15, phase 4, lot L6). Un financement en cours au plus
 * par véhicule. L'échéancier est généré par l'application ; chaque échéance est
 * imputée au mois de son échéance (RG-17, Q58), une seule fois (unique par rang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_financings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('reference', 64);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('first_due_on');
            $table->unsignedSmallInteger('installments_count');
            $table->decimal('initial_payment_amount', 15, 3)->nullable();
            $table->decimal('monthly_amount_excl_tax', 15, 3);
            $table->decimal('monthly_amount_incl_tax', 15, 3);
            $table->decimal('residual_value_amount', 15, 3)->nullable();
            $table->decimal('principal_amount', 15, 3)->nullable();
            $table->decimal('interest_rate', 7, 4)->nullable();
            $table->date('purchase_option_on')->nullable();
            $table->string('outcome', 32)->nullable();
            $table->date('outcome_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('vehicle_id');
        });

        Schema::create('financing_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_financing_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->decimal('amount_excl_tax', 15, 3);
            $table->decimal('amount_incl_tax', 15, 3);
            $table->decimal('interest_amount', 15, 3)->nullable();
            $table->date('charge_month');
            $table->date('paid_on')->nullable();
            $table->timestamps();

            $table->unique(['vehicle_financing_id', 'sequence']);
            $table->index('charge_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financing_installments');
        Schema::dropIfExists('vehicle_financings');
    }
};
