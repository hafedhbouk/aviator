<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Séries de numérotation (D27-1) : factures, avoirs, reçus de caution. Le format
 * (préfixe, modèle, longueur, remise à zéro) est attaché à la série, et le compteur
 * aussi (clé « series:<code> » dans « sequences ») : changer le préfixe ne remet pas
 * le compteur à zéro. Les compteurs existants des réservations et des contrats
 * (clé préfixe:année) ne changent pas.
 *
 * Les lignes des séries sont créées à la première utilisation, avec le réglage
 * initial du référentiel validé (App\Support\Sequences\Series).
 *
 * - year_format_reference_id, yearly_reset_reference_id : version du référentiel
 *   (confirmation écrite du cabinet) qui a levé le verrou D27-2 ; vide = verrou fermé.
 * - sequence_series_changes : journal des changements de format (avant, après, qui,
 *   quand), jamais modifié ni supprimé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequence_series', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('prefix', 24);
            $table->string('template', 64);
            $table->unsignedTinyInteger('padding');
            $table->string('reset_period', 16);
            $table->foreignId('year_format_reference_id')->nullable()->constrained('fiscal_references')->restrictOnDelete();
            $table->foreignId('yearly_reset_reference_id')->nullable()->constrained('fiscal_references')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('sequence_series_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_series_id')->constrained('sequence_series')->restrictOnDelete();
            $table->json('before');
            $table->json('after');
            $table->string('decided_by');
            $table->foreignId('confirmation_reference_id')->nullable()->constrained('fiscal_references')->restrictOnDelete();
            $table->string('operator')->nullable();
            $table->string('host')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequence_series_changes');
        Schema::dropIfExists('sequence_series');
    }
};
