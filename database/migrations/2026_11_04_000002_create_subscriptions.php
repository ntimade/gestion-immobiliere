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
            $table->date('subscribed_until')->nullable();
        });

        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('months')->unique();
            $table->decimal('price', 12, 2)->nullable();
            $table->string('currency', 3)->default('XAF');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        // Les quatre durées existent dès le départ : le super admin fixe les prix et les active.
        foreach ([1, 3, 6, 12] as $months) {
            DB::table('subscription_plans')->insert(['months' => $months, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('months');
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('method', 20);
            $table->string('phone', 50)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('support_admins')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['method', 'reference']);
        });

        // Numéros de réception des paiements, réglés par le super admin.
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscription_plans');

        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn('subscribed_until');
        });
    }
};
