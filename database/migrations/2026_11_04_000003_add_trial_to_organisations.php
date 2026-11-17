<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->date('trial_ends_at')->nullable();
        });

        // Les comptes déjà créés reçoivent l'essai à partir d'aujourd'hui : personne n'est bloqué par surprise.
        DB::table('organisations')->whereNull('subscribed_until')
            ->update(['trial_ends_at' => now()->addDays((int) config('subscription.trial_days'))->toDateString()]);
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn('trial_ends_at');
        });
    }
};
