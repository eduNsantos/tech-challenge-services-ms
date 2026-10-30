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
    public function changeStatus(string $orderId, ServiceOrderStatus $next): ServiceOrder
    {
        return DB::transaction(function () use ($orderId, $next) {
            $order = $this->lockOrder($orderId);
            $this->transition($order, $next);

            return $order->fresh('services');
        });
    }

    public function startService(string $orderId, string $serviceId, string $userId): ServiceOrder
    {
        return DB::transaction(function () use ($orderId, $serviceId, $userId) {
            $order = $this->lockOrder($orderId);
            $service = $this->findService($order, $serviceId);

            if ($service->started_at !== null) {
                throw new DomainException('The service was already started.');
            }

            $status = ServiceOrderStatus::from($order->status);

            if ($status === ServiceOrderStatus::AwaitingApproval) {
                $this->transition($order, ServiceOrderStatus::InProgress);
            } elseif ($status !== ServiceOrderStatus::InProgress) {
                throw new DomainException("Cannot start a service while the order is {$status->value}.");
            }

            $service->update(['started_at' => now(), 'started_user_id' => $userId]);

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
                $this->transition($order, ServiceOrderStatus::Finished);
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

    // Grava a mudança de status e o evento de saída na mesma transação (outbox).
    private function transition(ServiceOrder $order, ServiceOrderStatus $next): void
    {
        $previous = ServiceOrderStatus::from($order->status);

        if (! $previous->canTransitionTo($next)) {
            throw new DomainException("Cannot change status from {$previous->value} to {$next->value}.");
        }

        $order->update(['status' => $next->value]);

        $services = $order->services()->get();
        $eventId = Str::uuid()->toString();

        DB::table('outbox')->insert([
            'event_id' => $eventId,
            'type' => 'ServiceOrderStatusChanged',
            'group_id' => $order->id,
            'payload' => json_encode([
                'event_id' => $eventId,
                'type' => 'ServiceOrderStatusChanged',
                'version' => 1,
                'occurred_at' => now()->toIso8601String(),
                'service_order_id' => $order->id,
                'status' => $next->value,
                'previous_status' => $previous->value,
                'started_at' => $services->min('started_at')?->toIso8601String(),
                'finished_at' => $next === ServiceOrderStatus::Finished
                    ? $services->max('finished_at')?->toIso8601String()
                    : null,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
