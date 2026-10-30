<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceOrder extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'customer_id',
        'vehicle_id',
        'status',
        'services_total',
        'creation_date',
    ];

    protected $casts = [
        'services_total' => 'float',
        'creation_date' => 'datetime',
    ];

    public function services(): HasMany
    {
        return $this->hasMany(ServiceOrderService::class, 'service_order_id');
    }
}
