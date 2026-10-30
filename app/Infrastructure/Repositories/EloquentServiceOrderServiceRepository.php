<?php

namespace App\Infrastructure\Repositories;

use App\Domain\ServiceOrder\ServiceOrderService;
use App\Domain\ServiceOrder\ServiceOrderServiceContract;
use App\Models\ServiceOrderService as ServiceOrderServiceModel;
use Illuminate\Support\Str;

class EloquentServiceOrderServiceRepository implements ServiceOrderServiceContract
{
    public function save(ServiceOrderService $serviceOrderService): ServiceOrderService
    {
        ServiceOrderServiceModel::query()->create([
            'id' => Str::uuid()->toString(),
            'service_order_id' => $serviceOrderService->serviceOrderId(),
            'service_id' => $serviceOrderService->serviceId(),
            'quantity' => $serviceOrderService->quantity(),
            'price' => $serviceOrderService->price(),
            'started_at' => $serviceOrderService->startedAt()?->format('Y-m-d H:i:s'),
            'finished_at' => $serviceOrderService->finishedAt()?->format('Y-m-d H:i:s'),
            'started_user_id' => $serviceOrderService->startedUserId(),
            'finished_user_id' => $serviceOrderService->finishedUserId(),
        ]);

        return $serviceOrderService;
    }
}
