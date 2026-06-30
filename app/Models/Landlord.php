<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\Model;

class Landlord extends Model
{
    use DocumentModel;

    protected $connection = 'mongodb';
    protected $collection = 'landlords';

    protected $fillable = [
        'user_id',
        'full_name',
        'phone',
        'payment_account', // M-Pesa number, bank account, or paybill
        'status',          // active, suspended
    ];

    /**
     * Get the user account associated with this landlord.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function meters()
    {
        return $this->hasMany(Meter::class);
    }
}
