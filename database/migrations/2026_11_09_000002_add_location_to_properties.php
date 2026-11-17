<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('city', 100)->nullable()->after('name');
            $table->string('neighbourhood', 100)->nullable()->after('city');
            $table->decimal('latitude', 10, 7)->nullable()->after('description');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['city', 'neighbourhood', 'latitude', 'longitude']);
        });
    }
};
