<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Réservations (module C, phase 4, lot L11). Agence de départ = agence du document
 * (RG-13) ; retour à la même agence tant que Q3 = non. Devis figé en JSON (PriceQuote),
 * sans recherche dans le JSON. Les clés vers approval_requests (remise, dérogation)
 * seront posées avec cette table en phase 5 (D36).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->foreignId('return_agency_id')->constrained('agencies')->restrictOnDelete();
            $table->foreignId('pickup_location_id')->nullable()->constrained('agency_locations')->restrictOnDelete();
            $table->foreignId('return_location_id')->nullable()->constrained('agency_locations')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 32)->index();
            $table->string('channel', 16)->default('counter');
            $table->json('quote');
            $table->decimal('estimated_total_amount', 15, 3);
            $table->decimal('discount_amount', 15, 3)->default(0);
            $table->unsignedBigInteger('discount_approval_id')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedBigInteger('override_approval_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['vehicle_category_id', 'starts_at', 'ends_at']);
            $table->index(['agency_id', 'status', 'starts_at']);
        });

        Schema::create('reservation_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->decimal('unit_amount', 15, 3);
            $table->decimal('total_amount', 15, 3);

            $table->unique(['reservation_id', 'option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_options');
        Schema::dropIfExists('reservations');
    }
};
