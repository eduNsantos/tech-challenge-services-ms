<?php

namespace App\Domain\ServiceOrder;

use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ServiceOrder
{
    public function __construct(
        private readonly string $id,
        private readonly string $customerId,
        private readonly string $vehicleId,
        private ServiceOrderStatus $status,
        private readonly float $servicesTotal,
        private readonly DateTimeImmutable $creationDate,
    ) {
    }

    public static function create(
        string $customerId,
        string $vehicleId,
        float $servicesTotal,
        ?string $status = null,
        ?DateTimeImmutable $creationDate = null,
        ?string $id = null,
    ): self {
        if (trim($customerId) === '') {
            throw new InvalidArgumentException('customer_id is required.');
        }

        if (trim($vehicleId) === '') {
            throw new InvalidArgumentException('vehicle_id is required.');
        }

        if ($servicesTotal < 0) {
            throw new InvalidArgumentException('The services_total cannot be negative.');
        }

        return new self(
            $id !== null && trim($id) !== '' ? trim($id) : Str::uuid()->toString(),
            trim($customerId),
            trim($vehicleId),
            $status === null || $status === '' ? ServiceOrderStatus::Received : ServiceOrderStatus::fromInput($status),
            $servicesTotal,
            $creationDate ?? new DateTimeImmutable(),
        );
    }

    public function transitionTo(ServiceOrderStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new DomainException("Cannot change status from {$this->status->value} to {$next->value}.");
        }

        $this->status = $next;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function status(): ServiceOrderStatus
    {
        return $this->status;
    }

    public function servicesTotal(): float
    {
        return $this->servicesTotal;
    }

    public function creationDate(): DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customerId,
            'vehicle_id' => $this->vehicleId,
            'status' => $this->status->value,
            'services_total' => $this->servicesTotal,
            'creation_date' => $this->creationDate->format('Y-m-d H:i:s'),
        ];
    }
}
