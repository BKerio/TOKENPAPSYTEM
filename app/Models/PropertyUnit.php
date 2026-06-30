<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\Model;

class PropertyUnit extends Model
{
    use DocumentModel;

    protected $connection = 'mongodb';
    protected $collection = 'property_units';

    protected $fillable = [
        'landlord_id',
        'property_id',
        'parent_type',
        'parent_id',
        'name',
        'unit_number',
        'meter_id',
        'status',
    ];

    public function meter()
    {
        return $this->belongsTo(Meter::class, 'meter_id', 'id');
    }
}
