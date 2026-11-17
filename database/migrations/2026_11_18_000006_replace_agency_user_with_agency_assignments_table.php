<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remplacements à durée limitée (Q69) : une affectation donne accès à une autre
 * agence entre deux dates incluses (fuseau Africa/Tunis). Plusieurs périodes par
 * agence sont possibles. L'agence par défaut reste portée par users.agency_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'starts_on', 'ends_on']);
        });

        Schema::dropIfExists('agency_user');
    }

    public function down(): void
    {
        Schema::create('agency_user', function (Blueprint $table) {
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['agency_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::dropIfExists('agency_assignments');
    }
};
