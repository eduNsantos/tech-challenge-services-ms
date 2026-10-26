<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceOrderService extends Model
{
    protected $table = 'service_order_services';

    protected $fillable = [
        'service_order_id',
        'service_id',
        'quantity',
        'price',
        'started_at',
        'finished_at',
        'started_user_id',
        'finished_user_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'float',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
