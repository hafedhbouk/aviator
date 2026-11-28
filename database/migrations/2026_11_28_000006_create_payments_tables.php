<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Encaissements (phase 6, lot L7, E-06, E17, Q81, D37, D57), docs/modele_donnees.md §6 :
 *
 * - `payments` : mode, référence, banque (chèque, virement : E17), montant en dinars
 *   (D26), date de réception ; acompte de réservation (`reservation_id`, D37) ; caisse
 *   (`cash_session_id`, clé étrangère posée avec le journal de caisse, L8) ; jamais
 *   supprimé, annulé avec motif (RG-11) ;
 * - `payment_allocations` : imputation n ↔ n sur les factures, ajout seul ; Σ imputations
 *   ≤ montant encaissé et ≤ reste dû de chaque facture, sous verrou. La part non imputée
 *   est un acompte du client, imputable plus tard. Les imputations d'un encaissement
 *   annulé ne comptent plus ;
 * - cumuls tenus sur les lignes verrouillées : `invoices.paid_amount` (encaissements et
 *   avoirs imputés), `invoices.settled_amount` (avoir remboursé ou imputé),
 *   `payments.allocated_amount`. Sous verrou, la lecture d'une ligne verrouillée rend
 *   toujours sa dernière version validée, alors que la somme des imputations lue dans
 *   l'instantané de la transaction peut ignorer celles d'un processus concurrent qui
 *   vient de valider : le contrôle du plafond se fait donc sur ces cumuls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('paid_amount', 15, 3)->default(0)->after('total_due_amount');
            $table->decimal('settled_amount', 15, 3)->default(0)->after('paid_amount');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('reference', 64)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->decimal('amount', 15, 3);
            $table->decimal('allocated_amount', 15, 3)->default(0);
            $table->char('currency', 3)->default('TND');
            $table->dateTime('received_at')->index();
            $table->unsignedBigInteger('cash_session_id')->nullable()->index();
            $table->string('status', 16)->index();
            $table->string('cancellation_reason', 500)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 3);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['invoice_id', 'payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['paid_amount', 'settled_amount']);
        });
    }
};
