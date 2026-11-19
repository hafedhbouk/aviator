<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Options facturables (A-05), chacune liée à un code fiscal du référentiel validé,
 * jamais déduit de son libellé. Prix HT (Q34). Une option sans code fiscal ni prix ne
 * peut pas être activée (SaveOption) : les options du cahier sont créées inactives,
 * sans prix, et « livraison » sans code (Q85).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('options', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->string('pricing_unit', 32);
            $table->decimal('amount', 15, 3)->nullable();
            $table->decimal('max_amount_per_rental', 15, 3)->nullable();
            $table->foreignId('tax_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('options');
    }
};
