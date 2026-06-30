<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\Model;

class PropertyStreet extends Model
{
    use DocumentModel;

    protected $connection = 'mongodb';
    protected $collection = 'property_streets';

    protected $fillable = ['landlord_id', 'property_id', 'route_id', 'name', 'status'];

    public function route()
    {
        return $this->belongsTo(PropertyRoute::class, 'route_id', 'id');
    }
}
