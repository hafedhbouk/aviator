<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Clients au comptoir (phase 4, lot L8) :
 * - drivers : avancée de la phase 5 pour la recherche par permis (D24) ; permis et
 *   pièce chiffrés, recherche exacte par empreinte HMAC (D8, D23) ;
 * - customer_recommendations : première version (RG-15, RG-16), en ajout seul ;
 *   l'écran, les propositions et les approbations restent en phase 7 ; les clés vers
 *   contracts et approval_requests seront posées avec ces tables (phase 5) ;
 * - customers.name_key : nom aux mots triés, pour la recherche exacte du nom complet
 *   « dans n'importe quel ordre » (D24).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->date('birth_date')->nullable();
            $table->text('license_number');
            $table->char('license_hash', 64)->index();
            $table->date('license_issued_on')->nullable();
            $table->date('license_expires_on')->nullable();
            $table->char('license_country', 2)->nullable();
            $table->text('id_document_number')->nullable();
            $table->char('id_document_hash', 64)->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('customer_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->string('level', 32);
            $table->string('reason_code', 32);
            $table->text('comment')->nullable();
            $table->string('status', 32);
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'status', 'created_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('name_key', 255)->default('')->after('search_name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['name_key']);
            $table->dropColumn('name_key');
        });
        Schema::dropIfExists('customer_recommendations');
        Schema::dropIfExists('drivers');
    }
};
