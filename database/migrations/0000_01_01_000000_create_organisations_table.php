<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('phone', 50)->nullable();
            $table->string('phone_country', 2)->nullable();
            $table->string('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->char('currency', 3);
            // Nombre de jours avant l'échéance où le locataire apparaît dans la liste des rappels.
            $table->unsignedTinyInteger('reminder_days')->default(3);
            // Dernier jour où les factures de loyer ont été générées pour ce compte.
            $table->date('invoices_synced_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisations');
    }
};
