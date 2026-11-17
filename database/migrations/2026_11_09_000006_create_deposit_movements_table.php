<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chaque mouvement reste écrit : rien n'est modifié ni effacé, une correction ajoute une ligne.
        Schema::create('deposit_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('lease_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 15, 3);
            $table->date('happened_on');
            $table->string('note')->nullable();
            $table->timestamps();
        });

        // Les baux existants repartent de ce qui est connu : les corrections passées ne sont plus retrouvables.
        DB::table('leases')->orderBy('id')->each(function ($lease) {
            foreach ([
                ['paid', (float) $lease->deposit_amount, $lease->deposit_paid_at ?? $lease->start_date, null],
                ['retained', -(float) $lease->deposit_retained, $lease->end_date, $lease->deposit_note],
                ['refunded', -(float) $lease->deposit_refunded, $lease->end_date, null],
            ] as [$type, $amount, $date, $note]) {
                if ($amount != 0 && $date) {
                    DB::table('deposit_movements')->insert([
                        'organisation_id' => $lease->organisation_id,
                        'lease_id' => $lease->id,
                        'type' => $type,
                        'amount' => $amount,
                        'happened_on' => substr((string) $date, 0, 10),
                        'note' => $note,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_movements');
    }
};
