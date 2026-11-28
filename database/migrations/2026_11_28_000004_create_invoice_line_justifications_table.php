<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Justificatifs d'une ligne de facture (phase 6, lot L4, N2, D55) : une ligne dont le
 * code fiscal exige un justificatif (exonéré, hors champ) ou dont la règle est
 * CONDITIONAL ne se valide qu'avec au moins un justificatif rattaché : dossier de
 * dommage complet du contrat (phase 5) ou pièce jointe privée (chemin et empreinte
 * SHA-256). Rattaché et retiré seulement tant que la facture est un brouillon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_line_justifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_line_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->foreignId('damage_file_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('file_path')->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_line_justifications');
    }
};
