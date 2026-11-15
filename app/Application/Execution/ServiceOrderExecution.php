<?php

namespace App\Application\Execution;

use App\Domain\ServiceOrder\ServiceOrderStatus;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderService;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceOrderExecution
{
    public function startService(string $orderId, string $serviceId, string $userId): ServiceOrder
    {
        return DB::transaction(function () use ($orderId, $serviceId, $userId) {
            $order = $this->lockOrder($orderId);
            $service = $this->findService($order, $serviceId);

            if ($service->started_at !== null) {
                throw new DomainException('The service was already started.');
            }

            $status = ServiceOrderStatus::from($order->status);

            if ($status !== ServiceOrderStatus::InProgress) {
                throw new DomainException("Cannot start a service while the order is {$status->value}.");
            }

            $firstStart = ! $order->services()->whereNotNull('started_at')->exists();
            $service->update(['started_at' => now(), 'started_user_id' => $userId]);

            if ($firstStart) {
                $this->recordExecutionEvent($order, 'ServiceOrderExecutionStarted');
            }

            return $order->fresh('services');
        });
    }

    public function finishService(string $orderId, string $serviceId, string $userId): ServiceOrder
    {
        return DB::transaction(function () use ($orderId, $serviceId, $userId) {
            $order = $this->lockOrder($orderId);
            $service = $this->findService($order, $serviceId);

            if ($service->started_at === null) {
                throw new DomainException('The service was not started.');
            }

            if ($service->finished_at !== null) {
                throw new DomainException('The service was already finished.');
            }

            $service->update(['finished_at' => now(), 'finished_user_id' => $userId]);

            if (! $order->services()->whereNull('finished_at')->exists()) {
                $this->recordExecutionEvent($order, 'ServiceOrderExecutionFinished');
            }

            return $order->fresh('services');
        });
    }

    private function lockOrder(string $orderId): ServiceOrder
    {
        return ServiceOrder::query()->lockForUpdate()->findOrFail($orderId);
    }

    private function findService(ServiceOrder $order, string $serviceId): ServiceOrderService
    {
        $service = $order->services()->where('id', $serviceId)->first();

        if ($service === null) {
            throw (new ModelNotFoundException())->setModel(ServiceOrderService::class, [$serviceId]);
        }

        return $service;
    }

    private function recordExecutionEvent(ServiceOrder $order, string $type): void
    {
        if ($order->status_sequence === null) {
            throw new DomainException('Service order status has not been synchronized from the application.');
        }

        $eventId = Str::uuid()->toString();

        DB::table('outbox')->insert([
            'event_id' => $eventId,
            'type' => $type,
            'group_id' => $order->id,
            'payload' => json_encode([
                'event_id' => $eventId,
                'type' => $type,
                'version' => 1,
                'occurred_at' => now()->toIso8601String(),
                'service_order_id' => $order->id,
                'status_sequence' => (int) $order->status_sequence,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
