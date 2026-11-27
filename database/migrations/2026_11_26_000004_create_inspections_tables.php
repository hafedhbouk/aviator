<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * États des lieux de départ et de retour (phase 5, lot L5, D-06, RG-04) : kilométrage,
 * carburant en huitièmes, dommages placés sur le schéma du véhicule, photos
 * ré-encodées (D44) et signature du client (D45) sur le disque privé. Un état des
 * lieux par contrat et par type ; validé, il n'est plus modifiable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->unsignedInteger('mileage')->nullable();
            $table->unsignedTinyInteger('fuel_eighths')->nullable();
            $table->text('notes')->nullable();
            $table->string('signature_path')->nullable();
            $table->char('signature_sha256', 64)->nullable();
            $table->string('customer_absent_reason', 255)->nullable();
            $table->dateTime('returned_at_declared')->nullable();
            $table->string('signed_pdf_path')->nullable();
            $table->char('signed_pdf_sha256', 64)->nullable();
            $table->dateTime('validated_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['contract_id', 'type']);
        });

        Schema::create('inspection_damages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();
            $table->string('zone', 32);
            $table->decimal('x', 5, 2);
            $table->decimal('y', 5, 2);
            $table->string('description', 255);
            $table->string('severity', 16);
            $table->boolean('is_new')->default(true);
            $table->foreignId('carried_from_id')->nullable()->constrained('inspection_damages')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('inspection_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_photos');
        Schema::dropIfExists('inspection_damages');
        Schema::dropIfExists('inspections');
    }
};
