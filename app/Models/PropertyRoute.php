<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\Model;

class PropertyRoute extends Model
{
    use DocumentModel;

    protected $connection = 'mongodb';
    protected $collection = 'property_routes';

    protected $fillable = ['landlord_id', 'property_id', 'zone_id', 'name', 'status'];

    public function zone()
    {
        return $this->belongsTo(PropertyZone::class, 'zone_id', 'id');
    }

    public function streets()
    {
        return $this->hasMany(PropertyStreet::class, 'route_id', 'id');
    }
}
