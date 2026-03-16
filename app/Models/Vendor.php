<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\Model;
use App\Models\MpesaConfig;
use App\Models\SmsConfig;

class Vendor extends Model
{
    use DocumentModel;

    protected $connection = 'mongodb';
    protected $collection = 'vendors';

    protected $fillable = [
        'user_id',
        'business_name',
        'address',
        'account_id',
        'paybill',
        'vendor_type', // e.g., Individual, Company
        'bank_name',   // e.g., Equity, NCBA, KCB
        'status',      // e.g., active, suspended
        'sms_config',
        'mpesa_config',
        'logo_url',    // URL or path to vendor logo
        'dashboard_settings', // e.g. primary_color, tagline, show_logo_in_sidebar
    ];

    protected $casts = [
        'sms_config' => 'array',
        'mpesa_config' => 'array',
        'dashboard_settings' => 'array',
    ];

    /**
     * Get the MPESA configuration for this vendor.
     */
    public function mpesaConfig()
    {
        return $this->hasOne(MpesaConfig::class);
    }

    /**
     * Get the SMS configuration for this vendor.
     */
    public function smsConfig()
    {
        return $this->hasOne(SmsConfig::class);
    }

    /**
     * Get the SMS credentials for this vendor.
     */
    public function smsCredentials()
    {
        return $this->hasMany(SmsCredential::class);
    }

    /**
     * Get the user that owns the vendor.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the meters assigned to this vendor.
     */
    public function meters()
    {
        return $this->hasMany(Meter::class);
    }
}
