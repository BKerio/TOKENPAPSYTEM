<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Payment extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'payments';

    protected $fillable = [
        'merchant_request_id',
        'checkout_request_id',
        'account_reference',
        'phone',
        'amount',
        'mpesa_receipt_number',
        'result_code',
        'result_desc',
        'status',
    ];
}
