<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\Model;

class PropertyZone extends Model
{
    use DocumentModel;

    protected $connection = 'mongodb';
    protected $collection = 'property_zones';

    protected $fillable = ['landlord_id', 'property_id', 'name', 'status'];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function routes()
    {
        return $this->hasMany(PropertyRoute::class, 'zone_id', 'id');
    }
}
