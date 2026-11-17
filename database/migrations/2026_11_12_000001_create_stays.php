<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Location à la nuit des logements meublés : un tarif journalier sur le logement, les clients de passage
 * et leurs séjours. Un séjour se compte par tranches de 24 heures, une nuit au minimum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->decimal('daily_rate', 15, 3)->nullable()->after('rent');
        });

        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('gender', 1)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('id_number', 100)->nullable();
            $table->timestamps();
            $table->index(['organisation_id', 'phone']);
        });

        Schema::create('stays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('guest_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('check_in_at');
            $table->unsignedSmallInteger('days');
            $table->dateTime('check_out_at');
            $table->decimal('daily_rate', 15, 3);
            $table->decimal('amount', 15, 3);
            $table->decimal('paid_amount', 15, 3)->default(0);
            $table->string('method', 50)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamp('whatsapp_opened_at')->nullable();
            $table->timestamp('sms_opened_at')->nullable();
            $table->timestamps();
            $table->index(['organisation_id', 'check_in_at']);
            $table->index(['unit_id', 'check_in_at', 'check_out_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stays');
        Schema::dropIfExists('guests');
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('daily_rate');
        });
    }
};
