<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class MpesaConfig extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'mpesa_configs';

    protected $fillable = [
        'vendor_id',
        'consumer_key',
        'consumer_secret',
        'passkey',
        'shortcode',
        'till_no',
        'env',
        'callback_url',
        'transaction_type',
    ];

    /**
     * Get the vendor that owns the config.
     */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
