<?php

namespace App\Application\ServiceOrder;

use App\Application\Telemetry\Metrics;
use App\Application\Telemetry\ServiceOrderJourney;
use App\Domain\ServiceOrder\ServiceOrderStatus;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProcessServiceOrderStatusChanged
{
    public const TYPE = 'ServiceOrderStatusChanged';

    public function __construct(
        private readonly Metrics $metrics,
        private readonly ServiceOrderJourney $journey,
    ) {}

    public function handle(array $payload): bool
    {
        if (($payload['type'] ?? null) !== self::TYPE
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

        $previousStatus = null;

        try {
            $changed = DB::transaction(function () use ($payload, &$previousStatus): bool {
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

                $previousStatus = $order->status;
                $order->forceFill([
                    'status' => $payload['status'],
                    'status_sequence' => $payload['sequence'],
                ])->save();

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        if ($changed && $previousStatus !== $payload['status']) {
            $this->metrics->statusChanged($previousStatus, $payload['status'], self::TYPE);
        }

        if ($changed) {
            $this->journey->record('status_synchronized', $payload['service_order_id'], [
                'previous_status' => $previousStatus,
                'status' => $payload['status'],
                'sequence' => $payload['sequence'],
                'event_id' => $payload['event_id'],
            ]);
        }

        return $changed;
    }
}
