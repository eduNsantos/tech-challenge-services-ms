<?php

namespace App\Application\ServiceOrder;

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
    ) {
    }

    /**
     * @return bool false when the event was already processed.
     *
     * @throws InvalidArgumentException when the payload is invalid.
     */
    public function handle(array $payload): bool
    {
        $eventId = $this->requireString($payload, 'event_id');

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
            DB::transaction(function () use ($eventId, $serviceOrder, $items) {
                // A chave primária de processed_messages garante a idempotência.
                DB::table('processed_messages')->insert([
                    'event_id' => $eventId,
                    'type' => self::TYPE,
                ]);

                $this->serviceOrderRepository->save($serviceOrder);

                foreach ($items as $item) {
                    $this->serviceOrderServiceRepository->save($item);
                }
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

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
