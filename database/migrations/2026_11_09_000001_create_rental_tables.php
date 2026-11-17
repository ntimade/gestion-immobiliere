<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
            $table->unique(['organisation_id', 'name']);
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('number', 50);
            $table->string('type', 20)->default('apartment');
            $table->decimal('rent', 15, 3);
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['property_id', 'number']);
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('gender', 1)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('id_number', 100)->nullable();
            $table->timestamps();
            $table->index(['organisation_id', 'name']);
        });

        // Le bail relie un locataire à un logement : il garde l'historique quand un locataire part.
        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->char('first_period', 7); // AAAA-MM : premier mois facturé
            $table->unsignedTinyInteger('due_day'); // 1 à 31, ramené au dernier jour des mois plus courts
            $table->decimal('rent', 15, 3);
            $table->decimal('deposit_amount', 15, 3)->default(0);
            $table->date('deposit_paid_at')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('deposit_retained', 15, 3)->default(0);
            $table->decimal('deposit_refunded', 15, 3)->default(0);
            $table->string('deposit_note')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['organisation_id', 'end_date']);
            $table->index(['unit_id', 'end_date']);
        });

        // Une facture par bail et par mois. Le montant est figé : changer le loyer ne réécrit pas le passé.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->restrictOnDelete();
            $table->char('period', 7); // AAAA-MM
            $table->date('due_date');
            $table->decimal('amount', 15, 3);
            $table->decimal('paid_amount', 15, 3)->default(0);
            $table->string('notes')->nullable();
            $table->timestamp('whatsapp_opened_at')->nullable();
            $table->timestamp('sms_opened_at')->nullable();
            $table->timestamp('reminder_opened_at')->nullable();
            $table->timestamp('late_notice_opened_at')->nullable();
            $table->timestamps();
            $table->unique(['lease_id', 'period']);
            $table->index(['organisation_id', 'period']);
            $table->index(['organisation_id', 'due_date']);
        });

        // Un paiement appartient au bail : il peut régler plusieurs mois, y compris à l'avance.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 3);
            $table->date('paid_at');
            $table->string('method', 50)->nullable();
            $table->string('reference', 100)->nullable();
            $table->timestamp('whatsapp_opened_at')->nullable();
            $table->timestamp('sms_opened_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organisation_id', 'paid_at']);
        });

        // La part de chaque paiement sur chaque mois, recalculée à chaque changement.
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('leases');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('units');
        Schema::dropIfExists('properties');
    }
};
