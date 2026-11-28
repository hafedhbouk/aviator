<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Origine précise d'une ligne de facture reprise du contrat (phase 6, lot L3) : une
 * prolongation donne une ligne de location et une ligne par option prolongée, toutes de
 * même source (`contract_extension`) ; `source_key` les distingue (`rental`,
 * `option:<identifiant>`). Sert au contrôle « aucune période facturée deux fois ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->string('source_key', 64)->nullable()->after('source_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('source_key');
        });
    }
};
