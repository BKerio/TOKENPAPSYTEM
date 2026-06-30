<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\Model;

class LandlordTenant extends Model
{
    use DocumentModel;

    protected $connection = 'mongodb';
    protected $collection = 'landlord_tenants';

    protected $fillable = [
        'landlord_id',
        'property_id',
        'name',
        'phone',
        'email',
        'node_type',
        'node_id',
        'status',
    ];
}
