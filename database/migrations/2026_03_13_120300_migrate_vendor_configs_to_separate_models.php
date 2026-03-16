<?php

namespace Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use App\Models\Vendor;
use App\Models\MpesaConfig;
use App\Models\SmsConfig;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $vendors = Vendor::all();

        foreach ($vendors as $vendor) {
            // Migrate Mpesa Config
            if (!empty($vendor->mpesa_config)) {
                MpesaConfig::updateOrCreate(
                    ['vendor_id' => $vendor->id],
                    array_merge($vendor->mpesa_config, ['vendor_id' => $vendor->id])
                );
            }

            // Migrate Sms Config
            if (!empty($vendor->sms_config)) {
                SmsConfig::updateOrCreate(
                    ['vendor_id' => $vendor->id],
                    array_merge($vendor->sms_config, ['vendor_id' => $vendor->id])
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        MpesaConfig::truncate();
        SmsConfig::truncate();
    }
};
