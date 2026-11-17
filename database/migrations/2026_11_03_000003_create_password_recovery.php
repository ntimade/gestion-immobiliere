<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });

        Schema::create('security_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('question', 50);
            $table->string('answer');
            $table->timestamps();

            $table->unique(['user_id', 'question']);
        });

        // Le poste de support est hors de toute organisation : il a ses propres comptes.
        Schema::create('support_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_support_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('organisation_code', 50);
            $table->string('login');
            $table->string('contact');
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('handled_by')->nullable()->constrained('support_admins')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_support_requests');
        Schema::dropIfExists('support_admins');
        Schema::dropIfExists('security_answers');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
