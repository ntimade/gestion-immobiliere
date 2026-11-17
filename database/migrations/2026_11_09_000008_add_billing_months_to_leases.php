<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le nombre de mois facturés à chaque échéance, propre à chaque client. 1 : au mois.
        Schema::table('leases', function (Blueprint $table) {
            $table->unsignedTinyInteger('billing_months')->default(1)->after('due_day');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('billing_months');
        });
    }
};
