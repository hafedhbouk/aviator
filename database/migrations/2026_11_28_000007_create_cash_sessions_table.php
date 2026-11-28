<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Journal de caisse (phase 6, lot L8, E-09, Q55, D57), docs/modele_donnees.md §6 : une
 * caisse par agence et par jour (unique), ouverte avec un fond de caisse, clôturée avec
 * le montant compté ; écart motivé. Les encaissements en espèces et les remboursements
 * d'avoirs en espèces s'y rattachent (clés étrangères posées ici). Jamais supprimée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->dateTime('opened_at');
            $table->foreignId('opened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('opening_amount', 15, 3);
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('expected_amount', 15, 3)->nullable();
            $table->decimal('counted_amount', 15, 3)->nullable();
            $table->decimal('difference_amount', 15, 3)->nullable();
            $table->string('difference_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['agency_id', 'business_date']);
            $table->index(['agency_id', 'closed_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('cash_session_id')->references('id')->on('cash_sessions')->restrictOnDelete();
        });

        Schema::table('credit_note_settlements', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable()->after('payment_method_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credit_note_settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_session_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['cash_session_id']);
        });

        Schema::dropIfExists('cash_sessions');
    }
};
