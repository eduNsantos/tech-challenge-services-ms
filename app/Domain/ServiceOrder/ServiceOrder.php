<?php

namespace App\Domain\ServiceOrder;

use DateTimeImmutable;

final class ServiceOrder
{
    public function __construct(
        private readonly string $customerId,
        private readonly string $vehicleId,
        private readonly string $status,
        private readonly float $servicesTotal,
        private readonly float $partsTotal,
        private readonly float $totalBudget,
        private readonly DateTimeImmutable $creationDate,
    ) {
    }

    public static function create(
        string $customerId,
        string $vehicleId,
        float $servicesTotal,
        float $partsTotal,
        ?string $status = 'OPEN',
        ?DateTimeImmutable $creationDate = null,
    ): self {
        $normalizedStatus = strtoupper((string) $status);

        if (trim($customerId) === '') {
            throw new \InvalidArgumentException('customer_id is required.');
        }

        if (trim($vehicleId) === '') {
            throw new \InvalidArgumentException('vehicle_id is required.');
        }

        if (! in_array($normalizedStatus, ['OPEN', 'IN_PROGRESS', 'WAITING', 'COMPLETED', 'CANCELED'], true)) {
            throw new \InvalidArgumentException('Invalid service order status.');
        }

        $totalBudget = $servicesTotal + $partsTotal;

        return new self(
            $customerId,
            $vehicleId,
            $normalizedStatus,
            $servicesTotal,
            $partsTotal,
            $totalBudget,
            $creationDate ?? new DateTimeImmutable(),
        );
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function servicesTotal(): float
    {
        return $this->servicesTotal;
    }

    public function partsTotal(): float
    {
        return $this->partsTotal;
    }

    public function totalBudget(): float
    {
        return $this->totalBudget;
    }

    public function creationDate(): DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function toArray(): array
    {
        return [
            'customer_id' => $this->customerId,
            'vehicle_id' => $this->vehicleId,
            'status' => $this->status,
            'services_total' => $this->servicesTotal,
            'parts_total' => $this->partsTotal,
            'total_budget' => $this->totalBudget,
            'creation_date' => $this->creationDate->format('Y-m-d H:i:s'),
        ];
    }
}
