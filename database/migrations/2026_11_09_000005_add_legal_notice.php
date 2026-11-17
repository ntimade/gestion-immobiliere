<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sans délai réglé par le bailleur, aucune notification n'est proposée.
        Schema::table('organisations', function (Blueprint $table) {
            $table->unsignedSmallInteger('legal_notice_after')->nullable()->after('reminder_days');
            $table->string('legal_notice_unit', 10)->default('months')->after('legal_notice_after');
        });

        Schema::table('leases', function (Blueprint $table) {
            $table->timestamp('legal_notice_opened_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('legal_notice_opened_at');
        });

        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn(['legal_notice_after', 'legal_notice_unit']);
        });
    }
};
