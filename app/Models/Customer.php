<?php

namespace App\Models;
 
use MongoDB\Laravel\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;

class Customer extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable;

    protected $connection = 'mongodb';
    protected $table = 'customers';
    protected $primaryKey = '_id';
    protected $keyType = 'string';

    protected $fillable = [
        'vendor_id',
        'meter_id',
        'name',
        'phone',
        'email',
        'address',
        'county_id',
        'constituency_id',
        'ward_id',
        'status',
        'role',
    ];

    protected $attributes = [
        'role' => 'customer',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function meter()
    {
        return $this->belongsTo(Meter::class);
    }
}
