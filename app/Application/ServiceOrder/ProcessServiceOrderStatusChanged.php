<?php

namespace App\Application\ServiceOrder;

use App\Domain\ServiceOrder\ServiceOrderStatus;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProcessServiceOrderStatusChanged
{
    public function handle(array $payload): bool
    {
        if (($payload['type'] ?? null) !== 'ServiceOrderStatusChanged'
            || ($payload['version'] ?? null) !== 1
            || ! is_string($payload['event_id'] ?? null)
            || trim($payload['event_id']) === ''
            || ! is_string($payload['service_order_id'] ?? null)
            || trim($payload['service_order_id']) === ''
            || ! is_int($payload['sequence'] ?? null)
            || $payload['sequence'] < 1
            || ! is_string($payload['status'] ?? null)
            || ServiceOrderStatus::tryFrom($payload['status']) === null) {
            throw new InvalidArgumentException('Invalid service order status event.');
        }

        try {
            return DB::transaction(function () use ($payload): bool {
                DB::table('processed_messages')->insert([
                    'event_id' => $payload['event_id'],
                    'type' => $payload['type'],
                ]);

                $order = ServiceOrder::query()->lockForUpdate()->find($payload['service_order_id']);
                if ($order === null) {
                    throw (new ModelNotFoundException)->setModel(ServiceOrder::class, [$payload['service_order_id']]);
                }

                if ($order->status_sequence !== null && $order->status_sequence >= $payload['sequence']) {
                    return false;
                }

                $order->forceFill([
                    'status' => $payload['status'],
                    'status_sequence' => $payload['sequence'],
                ])->save();

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
