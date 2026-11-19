<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fiche société (A-01) : une seule ligne, d'identifiant 1 (Company::SINGLETON_ID).
 * Devise toujours TND (D26). Logo sur le disque privé, servi par la route publique
 * dédiée (D21). Les mentions des factures ne sont saisies qu'à partir d'une règle
 * validée (docs/modele_donnees.md, règle FACTURE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('tax_id', 32)->nullable();
            $table->string('trade_register', 64)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->char('currency', 3)->default('TND');
            $table->string('logo_path')->nullable();
            $table->text('invoice_legal_mentions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company');
    }
};
