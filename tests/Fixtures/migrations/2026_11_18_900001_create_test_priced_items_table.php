<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table de test uniquement : montants en DECIMAL(15,3), pour éprouver MoneyCast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_priced_items', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 15, 3);
            $table->decimal('optional_amount', 15, 3)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_priced_items');
    }
};
