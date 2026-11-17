<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le nombre de mois proposé à chaque entrée, négociable par client. 0 : pas de caution par défaut.
        Schema::table('organisations', function (Blueprint $table) {
            $table->unsignedTinyInteger('deposit_months')->default(2)->after('reminder_days');
        });

        // Null : bail enregistré avant ce réglage, seul son montant est connu.
        Schema::table('leases', function (Blueprint $table) {
            $table->unsignedTinyInteger('deposit_months')->nullable()->after('deposit_amount');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('deposit_months');
        });

        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn('deposit_months');
        });
    }
};
