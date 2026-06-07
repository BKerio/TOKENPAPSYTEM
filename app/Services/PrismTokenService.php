<?php

namespace App\Services;

use Thrift\Transport\TSocket;
use Thrift\Transport\TFramedTransport;
use Thrift\Protocol\TBinaryProtocol;
use Prism\PrismToken1\TokenApiClient;
use Prism\PrismToken1\SessionOptions;
use Illuminate\Support\Facades\Log;
use Exception;

class PrismTokenService
{
    private $client = null;
    private $transport = null;
    private $accessToken = null;

    /**
     * Connect to the Prism Vending system using Thrift over TLS
     */
    public function connect()
    {
        // Replace with environment variables eventually if needed
        $host = env('PRISM_HOST', "pt.prismcrypto.co.za:29531");
        $port = env('PRISM_PORT', 29543);

        Log::info("Connecting to Prism API at {$host}:{$port}");
        
        $context = stream_context_create([
            'ssl' => [
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);
        
        $TLSPREFIX = "tlsv1.2://"; 
        
        try {
            // Using error suppression (@) briefly, stream_socket_client will push connection errors into $errstr
            $socket = @stream_socket_client($TLSPREFIX . $host . ":" . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
            if ($socket === FALSE) {
                throw new Exception("Socket Error: $errstr ($errno)");
            }

            $trans = new TSocket($TLSPREFIX . $host, $port);
            $trans->setHandle($socket);
            $trans->setSendTimeout(3000); // Send timeout in ms
            $trans->setRecvTimeout(15000); // Large recv timeout for auth

            $this->transport = new TFramedTransport($trans);
            $proto = new TBinaryProtocol($this->transport);
            $this->client = new TokenApiClient($proto);

            return true;
        } catch (\Exception $e) {
            Log::error("Prism API Connection Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Generates a Message ID as needed by the API
     */
    protected function generateMessageId()
    {
        return sprintf('%10d-%s', time(), bin2hex(random_bytes(8)));
    }

    /**
     * Authenticate and get Access Token
     */
    public function authenticate($username = null, $password = null)
    {
        if (!$this->client) {
            $this->connect();
        }

        $user = $username ?? env('PRISM_USERNAME', 'millicom');
        $pass = $password ?? env('PRISM_PASSWORD', 'RGmWeoEB39dT3JYu');

        try {
            $signInResp = $this->client->signInWithPassword(
                $this->generateMessageId(),
                "local", 
                $user, 
                $pass, 
                new SessionOptions()
            );

            $this->accessToken = $signInResp->accessToken;
            return $this->accessToken;
        } catch (\Exception $e) {
            Log::error("Prism Authentication Failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Issue a credit token for a specific meter
     */
    public function issueCreditToken(\App\Models\Meter $meter, $amount, $currencySubclass = 0)
    {
        if (!$this->accessToken) {
            $this->authenticate();
        }

        $drn = $meter->meter_number;

        // Fetch vending parameters from database with fallbacks from meter or hardcoded defaults
        $sgc = \App\Models\VendingSetting::getValue('vending_sgc', (int) ($meter->sgc ?? 600909));
        $krn = \App\Models\VendingSetting::getValue('vending_krn', (int) ($meter->krn ?? 1));
        $ti  = \App\Models\VendingSetting::getValue('vending_ti', (int) ($meter->ti ?? 1));
        $ea  = \App\Models\VendingSetting::getValue('vending_ea', (int) ($meter->ea ?? 7));
        $tct = \App\Models\VendingSetting::getValue('vending_tct', 1);
        $ken = \App\Models\VendingSetting::getValue('vending_ken', (int) ($meter->ken ?? 255));

        $meterConfig = new \Prism\PrismToken1\MeterConfigIn([
            'drn' => $drn,
            'sgc' => (int) $sgc,
            'krn' => (int) $krn,
            'ti'  => (int) $ti,
            'ea'  => (int) $ea,
            'tct' => (int) $tct,
            'ken' => (int) $ken,
            'allowKrnUpdate' => false
        ]);

        try {
            $tokens = $this->client->issueCreditToken(
                $this->generateMessageId(),
                $this->accessToken,
                $meterConfig,
                $currencySubclass, // subclass
                $amount,           // transferAmount
                0,                 // tokenTime: now
                0                  // flags
            );
            
            return $tokens; // Returns an array of Token objects
        } catch (\Exception $e) {
            Log::error("Prism Token Generation Failed for DRN {$drn}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Ensure the transport is closed
     */
    public function disconnect()
    {
        if ($this->transport) {
            try {
                if ($this->transport->isOpen()) {
                    $this->transport->close();
                }
            } catch (\Exception $e) {
                // Ignore close errors
            }
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
