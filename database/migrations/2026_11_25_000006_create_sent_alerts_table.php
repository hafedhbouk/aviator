<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Alertes déjà envoyées (B-07, B-13, lot L7) : une alerte par événement, objet et
 * échéance, quel que soit le nombre d'exécutions de la tâche quotidienne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('event', 48);
            $table->morphs('subject');
            $table->date('due_on');
            $table->unsignedSmallInteger('recipients_count');
            $table->dateTime('sent_at');

            $table->unique(['event', 'subject_type', 'subject_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_alerts');
    }
};
