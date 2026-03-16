<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use MongoDB\Laravel\Eloquent\DocumentModel;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use DocumentModel;

    protected $connection = 'mongodb';
    protected $collection = 'personal_access_tokens';
    protected $primaryKey = '_id';
    protected $keyType = 'string';

    /**
     * Get the tokenable model that the access token belongs to.
     */
    public function tokenable()
    {
        return $this->morphTo();
    }

    /**
     * Find the token instance for the given token.
     *
     * @param  string  $token
     * @return static|null
     */
    public static function findToken($token)
    {
        if (strpos($token, '|') === false) {
            return static::where('token', hash('sha256', $token))->first();
        }

        [$id, $token] = explode('|', $token, 2);

        // Use static::where('_id', $id) instead of find() to be sure for MongoDB
        if ($instance = static::where('_id', $id)->first()) {
            return hash_equals($instance->token, hash('sha256', $token)) ? $instance : null;
        }
        
        return null;
    }
}
