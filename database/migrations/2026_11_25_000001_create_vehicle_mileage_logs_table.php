<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Relevés de kilométrage (B-03, lot L4), en ajout seul : écrits uniquement par
 * RecordMileage et CorrectMileage. Une baisse n'est possible que par une correction
 * motivée, qui garde la valeur précédente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_mileage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('previous_mileage');
            $table->unsignedInteger('mileage');
            $table->nullableMorphs('source');
            $table->boolean('is_correction')->default(false);
            $table->string('reason', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->dateTime('recorded_at');

            $table->index(['vehicle_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_mileage_logs');
    }
};
