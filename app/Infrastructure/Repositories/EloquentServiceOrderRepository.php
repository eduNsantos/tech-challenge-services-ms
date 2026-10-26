<?php

namespace App\Infrastructure\Repositories;

use App\Domain\ServiceOrder\ServiceOrder;
use App\Domain\ServiceOrder\ServiceOrderContract;
use App\Models\ServiceOrder as ServiceOrderModel;

class EloquentServiceOrderRepository implements ServiceOrderContract
{
    public function save(ServiceOrder $serviceOrder): ServiceOrder
    {
        ServiceOrderModel::query()->create([
            'customer_id' => $serviceOrder->customerId(),
            'vehicle_id' => $serviceOrder->vehicleId(),
            'status' => $serviceOrder->status(),
            'services_total' => $serviceOrder->servicesTotal(),
            'parts_total' => $serviceOrder->partsTotal(),
            'total_budget' => $serviceOrder->totalBudget(),
            'creation_date' => $serviceOrder->creationDate()->format('Y-m-d H:i:s'),
        ]);

        return $serviceOrder;
    }
}
