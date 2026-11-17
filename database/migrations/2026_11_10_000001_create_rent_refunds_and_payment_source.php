<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Les loyers payés pour des mois après la sortie, rendus au locataire. Le paiement d'origine reste intact.
        Schema::create('rent_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('lease_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 3);
            $table->date('refunded_on');
            $table->string('note')->nullable();
            // Une sortie annulée annule son remboursement sans l'effacer.
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        // « deposit » : une part de la caution retenue, affectée aux loyers impayés à la sortie.
        Schema::table('payments', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('source');
        });
        Schema::dropIfExists('rent_refunds');
    }
};
