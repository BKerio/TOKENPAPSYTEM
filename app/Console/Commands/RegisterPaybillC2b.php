<?php

namespace App\Console\Commands;

use App\Models\MpesaConfig;
use App\Models\Vendor;
use App\Services\MpesaService;
use Illuminate\Console\Command;

class RegisterPaybillC2b extends Command
{
    protected $signature = 'paybill:register-c2b
                            {--shortcode= : Paybill shortcode e.g. 880100}
                            {--vendor= : Vendor business name to use Daraja credentials}';

    protected $description = 'Register Safaricom C2B URLs so paybill payments auto-trigger token + SMS';

    public function handle(MpesaService $mpesaService): int
    {
        $shortCode = $this->option('shortcode')
            ?: config('services.ncba.paybill_shortcode', '880100');

        $baseUrl = rtrim(config('app.url'), '/');
        $confirmationUrl = $baseUrl . '/api/mpesa/c2b/confirmation';
        $validationUrl = $baseUrl . '/api/mpesa/c2b/validation';

        $this->info("Registering C2B URLs for paybill {$shortCode}");
        $this->line("Validation:   {$validationUrl}");
        $this->line("Confirmation: {$confirmationUrl}");

        $credentials = null;
        if ($vendorName = $this->option('vendor')) {
            $vendor = Vendor::where('business_name', 'like', '%' . $vendorName . '%')->first();
            if ($vendor?->mpesaConfig) {
                $credentials = $vendor->mpesaConfig->toArray();
                $this->info('Using credentials from vendor: ' . $vendor->business_name);
            }
        }

        $response = $mpesaService->registerC2bUrls(
            $shortCode,
            $confirmationUrl,
            $validationUrl,
            $credentials
        );

        $this->line(json_encode($response, JSON_PRETTY_PRINT));

        if (isset($response['ResponseDescription']) && stripos($response['ResponseDescription'], 'success') !== false) {
            $this->info('C2B URLs registered successfully.');
            return Command::SUCCESS;
        }

        $this->error('C2B registration failed. Millicom/NCBA must register these URLs on paybill ' . $shortCode . ' if you do not own the Daraja app.');
        return Command::FAILURE;
    }
}
