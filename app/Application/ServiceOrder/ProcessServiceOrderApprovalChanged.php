<?php

namespace App\Application\ServiceOrder;

use App\Application\Execution\ServiceOrderExecution;
use App\Domain\ServiceOrder\ServiceOrderStatus;
use App\Models\ServiceOrder;
use DateTimeImmutable;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProcessServiceOrderApprovalChanged
{
    public const TYPE = 'ServiceOrderApprovalChanged';

    public function __construct(private readonly ServiceOrderExecution $execution) {}

    /**
     * @return bool true when the MS status was changed
     */
    public function handle(array $payload): bool
    {
        if (($payload['type'] ?? null) !== self::TYPE || ($payload['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unexpected approval event type or version.');
        }

        $eventId = $this->requireString($payload, 'event_id');
        $orderId = $this->requireString($payload, 'service_order_id');
        $previousStatus = $this->requireString($payload, 'previous_status');
        $occurredAt = $this->requireString($payload, 'occurred_at');
        $status = ServiceOrderStatus::tryFrom($this->requireString($payload, 'status'));

        try {
            new DateTimeImmutable($occurredAt);
        } catch (Exception $e) {
            throw new InvalidArgumentException('Invalid occurred_at value.', previous: $e);
        }

        if ($previousStatus !== ServiceOrderStatus::AwaitingApproval->value
            || $status === null
            || ! in_array($status, [ServiceOrderStatus::InDiagnosis, ServiceOrderStatus::InProgress], true)) {
            throw new InvalidArgumentException('Unsupported approval status transition.');
        }

        try {
            return DB::transaction(function () use ($eventId, $orderId, $previousStatus, $status): bool {
                DB::table('processed_messages')->insert([
                    'event_id' => $eventId,
                    'type' => self::TYPE,
                ]);

                $order = ServiceOrder::query()->lockForUpdate()->find($orderId);

                if ($order === null) {
                    // The creation event can still be in flight; leave this message for SQS redelivery.
                    throw (new ModelNotFoundException)->setModel(ServiceOrder::class, [$orderId]);
                }

                if ($order->status !== $previousStatus) {
                    return false;
                }

                $this->execution->changeStatus($orderId, $status);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function requireString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("The {$key} is required.");
        }

        return $value;
    }
}
