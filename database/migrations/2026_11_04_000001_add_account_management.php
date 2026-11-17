<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 500)->nullable();
        });

        // Trace de ce que le support fait sur un compte.
        Schema::create('support_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_admin_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 30);
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_actions');

        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });
    }
};
