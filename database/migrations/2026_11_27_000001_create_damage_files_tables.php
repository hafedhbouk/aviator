<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Dossiers de dommage (phase 5, lot L9, analyse du cabinet N2) : pour un dommage nouveau
 * constaté au retour, rassembler constat, devis ou facture de réparation et autres
 * pièces (disque privé), avec la référence du contrat ; les états des lieux et leurs
 * photos y sont rattachés par le contrat. Un dossier complet servira de justificatif
 * obligatoire aux lignes hors champ de la facture (phase 6). Pose la clé étrangère de
 * `contract_charges.damage_file_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('damage_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('inspection_damage_id')->unique()->constrained('inspection_damages')->restrictOnDelete();
            $table->string('status', 16)->default('open');
            $table->text('notes')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('damage_file_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('damage_file_id')->constrained()->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('path');
            $table->char('sha256', 64);
            $table->unsignedInteger('size_bytes');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('contract_charges', function (Blueprint $table) {
            $table->foreign('damage_file_id')->references('id')->on('damage_files')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contract_charges', function (Blueprint $table) {
            $table->dropForeign(['damage_file_id']);
        });

        Schema::dropIfExists('damage_file_documents');
        Schema::dropIfExists('damage_files');
    }
};
