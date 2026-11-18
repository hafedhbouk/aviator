<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table de test uniquement : document numéroté (numéro nul pour un brouillon).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_numbered_documents', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->nullable()->unique();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_numbered_documents');
    }
};
