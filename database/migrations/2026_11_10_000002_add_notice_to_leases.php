<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le préavis fixe la date de départ : la facturation s'arrête et le logement se libère à cette date.
        // La sortie (caution, état des lieux) se finalise ensuite, comme avant.
        Schema::table('leases', function (Blueprint $table) {
            $table->date('notice_given_on')->nullable();
            $table->date('notice_end_date')->nullable();
            $table->timestamp('notice_opened_at')->nullable();
            $table->dateTime('inspection_at')->nullable();
            $table->text('inspection_report')->nullable();
            $table->decimal('inspection_cost', 15, 3)->nullable();
            $table->timestamp('inspection_opened_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn(['notice_given_on', 'notice_end_date', 'notice_opened_at', 'inspection_at', 'inspection_report', 'inspection_cost', 'inspection_opened_at']);
        });
    }
};
