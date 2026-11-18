<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Structure seulement (D18) : les écrans et la recherche viennent en phase 7.
     * Coordonnées = téléphone, e-mail, adresse, pièce d'identité (RG-14, D13).
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('company_name')->nullable();
            $table->string('tax_id', 32)->nullable();
            $table->string('search_name')->index();
            $table->date('birth_date')->nullable();
            $table->char('nationality', 2)->nullable();

            $table->string('phone', 32)->nullable();
            $table->string('phone_search', 32)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->char('country', 2)->nullable();

            $table->string('id_document_type', 32)->nullable();
            $table->text('id_document_number')->nullable();
            $table->char('id_document_hash', 64)->nullable()->index();
            $table->date('id_document_expires_at')->nullable();

            $table->foreignId('origin_agency_id')->constrained('agencies')->restrictOnDelete();
            $table->string('acquisition_source', 32)->nullable();
            $table->foreignId('referrer_customer_id')->nullable()
                ->constrained('customers')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->dateTime('anonymized_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
