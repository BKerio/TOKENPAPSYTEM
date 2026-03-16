<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class SmsCredential extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'sms_credentials';

    protected $fillable = [
        'vendor_id',      // Link to vendor (null for system-wide)
        'provider',       // e.g., 'TiaraConnect', 'AfricasTalking'
        'api_key',
        'api_secret',     // Optional, for some providers
        'partner_id',     // For Tiara/etc
        'shortcode',      // Sender ID / Shortcode
        'endpoint_url',   // Custom API endpoint if needed
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the vendor that owns the credentials.
     */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
