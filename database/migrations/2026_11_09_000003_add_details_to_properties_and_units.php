<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // mixed : plusieurs logements. Sinon, le bien est lui-même un seul logement de ce type.
        Schema::table('properties', function (Blueprint $table) {
            $table->string('kind', 20)->default('mixed')->after('name');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->string('floor', 30)->nullable()->after('rent');
            $table->decimal('surface', 8, 2)->nullable()->after('floor');
            $table->unsignedTinyInteger('bedrooms')->nullable()->after('surface');
            $table->unsignedTinyInteger('living_rooms')->nullable()->after('bedrooms');
            $table->unsignedTinyInteger('kitchens')->nullable()->after('living_rooms');
            $table->unsignedTinyInteger('bathrooms')->nullable()->after('kitchens');
            $table->boolean('furnished')->default(false)->after('bathrooms');
            $table->json('amenities')->nullable()->after('furnished');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn(['floor', 'surface', 'bedrooms', 'living_rooms', 'kitchens', 'bathrooms', 'furnished', 'amenities']);
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
