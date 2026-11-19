<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Saisons et grilles tarifaires (A-04). Saisons datées sans chevauchement ; grille
 * par catégorie et saison, commune (agency_id nul) ou propre à une agence (Q10) ;
 * tranches de durée continues, prix journalier HT (Q34). Contrôles dans
 * App\Domain\Setup (SaveSeason, SaveRatePlan, RateLines).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();

            $table->index(['starts_on', 'ends_on']);
        });

        Schema::create('rate_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('season_id')->constrained()->restrictOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['vehicle_category_id', 'season_id', 'agency_id', 'valid_from'], 'rate_plans_lookup_index');
        });

        Schema::create('rate_plan_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('min_days');
            $table->unsignedSmallInteger('max_days')->nullable();
            $table->decimal('daily_amount', 15, 3);
            $table->timestamps();

            $table->unique(['rate_plan_id', 'min_days']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_plan_lines');
        Schema::dropIfExists('rate_plans');
        Schema::dropIfExists('seasons');
    }
};
