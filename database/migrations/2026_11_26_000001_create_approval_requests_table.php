<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Demandes d'approbation au super admin (phase 5, lot L1, D46) : remise au-delà du
 * seuil (RG-10) et dérogation à la liste noire (RG-15) ; retenue sur caution en
 * phase 6, mise sur liste noire en phase 7. Données de la demande figées (payload),
 * décision unique, jamais de suppression. Pose aussi les clés étrangères laissées
 * vides en phase 4 (D36) sur les réservations et les recommandations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->json('payload');
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['subject_type', 'subject_id', 'type']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreign('discount_approval_id')->references('id')->on('approval_requests')->restrictOnDelete();
            $table->foreign('override_approval_id')->references('id')->on('approval_requests')->restrictOnDelete();
        });

        Schema::table('customer_recommendations', function (Blueprint $table) {
            $table->foreign('approval_request_id')->references('id')->on('approval_requests')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_recommendations', function (Blueprint $table) {
            $table->dropForeign(['approval_request_id']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['discount_approval_id']);
            $table->dropForeign(['override_approval_id']);
        });

        Schema::dropIfExists('approval_requests');
    }
};
