<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleOperation extends Model
{
    public $timestamps = false;

    protected $fillable = ['actor_id', 'operation_key', 'action', 'request_hash', 'result', 'occurred_at'];

    protected $hidden = ['operation_key', 'request_hash', 'result'];

    protected function casts(): array
    {
        return ['result' => 'array', 'occurred_at' => 'immutable_datetime'];
    }
}
