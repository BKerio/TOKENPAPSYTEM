<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PrismTokenService;

class TestPrismToken extends Command
{
    protected $signature = 'prism:test-token 
                            {meter=47500162848} 
                            {amount=10}';

    protected $description = 'Test the integration of the Prism API by requesting a token.';

    public function handle(PrismTokenService $prismService)
    {
        $meterNumber = $this->argument('meter');
        $amount = (float) $this->argument('amount');

        $this->info("Starting Prism API Integration Test...");
        $this->info("Meter:   $meterNumber");
        $this->info("Amount:  $amount");

        try {

            $this->info("\n1. Connecting to Prism Network over TLS...");
            $prismService->connect();
            $this->info("   -> Connected Successfully.");

            $this->info("\n2. Authenticating...");
            $prismService->authenticate();
            $this->info("   -> Authenticated Successfully.");

            $this->info("\n3. Generating Credit Token...");

            $dummyMeter = new \App\Models\Meter([
                'meter_number' => $meterNumber,
                'sgc' => 600909,
                'krn' => 1,
                'ti' => 1,
                'ea' => 7,
                'ken' => 255
            ]);

            $tokens = $prismService->issueCreditToken($dummyMeter, $amount);

            $this->info("\nSUCCESS: Tokens Generated");
            $this->line("-------------------------------------------------");

            foreach ($tokens as $index => $token) {
                $digits = $token->tokenDec ?? $token->tokenHex ?? 'UNKNOWN';
                $formatted = chunk_split($digits, 4, ' ');

                $this->info("STS Token" . ($index + 1) . ": " . trim($formatted));
                // $this->line("   Class: {$token->tokenClass}, Subclass: {$token->subclass}");
            }

            $this->line("-------------------------------------------------");

        } catch (\Exception $e) {

            $this->error("FAILED: " . $e->getMessage());

            if ($e instanceof \Prism\PrismToken1\ApiException) {
                $this->error("API Error Details:");
                $this->line("   Code: {$e->eCode}");
                $this->line("   Message: {$e->eMsgEn}");
            }

        } finally {

            $prismService->disconnect();
            $this->info("\nDisconnected from Prism.");
        }

        return Command::SUCCESS;
    }
}