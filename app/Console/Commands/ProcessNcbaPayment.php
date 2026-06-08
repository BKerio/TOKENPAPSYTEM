<?php

namespace App\Console\Commands;

use App\Services\NcbaPaymentService;
use Illuminate\Console\Command;

class ProcessNcbaPayment extends Command
{
    protected $signature = 'ncba:process-payment
                            {trans_id : M-Pesa/NCBA transaction reference}
                            {amount : Payment amount in KES}
                            {phone : Purchaser phone e.g. 2547XXXXXXXX}
                            {till : Vendor till/account id}
                            {meter : Meter number (DRN)}
                            {--name=Customer : Customer name for records}';

    protected $description = 'Manually process an NCBA paybill payment (token + SMS) when the webhook was not received.';

    public function handle(NcbaPaymentService $ncbaPaymentService): int
    {
        $transID = $this->argument('trans_id');

        if ($ncbaPaymentService->isDuplicate($transID)) {
            $this->error("Transaction {$transID} already exists.");
            return Command::FAILURE;
        }

        $this->info('Processing NCBA payment...');
        $this->line('TransID: ' . $transID);
        $this->line('Account: ' . $this->argument('till') . '#' . $this->argument('meter'));
        $this->line('Phone:   ' . $this->argument('phone'));
        $this->line('Amount:  KES ' . $this->argument('amount'));

        $result = $ncbaPaymentService->processPayment([
            'TransID'         => $transID,
            'TransAmount'     => $this->argument('amount'),
            'BillRefNumber'   => $this->argument('till'),
            'Narrative'       => $this->argument('meter'),
            'Mobile'          => $this->argument('phone'),
            'name'            => $this->option('name'),
        ]);

        if (!$result['meter_found']) {
            $this->error('Meter not found. Check till/meter values.');
            return Command::FAILURE;
        }

        if ($result['token_generated']) {
            $this->info('Token generated successfully.');
            foreach ($result['tokens'] ?? [] as $i => $token) {
                $this->line('Token ' . ($i + 1) . ': ' . trim(chunk_split($token, 4, ' ')));
            }
        } else {
            $this->error('Token generation failed: ' . ($result['error'] ?? 'unknown error'));
        }

        $this->info($result['sms_sent'] ? 'SMS sent to purchaser.' : 'SMS failed to send — check SMS logs.');

        return ($result['token_generated'] && $result['sms_sent']) ? Command::SUCCESS : Command::FAILURE;
    }
}
