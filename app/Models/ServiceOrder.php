<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceOrder extends Model
{
    protected $fillable = [
        'customer_id',
        'vehicle_id',
        'status',
        'services_total',
        'parts_total',
        'total_budget',
        'creation_date',
    ];

    protected $casts = [
        'services_total' => 'float',
        'parts_total' => 'float',
        'total_budget' => 'float',
        'creation_date' => 'datetime',
    ];
}
