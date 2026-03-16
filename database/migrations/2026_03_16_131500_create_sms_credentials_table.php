<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // In MongoDB, we mostly use migrations for indexes or creating collections explicitly.
        // Assuming we stay consistent with the project's MongoDB architecture.
        Schema::connection('mongodb')->create('sms_credentials', function (Blueprint $table) {
            // MongoDB doesn't use traditional SQL schema, but we can define indexes here if needed.
            // For now, we just create the collection structure reference.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('sms_credentials');
    }
};
