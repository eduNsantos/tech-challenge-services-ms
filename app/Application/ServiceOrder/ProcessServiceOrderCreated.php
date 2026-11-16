<?php

namespace App\Application\ServiceOrder;

use App\Application\Telemetry\Metrics;
use App\Application\Telemetry\ServiceOrderJourney;
use App\Domain\ServiceOrder\ServiceOrder;
use App\Domain\ServiceOrder\ServiceOrderContract;
use App\Domain\ServiceOrder\ServiceOrderService;
use App\Domain\ServiceOrder\ServiceOrderServiceContract;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProcessServiceOrderCreated
{
    public const TYPE = 'ServiceOrderCreated';

    public function __construct(
        private readonly ServiceOrderContract $serviceOrderRepository,
        private readonly ServiceOrderServiceContract $serviceOrderServiceRepository,
        private readonly Metrics $metrics,
        private readonly ServiceOrderJourney $journey,
    ) {}

    /**
     * @return bool false when the event was already processed or the order already exists.
     *
     * @throws InvalidArgumentException when the payload is invalid.
     */
    public function handle(array $payload): bool
    {
        $eventId = $this->requireString($payload, 'event_id');
        $sequence = $payload['sequence'] ?? null;

        if ($sequence !== null && (! is_int($sequence) || $sequence < 1)) {
            throw new InvalidArgumentException('Invalid status sequence.');
        }

        if (($payload['type'] ?? null) !== self::TYPE) {
            throw new InvalidArgumentException('Unexpected event type.');
        }

        $order = $payload['service_order'] ?? null;
        $services = $payload['services'] ?? null;

        if (! is_array($order) || ! is_array($services)) {
            throw new InvalidArgumentException('service_order and services are required.');
        }

        $serviceOrder = ServiceOrder::create(
            $this->requireString($order, 'customer_id'),
            $this->requireString($order, 'vehicle_id'),
            (float) ($order['services_total'] ?? 0),
            $order['status'] ?? null,
            isset($payload['occurred_at']) ? new DateTimeImmutable($payload['occurred_at']) : null,
            $this->requireString($order, 'id'),
        );

        $items = array_map(fn ($service) => $this->buildService($serviceOrder->id(), $service), $services);

        try {
            $orderAlreadyExists = false;

            DB::transaction(function () use ($eventId, $serviceOrder, $items, $sequence, &$orderAlreadyExists) {
                // A chave primária de processed_messages garante a idempotência.
                DB::table('processed_messages')->insert([
                    'event_id' => $eventId,
                    'type' => self::TYPE,
                ]);

                if (DB::table('service_orders')->where('id', $serviceOrder->id())->lockForUpdate()->first()) {
                    $orderAlreadyExists = true;

                    return;
                }

                $this->serviceOrderRepository->save($serviceOrder);
                DB::table('service_orders')->where('id', $serviceOrder->id())->update([
                    'status_sequence' => $sequence,
                ]);

                foreach ($items as $item) {
                    $this->serviceOrderServiceRepository->save($item);
                }
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        if ($orderAlreadyExists) {
            return false;
        }

        $this->metrics->orderReceived($serviceOrder->status()->value);
        $this->journey->record('service_order_received', $serviceOrder->id(), [
            'status' => $serviceOrder->status()->value,
            'services_count' => count($items),
            'event_id' => $eventId,
        ]);

        return true;
    }

    private function buildService(string $serviceOrderId, mixed $service): ServiceOrderService
    {
        if (! is_array($service)) {
            throw new InvalidArgumentException('Invalid service entry.');
        }

        return ServiceOrderService::create(
            $serviceOrderId,
            $this->requireString($service, 'service_id'),
            (int) ($service['quantity'] ?? 0),
            (float) ($service['price'] ?? 0),
            id: $this->requireString($service, 'id'),
            name: $this->requireString($service, 'name'),
        );
    }

    private function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("The {$key} is required.");
        }

        return $value;
    }
}
