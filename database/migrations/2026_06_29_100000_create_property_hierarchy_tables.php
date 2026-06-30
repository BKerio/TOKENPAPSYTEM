<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_zones', function (Blueprint $table) {
            $table->id();
            $table->string('landlord_id')->index();
            $table->string('property_id')->index();
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('property_routes', function (Blueprint $table) {
            $table->id();
            $table->string('landlord_id')->index();
            $table->string('property_id')->index();
            $table->string('zone_id')->index();
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('property_streets', function (Blueprint $table) {
            $table->id();
            $table->string('landlord_id')->index();
            $table->string('property_id')->index();
            $table->string('route_id')->index();
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('property_units', function (Blueprint $table) {
            $table->id();
            $table->string('landlord_id')->index();
            $table->string('property_id')->index();
            $table->string('parent_type'); // zone | route | street
            $table->string('parent_id')->index();
            $table->string('name');
            $table->string('unit_number')->nullable();
            $table->string('meter_id')->nullable()->index();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('landlord_tenants', function (Blueprint $table) {
            $table->id();
            $table->string('landlord_id')->index();
            $table->string('property_id')->index();
            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('node_type'); // unit | zone | route | street
            $table->string('node_id')->index();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landlord_tenants');
        Schema::dropIfExists('property_units');
        Schema::dropIfExists('property_streets');
        Schema::dropIfExists('property_routes');
        Schema::dropIfExists('property_zones');
    }
};
