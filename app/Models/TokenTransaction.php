<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model as DocumentModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TokenTransaction extends DocumentModel
{
    use HasFactory;

    protected $connection = 'mongodb';
    protected $collection = 'token_transactions';

    protected $fillable = [
        'meter_id',
        'vendor_id',
        'customer_id', // Optional, if linked to a specific customer's purchase
        'payment_id',  // Links directly to the Payment record (checkout_request_id owner)
        'amount',
        'tokens', // Array of generated token strings
        'prism_message_id', // Useful for debugging or matching requests with Prism
        'status', // e.g., 'success', 'failed'
        'description', // E.g., '100 kWh Credit' or Error details
    ];

    public function meter()
    {
        return $this->belongsTo(Meter::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
