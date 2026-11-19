<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Verrous de sérialisation en base (D38) : une ligne par verrou nommé, verrouillée par
 * SELECT … FOR UPDATE jusqu'à la fin de la transaction (App\Support\Locking\DatabaseLock).
 * Remplace les verrous de cache, dont l'effet dépend du magasin configuré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('named_locks', function (Blueprint $table) {
            $table->string('name', 64)->primary();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('named_locks');
    }
};
